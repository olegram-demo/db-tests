<?php

declare(strict_types=1);

namespace Tests\IsolationLevels;

use App\ApplicationFactory;
use App\Enums\Platform;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use parallel\Future;
use parallel\Channel;

final class NonRepeatableReadsTest extends IsolationLevelsTestCase
{
    private UuidInterface $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = Uuid::uuid4();
    }

    /**
     * @dataProvider dataProvider
     *
     * @throws \Throwable
     * @throws \Doctrine\DBAL\Exception
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function testNonRepeatableReads(Platform $platform, int $isolationLevel, bool $isOccurs): void
    {
        $db = $this->app->getDb($platform);
        $this->createSchema($db);
        $this->seed($db);

        $thread1 = $this->createThread();
        $thread2 = $this->createThread();

        $channel = new Channel();

        $future = $thread1->run(static function (Channel $channel, string $userId) use ($platform, $isolationLevel): bool {
            $db = ApplicationFactory::create()->getDb($platform);
            $db->setTransactionIsolation($isolationLevel);
            return $db->transactional(static function (Connection $db) use ($userId, $channel): bool {
                $balance1 = (int) $db->fetchOne('SELECT balance FROM users WHERE id = :id', ['id' => $userId]);
                $channel->send($balance1);
                $balance2 = $channel->recv();
                $balance3 = (int) $db->fetchOne('SELECT balance FROM users WHERE id = :id', ['id' => $userId]);
                if (!\in_array($balance3, [$balance1, $balance2], true)) {
                    throw new \RuntimeException('Something went wrong.');
                }
                return $balance3 !== $balance1;
            });
        }, [$channel, $this->userId->toString()]);

        $thread2->run(static function (Channel $channel, string $userId, int $increment) use ($platform): void {
            $db = ApplicationFactory::create()->getDb($platform);
            $newBalance = $channel->recv() + $increment;
            $db->transactional(static function (Connection $db) use ($userId, $newBalance): void {
                $db->update('users', ['balance' => $newBalance], ['id' => $userId]);
            });
            $channel->send($newBalance);
        }, [$channel, $this->userId->toString(), $this->faker->numberBetween(10, 80)]);

        $this->assertEquals($isOccurs, $future?->value());
    }

    public function dataProvider(): array
    {
        return [
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_COMMITTED, true],
            [Platform::POSTGRES(), TransactionIsolationLevel::REPEATABLE_READ, false],
            [Platform::MYSQL(), TransactionIsolationLevel::READ_COMMITTED, true],
            [Platform::MYSQL(), TransactionIsolationLevel::REPEATABLE_READ, false],
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function seed(Connection $db): void
    {
        $db->insert('users', [
            'id' => $this->userId,
            'name' => $this->faker->name,
            'balance' => $this->faker->numberBetween(100, 800),
        ]);
    }
}
