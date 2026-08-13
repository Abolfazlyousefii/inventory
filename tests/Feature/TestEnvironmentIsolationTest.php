<?php

namespace Tests\Feature;

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_test_environment_uses_in_memory_sqlite_and_never_inventory_database(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('cache.default'));

        $permissionCacheStore = config('permission.cache.store');

        $this->assertContains($permissionCacheStore, ['array', 'default']);
        $this->assertSame(
            'array',
            $permissionCacheStore === 'default' ? config('cache.default') : $permissionCacheStore
        );

        $sessionHandler = app('session')->driver()->getHandler();

        $this->assertInstanceOf(ArraySessionHandler::class, $sessionHandler);
        $this->assertNotInstanceOf(DatabaseSessionHandler::class, $sessionHandler);

        $databaseName = DB::connection()->getDatabaseName();

        $this->assertNotSame(
            'inventory',
            $databaseName,
            'Tests must never run against the inventory database.'
        );
    }
}
