<?php

namespace Tests\Unit\Permissions;

use App\Http\Middleware\EnsureYuanDashboardAccess;
use App\Support\PageAccessCatalog;
use App\Support\YuanDashboardAccess;
use Illuminate\Http\Request;
use Tests\TestCase;

class YuanDashboardUnlockTest extends TestCase
{
    public function test_all_yuan_data_and_write_routes_require_the_unlock(): void
    {
        foreach (['index', 'data', 'history', 'rate', 'preview', 'apply'] as $name) {
            $route = app('router')->getRoutes()->getByName('products.yuan.'.$name);
            $this->assertNotNull($route);
            $this->assertContains(EnsureYuanDashboardAccess::class, $route->gatherMiddleware());
        }

        foreach (['unlock', 'unlock.submit'] as $name) {
            $route = app('router')->getRoutes()->getByName('products.yuan.'.$name);
            $this->assertNotNull($route);
            $this->assertNotContains(EnsureYuanDashboardAccess::class, $route->gatherMiddleware());
            $this->assertSame(['page.products.price_changes'], PageAccessCatalog::permissionsForRoute('products.yuan.'.$name));
        }
    }

    public function test_unlock_proof_is_bound_to_user_time_and_current_password(): void
    {
        config(['yuan_dashboard.access_key' => 'example-long-secret-for-tests', 'yuan_dashboard.cookie_hours' => 12]);
        $access = app(YuanDashboardAccess::class);
        $request = Request::create('/yuan');
        $request->setUserResolver(fn () => new class {
            public function getAuthIdentifier(): int { return 101; }
        });
        $cookie = $access->issueCookie($request);
        $this->assertSame('/yuan', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $request->cookies->set(YuanDashboardAccess::COOKIE_NAME, $cookie->getValue());
        $this->assertTrue($access->isUnlocked($request));

        $other = clone $request;
        $other->setUserResolver(fn () => new class {
            public function getAuthIdentifier(): int { return 102; }
        });
        $this->assertFalse($access->isUnlocked($other));

        config(['yuan_dashboard.access_key' => 'a-completely-different-secret']);
        $this->assertFalse($access->isUnlocked($request));

        config(['yuan_dashboard.access_key' => 'example-long-secret-for-tests']);
        $this->travel(12)->hours();
        $this->assertFalse($access->isUnlocked($request));
    }

    public function test_locked_requests_cannot_read_api_data_or_use_price_actions(): void
    {
        config(['yuan_dashboard.access_key' => 'example-long-secret-for-tests']);
        $guard = app(EnsureYuanDashboardAccess::class);
        foreach (['/yuan/data', '/yuan/apply'] as $path) {
            $request = Request::create($path, $path === '/yuan/apply' ? 'POST' : 'GET', server: [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $guard->handle($request, fn () => response()->json(['secret' => true]));
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('yuan_locked', $response->getData(true)['code']);
        }
    }

    public function test_missing_secret_fails_closed_and_password_is_verified(): void
    {
        config(['yuan_dashboard.access_key' => '']);
        $access = app(YuanDashboardAccess::class);
        $this->assertFalse($access->validPassword('anything'));
        config(['yuan_dashboard.access_key' => 'example-long-secret-for-tests']);
        $this->assertTrue($access->validPassword('example-long-secret-for-tests'));
        $this->assertFalse($access->validPassword('another-password'));
    }
}
