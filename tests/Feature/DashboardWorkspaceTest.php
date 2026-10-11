<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardWorkspaceService;
use App\Support\PageAccessCatalog;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DashboardWorkspaceTest extends TestCase
{
    private function userWithPages(array $permissions): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isSuperAdmin')->andReturn(false);
        $user->shouldReceive('getPermissionsViaRoles')->andReturn(collect(array_map(fn($key) => (object)['key'=>$key], $permissions)));
        return $user;
    }

    public function test_user_without_page_access_sees_no_shortcuts_or_attention_queries(): void
    {
        $user=$this->userWithPages([]);$service=new DashboardWorkspaceService;
        DB::enableQueryLog();
        $this->assertSame([], $service->cards($user));
        $this->assertSame([], $service->attention($user));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_seller_shortcuts_never_grant_finance_or_warehouse_access(): void
    {
        $user=$this->userWithPages(['page.sales.preinvoices','page.products']);
        $cards=(new DashboardWorkspaceService)->cards($user);$routes=array_column($cards,'route');
        $this->assertContains('preinvoice.create',$routes);
        $this->assertContains('preinvoice.my.index',$routes);
        $this->assertContains('products.index',$routes);
        $this->assertNotContains('warehouse.shipping.index',$routes);
        $this->assertNotContains('account-statements.index',$routes);
        foreach($cards as $card)$this->assertTrue(PageAccessCatalog::userCanRoute($user,$card['route']));
    }

    public function test_warehouse_workspace_includes_authorized_secondary_tools_without_financial_data(): void
    {
        $user=$this->userWithPages(['page.warehouse.shipping','page.warehouse.daily_sales','page.warehouse.thresholds','page.warehouse.reservations']);
        $cards=(new DashboardWorkspaceService)->cards($user);$routes=array_column($cards,'route');
        foreach(['warehouse.shipping.index','warehouse.daily-sales.index','stock-thresholds.index','warehouse-reservations.index'] as $route)$this->assertContains($route,$routes);
        $this->assertNotContains('finance.reports.index',$routes);
        foreach($cards as $card)$this->assertTrue($card['primary']);
    }

    public function test_permissions_are_rechecked_when_access_is_removed(): void
    {
        $service=new DashboardWorkspaceService;
        $this->assertContains('account-statements.index',array_column($service->cards($this->userWithPages(['page.finance.accounts'])),'route'));
        $this->assertNotContains('account-statements.index',array_column($service->cards($this->userWithPages([])),'route'));
    }

    public function test_admin_keeps_additional_tools_without_cluttering_primary_grid(): void
    {
        $user=Mockery::mock(User::class)->makePartial();$user->shouldReceive('isSuperAdmin')->andReturn(true);
        $cards=(new DashboardWorkspaceService)->cards($user);
        $this->assertCount(9,array_filter($cards,fn($card)=>$card['primary']));
        $this->assertGreaterThan(9,count($cards));
        $this->assertCount(count($cards),array_unique(array_column($cards,'route')));
    }
}
