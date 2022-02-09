<?php

declare(strict_types=1);

namespace Tests\IsolationLevels;

use App\ApplicationFactory;
use App\Enums\Platform;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use ezcSystemInfo;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use parallel\Future;
use parallel\Channel;
use parallel\Events;

final class LostUpdateTest extends IsolationLevelsTestCase
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
    public function testLostUpdate(Platform $platform, int $isolationLevel, bool $isOccurs): void
    {
        $cpuCount = ezcSystemInfo::getInstance()->cpuCount;
        $updatesPerThread = 100;

        $db = $this->app->getDb($platform);
        $this->createSchema($db);
        $this->seed($db);

        $threads = [];
        for ($i = 0; $i < $cpuCount; $i++) {
            $threads[] = $this->createThread();
        }

        $futures = [];
        foreach ($threads as $thread) {
            $futures[] = $thread->run(static function (string $userId, int $count) use ($platform, $isolationLevel): int {
                $total = 0;
                $db = ApplicationFactory::create()->getDb($platform);
                $db->setTransactionIsolation($isolationLevel);
                for ($i = 0; $i < $count; $i++) {
                    $total += $db->transactional(static function (Connection $db) use ($userId): int {
                        $value = \random_int(1, 10);
                        $db->executeStatement(
                            'UPDATE users SET balance = balance + :value WHERE id = :id',
                            ['value' => $value, 'id' => $userId]
                        );
                        return $value;
                    });
                }
                return $total;
            }, [$this->userId->toString(), $updatesPerThread]);
        }

        $expectedBalance = \array_reduce(
            $futures,
            static fn (int $total, ?Future $item): int => $total += (int) $item?->value(),
            0,
        );

        $actualBalance = (int) $db->fetchOne('SELECT balance FROM users WHERE id = ?', [$this->userId]);

        $this->assertEquals($expectedBalance, $actualBalance);
    }

    public function dataProvider(): array
    {
        return [
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_UNCOMMITTED, false],
            [Platform::MYSQL(), TransactionIsolationLevel::READ_UNCOMMITTED, false],
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
