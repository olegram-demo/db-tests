<?php

declare(strict_types=1);

namespace App;

use App\Enums\Platform;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Types\Type;
use Dotenv\Dotenv;
use Faker\Factory;
use Faker\Generator;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Ramsey\Uuid\Doctrine\UuidType;

class ApplicationFactory
{
    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public static function create(): Application
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();

        if (!Type::hasType(UuidType::NAME)) {
            Type::addType(UuidType::NAME, UuidType::class);
        }

        $app = Application::getInstance();

        $app->singleton(Generator::class, static function (): Generator {
            $faker = Factory::create();
            $faker->seed(1000);
            return $faker;
        });

        $dotenv->required(['POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD']);
        $app->singleton(Platform::POSTGRES()->getValue(), static function (): Connection {
            $logger = new Logger(Platform::POSTGRES()->getValue());
            $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/postgres.log'));
            $connectionParams = [
                'dbname' => (string) $_ENV['POSTGRES_DB'],
                'user' => (string) $_ENV['POSTGRES_USER'],
                'password' => (string) $_ENV['POSTGRES_PASSWORD'],
                'host' => 'postgres',
                'driver' => 'pdo_pgsql',
            ];
            $config = new Configuration();
            $config->setMiddlewares([new Middleware($logger)]);
            return DriverManager::getConnection($connectionParams, $config);
        });

        $dotenv->required(['MYSQL_DB', 'MYSQL_ROOT_PASSWORD']);
        $app->singleton(Platform::MYSQL()->getValue(), static function (): Connection {
            $logger = new Logger(Platform::MYSQL()->getValue());
            $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/mysql.log'));
            $connectionParams = [
                'dbname' => (string) $_ENV['MYSQL_DB'],
                'user' => 'root',
                'password' => (string) $_ENV['MYSQL_ROOT_PASSWORD'],
                'host' => 'mysql',
                'driver' => 'pdo_mysql',
            ];
            $config = new Configuration();
            $config->setMiddlewares([new Middleware($logger)]);
            return DriverManager::getConnection($connectionParams, $config);
        });

        return $app;
    }
}
