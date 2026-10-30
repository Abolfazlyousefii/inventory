<?php
namespace Tests\Feature;
use App\Models\Invoice;
use App\Models\Site\Order;
use App\Services\ExternalPaidOrderService;
use App\Services\Sync\SitePaidOrdersSyncService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class DirectPaidOrderReceiptTest extends TestCase
{
 protected function setUp():void {
  parent::setUp();
  DB::statement("ATTACH DATABASE ':memory:' AS site_receipt_test");
  config(['database.connections.site.database'=>'site_receipt_test']);
  DB::statement('CREATE TABLE site_receipt_test.orders (id INTEGER PRIMARY KEY,status TEXT,inventory_stock_hold_id INTEGER,inventory_document_type TEXT,inventory_document_id INTEGER,inventory_document_uuid TEXT,inventory_sync_status TEXT,inventory_synced_at TEXT,inventory_last_error TEXT)');
  DB::table('site_receipt_test.orders')->insert(['id'=>42,'status'=>'paid','inventory_stock_hold_id'=>32]);
  DB::statement('CREATE TABLE import_markers (id INTEGER PRIMARY KEY)');
  $invoice=(new Invoice)->forceFill(['id'=>5,'uuid'=>'12345']);
  $this->mock(ExternalPaidOrderService::class)->shouldReceive('import')->andReturnUsing(function()use($invoice){DB::table('import_markers')->insert(['id'=>1]);return ['invoice'=>$invoice,'created'=>true];});
 }
 private function importReceipt():array {
  $service=app(SitePaidOrdersSyncService::class);
  return (new \ReflectionMethod($service,'importAndRecord'))->invoke($service,(new Order)->forceFill(['id'=>42,'inventory_stock_hold_id'=>32]),['crm_order_id'=>42]);
 }
 public function test_site_records_invoice_link_and_success_with_the_import():void {
  $this->importReceipt();
  $row=DB::table('site_receipt_test.orders')->where('id',42)->first();
  $this->assertSame('synced',$row->inventory_sync_status);
  $this->assertSame('invoice',$row->inventory_document_type);
  $this->assertSame(5,$row->inventory_document_id);
  $this->assertSame('12345',$row->inventory_document_uuid);
  $this->assertNotNull($row->inventory_synced_at);
 }
 public function test_site_write_failure_rolls_back_the_invoice_transaction():void {
  DB::statement('ALTER TABLE site_receipt_test.orders DROP COLUMN inventory_document_uuid');
  try{$this->importReceipt();$this->fail('Missing Site receipt column must reject import');}catch(\Illuminate\Database\QueryException $e){}
  $this->assertSame(0,DB::table('import_markers')->count());
  $this->assertNull(DB::table('site_receipt_test.orders')->value('inventory_sync_status'));
 }
 public function test_no_import_is_attempted_if_paid_order_changed_before_lock():void {
  DB::table('site_receipt_test.orders')->where('id',42)->update(['status'=>'canceled']);
  try{$this->importReceipt();$this->fail('Stale paid order must reject import');}catch(\RuntimeException $e){}
  $this->assertSame(0,DB::table('import_markers')->count());
 }
}
