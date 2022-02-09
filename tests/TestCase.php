<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\ApplicationFactory;
use Faker\Generator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use parallel\Runtime;

class TestCase extends BaseTestCase
{
    protected Application $app;
    protected Generator $faker;

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function setUp(): void
    {
        if (!isset($this->app)) {
            $this->app = ApplicationFactory::create();
            $this->faker = $this->app->make(Generator::class);
        }
    }

    protected function createThread(): Runtime
    {
        return new Runtime(__DIR__ . '/../vendor/autoload.php');
    }
}
