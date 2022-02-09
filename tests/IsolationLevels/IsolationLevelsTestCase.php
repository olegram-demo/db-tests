<?php

declare(strict_types=1);

namespace Tests\IsolationLevels;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ramsey\Uuid\Doctrine\UuidType;
use Tests\TestCase;

class IsolationLevelsTestCase extends TestCase
{
    /**
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Doctrine\DBAL\Exception
     */
    protected function createSchema(Connection $db): void
    {
        $schema = $db->createSchemaManager();

        if ($schema->tablesExist(['users'])) {
            $schema->dropTable('users');
        }

        $users = new Table('users');
        $users->addColumn('id', UuidType::NAME);
        $users->addColumn('name', Types::STRING);
        $users->addColumn('balance', Types::INTEGER);
        $users->setPrimaryKey(['id']);

        $schema->createTable($users);
    }
}
