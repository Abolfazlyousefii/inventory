<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoLoginTest extends TestCase
{
    public function test_auto_login_returns_service_unavailable_when_crm_connection_times_out(): void
    {
        config()->set('services.crm.client_token_url', 'https://crm.ariyajanebi.ir/api/token-for-client');
        config()->set('services.crm.client_secret', 'test-secret');
        config()->set('services.crm.connect_timeout', 1);
        config()->set('services.crm.timeout', 1);
        config()->set('services.crm.verify_ssl', true);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Timeout was reached');
        });

        $response = $this->get('/auto-login?phone=09123456789');

        $response->assertStatus(503);
    }
}
