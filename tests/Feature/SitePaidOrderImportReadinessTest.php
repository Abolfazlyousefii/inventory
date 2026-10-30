<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Sync\SitePaidOrdersSyncService;
use App\Services\Sync\LegacyApiSitePaidOrdersSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class SitePaidOrderImportReadinessTest extends TestCase
{
 use RefreshDatabase;
 public function test_paid_import_cursor_is_initialized_and_seller_is_disabled_for_login(): void
 {
  $this->assertTrue(Schema::hasTable('site_paid_order_import_cursor'));
  $this->assertSame(0,(int)DB::table('site_paid_order_import_cursor')->where('id',1)->value('last_order_id'));
  $seller=User::where('username','ariya_site')->sole();
  $this->assertTrue($seller->is_seller);
  $this->assertFalse($seller->is_active);
  $this->assertFalse($seller->can_access_erp);
  $this->assertSame(0,$seller->roles()->count());
 }
 public function test_paid_only_opt_in_selects_direct_import_without_enabling_stock_reconciliation(): void
 {
  config(['services.request_driven_site_stock'=>false,'services.direct_site_paid_orders'=>true]);
  $this->mock(LegacyApiSitePaidOrdersSyncService::class)->shouldNotReceive('syncAll');
  $result=app(SitePaidOrdersSyncService::class)->syncAll();
  $this->assertStringContainsString('requires MySQL',$result['errors'][0]['message']);
  $this->assertFalse(config('services.request_driven_site_stock'));
 }
 public function test_legacy_mode_is_preserved_without_either_explicit_opt_in(): void
 {
  config(['services.request_driven_site_stock'=>false,'services.direct_site_paid_orders'=>false]);
  $this->mock(LegacyApiSitePaidOrdersSyncService::class)->shouldReceive('syncAll')->once()->andReturn(['received'=>0]);
  $this->assertSame(['received'=>0],app(SitePaidOrdersSyncService::class)->syncAll());
 }
}
