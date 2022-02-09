<?php

declare(strict_types=1);

namespace Tests\IsolationLevels;

use App\ApplicationFactory;
use App\Enums\Platform;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Faker\Generator;
use Ramsey\Uuid\Uuid;
use parallel\Future;
use parallel\Channel;

final class PhantomReadsTest extends IsolationLevelsTestCase
{
    /**
     * @dataProvider dataProvider
     *
     * @throws \Throwable
     * @throws \Doctrine\DBAL\Exception
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function testPhantomReads(Platform $platform, int $isolationLevel, bool $isOccurs): void
    {
        $db = $this->app->getDb($platform);
        $this->createSchema($db);
        self::insertUsers($db, $this->faker, 10);

        $thread1 = $this->createThread();
        $thread2 = $this->createThread();

        $channel = new Channel();

        $future = $thread1->run(static function (Channel $channel) use ($platform, $isolationLevel): bool {
            $db = ApplicationFactory::create()->getDb($platform);
            $db->setTransactionIsolation($isolationLevel);
            return $db->transactional(static function (Connection $db) use ($channel): bool {
                $count1 = (int) $db->fetchOne('SELECT COUNT(*) FROM users');
                $channel->send($count1);
                $addedRows = $channel->recv();
                $count2 = (int) $db->fetchOne('SELECT COUNT(*) FROM users');
                if (!\in_array($count2, [$count1, $count1 + $addedRows], true)) {
                    throw new \RuntimeException('Something went wrong.');
                }
                return $count2 !== $count1;
            });
        }, [$channel]);

        $thread2->run(static function (Channel $channel) use ($platform): void {
            $rowsQuantity = 2;
            $app = ApplicationFactory::create();
            $db = $app->getDb($platform);
            /** @var Generator $faker */
            $faker = $app->make(Generator::class);
            $channel->recv();
            $db->transactional(static function (Connection $db) use ($faker, $rowsQuantity): void {
                self::insertUsers($db, $faker, $rowsQuantity);
            });
            $channel->send($rowsQuantity);
        }, [$channel]);

        $this->assertEquals($isOccurs, $future?->value());
    }

    /**
     * @throws \Throwable
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Doctrine\DBAL\Exception
     */
    public function testMySqlLockOnSerializableLevel(): void
    {
        $db = $this->app->getDb(Platform::MYSQL());
        $this->createSchema($db);
        self::insertUsers($db, $this->faker, 10);

        $thread1 = $this->createThread();
        $thread2 = $this->createThread();

        $channel = new Channel();

        $thread1->run(static function (Channel $channel): void {
            $db = ApplicationFactory::create()->getDb(Platform::MYSQL());
            $db->setTransactionIsolation(TransactionIsolationLevel::SERIALIZABLE);
            $db->transactional(static function (Connection $db) use ($channel): void {
                $db->fetchOne('SELECT COUNT(*) FROM users');
                $channel->send(null);
                $channel->recv();
            });
        }, [$channel]);

        $future = $thread2->run(static function (Channel $channel): int {
            $app = ApplicationFactory::create();
            $db = $app->getDb(Platform::MYSQL());
            $db->executeStatement('SET innodb_lock_wait_timeout = 2');
            /** @var Generator $faker */
            $faker = $app->make(Generator::class);
            $channel->recv();
            $result = 0;
            $db->beginTransaction();
            try {
                self::insertUsers($db, $faker);
            } catch (LockWaitTimeoutException $e) {
                $result = $e->getCode();
            }
            $db->rollBack();
            $channel->send(null);
            return $result;
        }, [$channel]);

        $this->assertEquals(1205, $future?->value());
    }

    public function dataProvider(): array
    {
        return [
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_COMMITTED, true],
            [Platform::POSTGRES(), TransactionIsolationLevel::REPEATABLE_READ, false],
            [Platform::POSTGRES(), TransactionIsolationLevel::SERIALIZABLE, false],
            [Platform::MYSQL(), TransactionIsolationLevel::READ_COMMITTED, true],
            [Platform::MYSQL(), TransactionIsolationLevel::REPEATABLE_READ, false],
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private static function insertUsers(Connection $db, Generator $faker, int $quantity = 1): void
    {
        for ($i = 0; $i < $quantity; $i++) {
            $db->insert('users', [
                'id' => Uuid::uuid4(),
                'name' => $faker->name,
                'balance' => $faker->numberBetween(100, 800),
            ]);
        }
    }
}
