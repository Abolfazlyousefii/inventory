<?php

namespace Tests\Feature;

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

        $databaseName = DB::connection()->getDatabaseName();

        $this->assertNotSame(
            'inventory',
            $databaseName,
            'Tests must never run against the inventory database.'
        );
    }
}
