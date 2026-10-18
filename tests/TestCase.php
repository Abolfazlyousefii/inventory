<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const DATABASE_SAFETY_MESSAGE = 'Tests must never run against the inventory database.';

    public function createApplication()
    {
        $this->forceTestingEnvironmentBeforeBootstrap();

        $app = parent::createApplication();

        $this->guardTestingDatabase();
        // Tests must explicitly fake integrations; never contact the live shop.
        Http::preventStrayRequests();
        // Historical schema was removed from production migrations in e2cbf9f6.
        // Load the recovered baseline only behind the in-memory database guard.
        $app->make('migrator')->path(base_path('tests/Support/LegacyMigrations'));

        return $app;
    }

    private function forceTestingEnvironmentBeforeBootstrap(): void
    {
        $variables = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
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
