<?php

namespace Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppShellLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function test_layout_loads_theme_assets_in_the_required_order_without_inline_styles(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $assets = [
            "lib/bootstrap.rtl.min.css",
            "lib/select2.min.css",
            "lib/jalalidatepicker.min.css",
            "css/Vazirmatn.css",
            "css/admin-theme.css",
            "css/admin.css",
            "css/app-shell.css",
            "css/sidebar.css",
            "@stack('styles')",
        ];

        $lastPosition = -1;
        foreach ($assets as $asset) {
            $position = strpos($layout, $asset);
            $this->assertNotFalse($position, "Missing layout asset: {$asset}");
            $this->assertGreaterThan($lastPosition, $position, "Incorrect asset order near: {$asset}");
            $lastPosition = $position;
        }

        $this->assertStringNotContainsString('<style', $layout);
        $this->assertStringContainsString("asset('js/app-shell.js')", $layout);
        $this->assertStringContainsString('class="app-page-title"', $layout);
    }

    public function test_design_tokens_and_shared_components_use_the_corporate_palette(): void
    {
        $tokens = file_get_contents(public_path('css/admin-theme.css'));
        $admin = file_get_contents(public_path('css/admin.css'));

        foreach ([
            '--app-primary: #16354f',
            '--app-accent: #2879a8',
            '--app-bg: #f4f7f9',
            '--app-sidebar-width: 264px',
            '--app-sidebar-collapsed-width: 76px',
            '--app-topbar-height: 62px',
        ] as $token) {
            $this->assertStringContainsString($token, $tokens);
        }

        $this->assertStringNotContainsString('#4f46e5', $admin);
        $this->assertStringNotContainsString('radial-gradient', $admin);
        $this->assertStringContainsString('.select2-container--default', $admin);
        $this->assertStringContainsString('jdp-container', $admin);
        $this->assertStringContainsString('.modal-content', $admin);
    }

    public function test_sidebar_keeps_permissions_and_active_routes_while_moving_interaction_to_shell_script(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/sidebar.blade.php'));
        $script = file_get_contents(public_path('js/app-shell.js'));

        $this->assertStringNotContainsString('<style', $sidebar);
        $this->assertStringNotContainsString('<script', $sidebar);
        $this->assertStringContainsString("@canPermission('dashboard.view')", $sidebar);
        $this->assertStringContainsString("@canAnyPermission(['products.view'", $sidebar);
        $this->assertStringContainsString("@canAnyPermission(['issues.view'", $sidebar);
        $this->assertStringContainsString("@canAnyPermission(['preinvoices.create'", $sidebar);
        $this->assertStringContainsString("@canAnyPermission(['preinvoices.finance.view'", $sidebar);
        $this->assertStringContainsString("@canAnyPermission(['categories.view'", $sidebar);
        $this->assertStringContainsString("'admin.product-exports.*'", $sidebar);
        $this->assertStringContainsString("'vouchers.sales.queue'", $sidebar);

        $this->assertStringContainsString("const collapsedStorageKey = 'aria_sidebar_collapsed'", $script);
        $this->assertStringContainsString("event.key === 'Escape'", $script);
        $this->assertStringContainsString("event.key !== 'Tab'", $script);
        $this->assertStringContainsString("document.body.classList.add('sidebar-open')", $script);
        $this->assertStringContainsString("document.body.classList.toggle('sidebar-collapsed'", $script);
    }

    public function test_sidebar_visibility_and_active_accordion_remain_role_aware(): void
    {
        $admin = $this->renderSidebar(['admin'], [], 'dashboard', '/dashboard');
        $this->assertStringContainsString('کالاهای آریا', $admin);
        $this->assertStringContainsString('انبارداری شرکت آریا', $admin);
        $this->assertStringContainsString('بازرگانی و فروش', $admin);
        $this->assertStringContainsString('مالی', $admin);
        $this->assertStringContainsString('پیکربندی', $admin);

        $seller = $this->renderSidebar(
            ['seller'],
            ['dashboard.view', 'preinvoices.create', 'preinvoices.own.view', 'customers.view'],
            'preinvoice.create',
            '/preinvoice/create'
        );
        $this->assertStringContainsString('بازرگانی و فروش', $seller);
        $this->assertStringContainsString('data-accordion-section="sales"', $seller);
        $this->assertStringContainsString('sidebar-accordion-item is-open', $seller);
        $this->assertStringNotContainsString('انبارداری شرکت آریا', $seller);
        $this->assertStringNotContainsString('مدیریت دسترسی کاربران', $seller);

        $finance = $this->renderSidebar(
            ['finance'],
            ['dashboard.view', 'preinvoices.finance.view', 'account_statements.view', 'invoices.view', 'cheques.view', 'finance.reports.view'],
            'invoices.index',
            '/invoices'
        );
        $this->assertStringContainsString('data-accordion-section="finance"', $finance);
        $this->assertStringContainsString('در انتظار تایید مالی', $finance);
        $this->assertStringNotContainsString('ثبت پیش‌فاکتور', $finance);

        $warehouse = $this->renderSidebar(
            ['warehouse'],
            ['dashboard.view', 'issues.view', 'warehouse.collection.queue.view', 'warehouse.shipping.queue.view', 'inventory.count.view'],
            'vouchers.sales.queue',
            '/vouchers/sales/queue'
        );
        $this->assertStringContainsString('data-accordion-section="warehouse"', $warehouse);
        $this->assertStringContainsString('صف جمع‌آوری فاکتور', $warehouse);
        $this->assertStringNotContainsString('گزارش مالی', $warehouse);
    }

    private function renderSidebar(array $roles, array $permissions, string $routeName, string $path): string
    {
        $user = new class($roles, $permissions) extends GenericUser
        {
            public function __construct(
                private readonly array $roles,
                private readonly array $permissionKeys,
            ) {
                parent::__construct([
                    'id' => 1,
                    'name' => 'کاربر تست',
                    'email' => 'test@example.com',
                ]);
            }

            public function hasAnyRole(array|string $roles): bool
            {
                return array_intersect($this->roles, (array) $roles) !== [];
            }

            public function hasPermission(string $permission): bool
            {
                return $this->hasAnyRole(['admin', 'Admin', 'ادمین'])
                    || in_array($permission, $this->permissionKeys, true);
            }

            public function getRoleNames()
            {
                return collect($this->roles);
            }
        };

        Auth::setUser($user);
        $request = Request::create($path);
        $route = new Route(['GET'], ltrim($path, '/'), static fn () => null);
        $route->name($routeName);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);

        return view('layouts.sidebar')->render();
    }
}
