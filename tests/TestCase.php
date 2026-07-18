<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const DATABASE_SAFETY_MESSAGE = 'Tests must never run against the inventory database.';

    public function createApplication()
    {
        $this->forceTestingEnvironmentBeforeBootstrap();

        $app = parent::createApplication();

        $this->guardTestingDatabase();

        return $app;
    }

    private function forceTestingEnvironmentBeforeBootstrap(): void
    {
        $variables = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config-testing.php',
        ];

        foreach ($variables as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function guardTestingDatabase(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException(self::DATABASE_SAFETY_MESSAGE);
        }

        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException(self::DATABASE_SAFETY_MESSAGE);
        }

        if (config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException(self::DATABASE_SAFETY_MESSAGE);
        }

        if (strtolower((string) DB::connection()->getDatabaseName()) === 'inventory') {
            throw new RuntimeException(self::DATABASE_SAFETY_MESSAGE);
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException(self::DATABASE_SAFETY_MESSAGE);
        }
    }
}
