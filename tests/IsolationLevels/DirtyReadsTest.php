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

final class DirtyReadsTest extends IsolationLevelsTestCase
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
    public function testDirtyReads(Platform $platform, int $isolationLevel, bool $isOccurs): void
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
                $dirtyBalance = $channel->recv();
                $balance = (int) $db->fetchOne('SELECT balance FROM users WHERE id = :id', ['id' => $userId]);
                $channel->send($balance);
                return $balance === $dirtyBalance;
            });
        }, [$channel, $this->userId->toString()]);

        $thread2->run(static function (Channel $channel, string $userId, int $newBalance) use ($platform): void {
            $db = ApplicationFactory::create()->getDb($platform);
            $db->beginTransaction();
            $db->update('users', ['balance' => $newBalance], ['id' => $userId]);
            $channel->send($newBalance);
            $channel->recv();
            $db->rollBack();
        }, [$channel, $this->userId->toString(), $this->faker->numberBetween(100, 800)]);

        $this->assertEquals($isOccurs, $future?->value());
    }

    public function dataProvider(): array
    {
        return [
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_UNCOMMITTED, false],
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_COMMITTED, false],
            [Platform::MYSQL(), TransactionIsolationLevel::READ_UNCOMMITTED, true],
            [Platform::MYSQL(), TransactionIsolationLevel::READ_COMMITTED, false],
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
            'balance' => 0,
        ]);
    }
}
