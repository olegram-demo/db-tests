<?php

declare(strict_types=1);

namespace Tests\IsolationLevels;

use App\Enums\Platform;
use Doctrine\DBAL\TransactionIsolationLevel;

final class DefaultIsolationLevelTest extends IsolationLevelsTestCase
{
    /**
     * @dataProvider dataProvider
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Doctrine\DBAL\Exception
     */
    public function testDefaultIsolationLevel(Platform $platform, int $expectedIsolationLevel): void
    {
        $this->assertEquals($expectedIsolationLevel, $this->app->getDb($platform)->getTransactionIsolation());
    }

    public function dataProvider(): array
    {
        return [
            [Platform::POSTGRES(), TransactionIsolationLevel::READ_COMMITTED],
            [Platform::MYSQL(), TransactionIsolationLevel::REPEATABLE_READ],
        ];
    }
}
