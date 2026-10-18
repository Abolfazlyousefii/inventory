<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestEnvironmentSafetyTest extends TestCase
{
    public function test_database_is_ephemeral_and_external_requests_are_blocked(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->expectException(StrayRequestException::class);
        Http::get('https://example.invalid/must-never-leave-the-test-process');
    }

    public function test_explicitly_faked_integrations_still_work(): void
    {
        Http::fake(['https://example.invalid/*' => Http::response(['ok' => true])]);
        $this->assertTrue(Http::get('https://example.invalid/fake')->json('ok'));
        Http::assertSentCount(1);
    }
}
