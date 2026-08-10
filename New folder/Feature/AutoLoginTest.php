<?php

namespace Tests\Feature;

use Tests\TestCase;

class AutoLoginTest extends TestCase
{
    public function test_legacy_phone_based_auto_login_is_not_available(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri());

        $this->assertFalse($uris->contains('auto-login'));
    }
}
