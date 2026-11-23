<?php

namespace Tests\Unit\Permissions;

use App\Support\PageAccessCatalog;
use Tests\TestCase;

class YuanDashboardPageAccessTest extends TestCase
{
    public function test_every_yuan_dashboard_route_has_a_page_owner(): void
    {
        foreach (['index', 'data', 'history', 'rate', 'preview', 'apply'] as $action) {
            $this->assertSame(
                ['page.products.price_changes'],
                PageAccessCatalog::permissionsForRoute('products.yuan.'.$action),
                'Missing page owner for products.yuan.'.$action,
            );
        }
    }

    public function test_price_changing_endpoints_require_the_apply_permission(): void
    {
        foreach (['rate', 'preview', 'apply'] as $action) {
            $route = app('router')->getRoutes()->getByName('products.yuan.'.$action);
            $this->assertNotNull($route);
            $this->assertContains(
                'permission:products.price_changes.apply',
                $route->gatherMiddleware(),
                'Missing action authorization for products.yuan.'.$action,
            );
        }
    }
}
