<?php

namespace Tests\Feature;

use App\Services\ProductStockHistoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Event, Http, Schema};
use Tests\TestCase;

class ProductStockHistoryExplanationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Http::fake();
        $tables = [
            'products'=>['name','stock','reserved'],
            'product_variants'=>['product_id','variant_name','variant_code','stock','reserved'],
            'warehouses'=>['name','type'], 'users'=>['name'], 'categories'=>['name','parent_id'],
            'warehouse_stocks'=>['warehouse_id','product_id','product_variant_id','quantity'],
            'stock_movements'=>['product_id','product_variant_id','warehouse_id','user_id','type','reason','quantity','stock_before','stock_after','reference','reference_type','reference_id','transaction_type','note'],
            'preinvoice_orders'=>['uuid'],
            'preinvoice_draft_reservations'=>['product_id','variant_id','quantity','preinvoice_order_id','user_id','released_at','released_by','release_reason','release_note','converted_at'],
            'invoices'=>['uuid','status','cancelled_at','preinvoice_order_id','seller_id'],
            'invoice_items'=>['invoice_id','product_id','variant_id','quantity'],
            'purchases'=>['user_id'], 'purchase_items'=>['purchase_id','product_id','product_variant_id','quantity'],
            'warehouse_transfers'=>['reference','voucher_type','from_warehouse_id','to_warehouse_id','transferred_at','user_id'],
            'warehouse_transfer_items'=>['warehouse_transfer_id','product_id','product_variant_id','quantity','destination_warehouse_id'],
            'stock_count_documents'=>['status','document_number','finalized_at','finalized_by'],
            'stock_count_document_items'=>['document_id','product_id','product_variant_id','warehouse_id','system_available_at_start','new_available'],
            'warehouse_inbound_receipts'=>['receipt_number','source_type','source_id','status','requested_by','reviewed_by','reviewed_at','request_note','review_note'],
            'warehouse_inbound_receipt_items'=>['receipt_id','product_id','product_variant_id','expected_quantity','accepted_quantity','suggested_warehouse_id','received_warehouse_id','stock_movement_id','reason','note'],
            'invoice_collection_revisions'=>['invoice_id','changed_by','reason_type','reason_note'],
            'invoice_collection_revision_items'=>['invoice_collection_revision_id','product_id','product_variant_id','change_type','old_quantity','new_quantity','variant_name_snapshot','sku_snapshot'],
            'sales_return_documents'=>['document_number','status','created_by','applied_at'],
            'sales_return_document_items'=>['document_id','product_id','product_variant_id','return_quantity','item_condition','destination_warehouse_id'],
        ];
        foreach ($tables as $name=>$fields) {
            Schema::create($name, function (Blueprint $t) use ($fields) {
                $t->id(); foreach ($fields as $field) $t->string($field)->nullable(); $t->timestamps();
            });
        }
        DB::table('products')->insert(['id'=>1,'name'=>'گارد','stock'=>0,'reserved'=>0]);
        DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'مدل ی مشکی','variant_code'=>'030001','stock'=>0,'reserved'=>0]);
        DB::table('warehouses')->insert(['id'=>1,'name'=>'مرکزی','type'=>'central']);
        DB::table('warehouse_stocks')->insert(['id'=>1,'warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>0]);
        DB::table('users')->insert(['id'=>1,'name'=>'بازبین']);
        DB::table('invoices')->insert(['id'=>1,'uuid'=>'01433','status'=>'shipped','created_at'=>'2026-09-01 10:00:00']);
    }

    private function zeroReceipt(string $status = 'discrepancy'): void
    {
        DB::table('warehouse_inbound_receipts')->insert(['id'=>1,'receipt_number'=>'WI-000259','source_type'=>'invoice_adjustment','source_id'=>1,'status'=>$status,'requested_by'=>1,'reviewed_by'=>1,'reviewed_at'=>'2026-09-02 10:00:00','created_at'=>'2026-09-01 12:00:00']);
        DB::table('warehouse_inbound_receipt_items')->insert(['id'=>1,'receipt_id'=>1,'product_id'=>1,'product_variant_id'=>1,'expected_quantity'=>1,'accepted_quantity'=>0,'suggested_warehouse_id'=>1,'received_warehouse_id'=>1,'reason'=>'item_removed']);
    }

    public function test_zero_receipt_and_removed_invoice_item_remain_visible_without_stock_effect(): void
    {
        $this->zeroReceipt();
        DB::table('invoice_collection_revisions')->insert(['id'=>1,'invoice_id'=>1,'changed_by'=>1,'reason_type'=>'item_removed','created_at'=>'2026-09-01 12:00:00']);
        DB::table('invoice_collection_revision_items')->insert(['id'=>1,'invoice_collection_revision_id'=>1,'product_id'=>1,'product_variant_id'=>1,'change_type'=>'removed','old_quantity'=>1,'new_quantity'=>null]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $receipt=$h['rows']->firstWhere('id','inbound-item-1');
        $this->assertNotNull($receipt, 'A zero receipt must not disappear from history.');
        $this->assertSame(0, $receipt->available_delta);
        $this->assertSame(1, $receipt->expected_quantity);
        $this->assertSame(0, $receipt->accepted_quantity);
        $this->assertSame('discrepancy', $receipt->event_status);
        $this->assertStringContainsString('01433', $receipt->related_reference);
        $revision=$h['rows']->firstWhere('id','revision-item-1');
        $this->assertNotNull($revision);
        $this->assertSame(1,$revision->document_quantity_before);
        $this->assertSame(0,$revision->document_quantity_after);
        $this->assertSame(0,$revision->available_delta);
        $this->assertSame(0,$h['current']);
        $this->assertSame(0,$h['sold']);
    }

    public function test_positive_receipt_and_supporting_document_do_not_count_twice(): void
    {
        $this->zeroReceipt('received');
        DB::table('warehouse_inbound_receipt_items')->where('id',1)->update(['expected_quantity'=>2,'accepted_quantity'=>2,'stock_movement_id'=>1]);
        DB::table('stock_movements')->insert(['id'=>1,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'in','reason'=>'invoice_adjustment_return','quantity'=>2,'stock_before'=>0,'stock_after'=>2]);
        DB::table('warehouse_stocks')->where('id',1)->update(['quantity'=>2]);
        DB::table('product_variants')->where('id',1)->update(['stock'=>2]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $this->assertSame(2, $h['rows']->sum('available_delta'));
        $this->assertSame(0,$h['rows']->firstWhere('id','inbound-item-1')->available_delta);
        $this->assertSame(2,$h['current']);
        $this->assertTrue($h['projection_matches']);
    }

    public function test_pending_and_cancelled_receipts_never_claim_an_applied_stock_effect(): void
    {
        foreach (['pending','cancelled'] as $status) {
            DB::table('warehouse_inbound_receipt_items')->delete(); DB::table('warehouse_inbound_receipts')->delete();
            $this->zeroReceipt($status);
            DB::table('warehouse_inbound_receipt_items')->where('id',1)->update(['accepted_quantity'=>1]);
            $row=app(ProductStockHistoryService::class)->history(1,1)['rows']->firstWhere('id','inbound-item-1');
            $this->assertNotNull($row);
            $this->assertSame(0,$row->available_delta);
            $this->assertSame(0,$row->accepted_quantity);
            $this->assertSame($status,$row->event_status);
        }
    }

    public function test_legacy_snapshot_is_not_presented_as_verified_delta(): void
    {
        DB::table('stock_movements')->insert(['id'=>1,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'out','reason'=>'sale','quantity'=>3,'stock_before'=>10,'stock_after'=>10]);
        $r=app(ProductStockHistoryService::class)->history(1,1)['rows']->firstWhere('id',1);
        $this->assertSame('incomplete',$r->evidence_level);
        $this->assertNull($r->available_delta);
        $this->assertFalse($r->snapshot_reliable);
    }

    public function test_snapshot_and_reservation_are_read_only_and_never_invent_before_after(): void
    {
        DB::table('preinvoice_draft_reservations')->insert(['id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>5,'created_at'=>'2026-09-01 10:00:00','released_at'=>'2026-09-01 11:00:00','release_reason'=>'save_as_draft']);
        $tables=['warehouse_stocks','product_variants','products','preinvoice_draft_reservations','stock_movements'];
        $before=[];foreach($tables as$t)$before[$t]=DB::table($t)->get()->toJson();
        $h=app(ProductStockHistoryService::class)->history(1,1);
        foreach($tables as$t)$this->assertSame($before[$t],DB::table($t)->get()->toJson());
        $r=$h['rows']->firstWhere('id','reserve-1');
        $this->assertNull($r->available_delta);
        $this->assertFalse($r->snapshot_reliable);
        $this->assertSame('incomplete',$r->evidence_level);
    }

    public function test_selected_variant_does_not_include_other_variant_explanations(): void
    {
        $this->zeroReceipt();
        DB::table('warehouse_inbound_receipt_items')->where('id',1)->update(['product_variant_id'=>2]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $this->assertNull($h['rows']->firstWhere('id','inbound-item-1'));
    }

    public function test_projection_check_does_not_claim_document_reconciliation(): void
    {
        DB::table('product_variants')->where('id',1)->update(['stock'=>3,'reserved'=>2]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $this->assertFalse($h['projection_matches']);
        $this->assertSame(3,$h['cached_current']);
        $this->assertSame(2,$h['reserved']);
        $this->assertFalse($h['history_complete']);
    }

    public function test_controller_searches_receipt_and_arabic_letters_before_pagination(): void
    {
        $this->zeroReceipt();
        $controller=app(\App\Http\Controllers\ProductStockLedgerController::class);
        $product=\App\Models\Product::findOrFail(1);
        $data=$controller->index(\Illuminate\Http\Request::create('/products/1/stock-ledger','GET',['q'=>'WI-000259','effect'=>'reference']),$product)->getData();
        $this->assertSame(1,$data['movements']->total());
        $this->assertSame('inbound-item-1',$data['movements']->first()->id);
        $data=$controller->index(\Illuminate\Http\Request::create('/products/1/stock-ledger','GET',['q'=>'مدل ي']),$product)->getData();
        $this->assertSame(1,$data['movements']->total());
        $this->assertSame('مدل ي',$data['filters']['q']);
    }

    public function test_synthetic_stocktake_and_existing_movement_do_not_duplicate_delta(): void
    {
        DB::table('stock_count_documents')->insert(['id'=>1,'status'=>'finalized','document_number'=>'STC-1','finalized_at'=>'2026-09-01']);
        DB::table('stock_count_document_items')->insert(['id'=>1,'document_id'=>1,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'system_available_at_start'=>5,'new_available'=>0]);
        DB::table('stock_movements')->insert(['id'=>1,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'out','reason'=>'adjustment','quantity'=>5,'stock_before'=>5,'stock_after'=>0,'reference_type'=>'stock_count_document','reference_id'=>1]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $this->assertSame(-5,$h['rows']->sum('available_delta'));
        $this->assertNull($h['rows']->firstWhere('id','stocktake-1'));
    }

    public function test_financial_only_return_is_shown_without_invented_central_stock(): void
    {
        DB::table('sales_return_documents')->insert(['id'=>1,'document_number'=>'00001','status'=>'applied','created_by'=>1]);
        DB::table('sales_return_document_items')->insert(['id'=>1,'document_id'=>1,'product_id'=>1,'product_variant_id'=>1,'return_quantity'=>4,'item_condition'=>'damaged','destination_warehouse_id'=>2]);
        $h=app(ProductStockHistoryService::class)->history(1,1);
        $r=$h['rows']->firstWhere('id','sales-return-item-1');
        $this->assertNotNull($r);
        $this->assertSame(0,$r->available_delta);
        $this->assertSame(0,$h['current']);
    }

    public function test_actual_blade_renders_receipt_zero_and_search_controls(): void
    {
        $this->zeroReceipt();
        DB::table('stock_movements')->insert(['id'=>1,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'in','reason'=>'purchase','quantity'=>1,'stock_before'=>0,'stock_after'=>1,'created_at'=>'2026-09-01 09:00:00']);
        DB::table('stock_movements')->insert(['id'=>2,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'out','reason'=>'sale','quantity'=>2,'stock_before'=>3,'stock_after'=>1,'created_at'=>'2026-09-01 08:00:00']);
        DB::table('stock_movements')->insert(['id'=>3,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'out','reason'=>'sale','quantity'=>3,'stock_before'=>10,'stock_after'=>10,'created_at'=>'2026-09-01 07:00:00']);
        DB::table('preinvoice_draft_reservations')->insert(['id'=>1,'product_id'=>1,'variant_id'=>1,'quantity'=>1,'created_at'=>'2026-09-01 10:00:00']);
        $preview=storage_path('app/stock-ledger-qa');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($preview.'/views/layouts');
        $css=file_get_contents(public_path('css/product-stock-ledger.css'));
        \Illuminate\Support\Facades\File::put($preview.'/views/layouts/app.blade.php','<!doctype html><html dir="rtl" lang="fa"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:20px;background:#f4f7f9;font-family:Tahoma}.form-control,.form-select{width:100%;box-sizing:border-box}.btn{padding:10px;border:1px solid;display:inline-block}'.$css.'</style><body>@yield("content")</body></html>');
        view()->getFinder()->prependLocation($preview.'/views');
        view()->share('errors',new \Illuminate\Support\ViewErrorBag());
        $data=app(\App\Http\Controllers\ProductStockLedgerController::class)->index(\Illuminate\Http\Request::create('/products/1/stock-ledger'),\App\Models\Product::findOrFail(1))->getData();
        $rendered=view('products.stock-ledger',$data)->render();
        $this->assertStringContainsString('دریافت صفر',$rendered);
        $this->assertStringContainsString('سند توضیحی',$rendered);
        $this->assertStringContainsString('name="q"',$rendered);
        $this->assertStringContainsString('WI-',$rendered);
        $dom=new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$rendered);
        $xpath=new \DOMXPath($dom);
        $rowText=fn($id)=>preg_replace('/\s+/u',' ',trim($xpath->query('//tr[@data-history-id="'.$id.'"]')->item(0)?->textContent ?? ''));
        $this->assertStringContainsString('+۱',$rowText('1'));
        $this->assertStringContainsString('۱ عدد اضافه شد',$rowText('1'));
        $this->assertStringContainsString('−۲',$rowText('2'));
        $this->assertStringContainsString('۲ عدد کم شد',$rowText('2'));
        $this->assertStringContainsString('تعداد ثبت‌شده',$rowText('3'));
        $this->assertStringNotContainsString('−۳',$rowText('3'));
        $this->assertStringContainsString('تعداد دریافت',$rowText('inbound-item-1'));
        $this->assertStringContainsString('از ۱ عدد، هیچ کالایی تحویل نشد',$rowText('inbound-item-1'));
        $this->assertStringContainsString('تعداد رزرو',$rowText('reserve-1'));
        $this->assertStringContainsString('ثبت رزرو سفارش',$rowText('reserve-1'));
        $this->assertStringContainsString('این تعداد، تغییر مستقل موجودی نیست',$rowText('reserve-1'));
        $this->assertSame(0,$xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," psl-unknown ")]')->length);
        $this->assertStringNotContainsString('اطلاعات ناکافی؛ نیاز به بررسی',$rendered);
        $this->assertStringContainsString('موجودی فعلی',$rendered);
        \Illuminate\Support\Facades\File::put($preview.'/preview.html',$rendered);
        $data['summary']->cached_current=4;
        $data['summary']->projection_matches=false;
        $mismatch=view('products.stock-ledger',$data)->render();
        $this->assertStringContainsString('اختلاف: ۴ عدد',$mismatch);
        $this->assertStringContainsString('این اختلاف به‌تنهایی دلیل باگ نیست',$mismatch);
    }

    public function test_receipt_with_wrong_linked_movement_is_flagged_incomplete(): void
    {
        $this->zeroReceipt('received');
        DB::table('warehouse_inbound_receipt_items')->where('id',1)->update(['accepted_quantity'=>1,'stock_movement_id'=>1]);
        DB::table('stock_movements')->insert(['id'=>1,'product_id'=>1,'product_variant_id'=>2,'warehouse_id'=>1,'type'=>'in','reason'=>'purchase','quantity'=>1,'stock_before'=>0,'stock_after'=>1]);
        $h=app(ProductStockHistoryService::class)->history(1);
        $this->assertSame('incomplete',$h['rows']->firstWhere('id','inbound-item-1')->evidence_level);
        $this->assertNull($h['rows']->firstWhere('id',1)->source_url);
    }

    public function test_search_finds_an_old_record_outside_first_page(): void
    {
        for($id=1;$id<=35;$id++) DB::table('stock_movements')->insert(['id'=>$id,'product_id'=>1,'product_variant_id'=>1,'warehouse_id'=>1,'type'=>'in','reason'=>'purchase','quantity'=>1,'stock_before'=>0,'stock_after'=>1,'reference'=>$id===1?'TARGET-OLD':'OTHER-'.$id,'created_at'=>sprintf('2026-09-%02d 10:00:00',min($id,28))]);
        $data=app(\App\Http\Controllers\ProductStockLedgerController::class)->index(\Illuminate\Http\Request::create('/products/1/stock-ledger','GET',['q'=>'TARGET-OLD']),\App\Models\Product::findOrFail(1))->getData();
        $this->assertSame(1,$data['movements']->total());
        $this->assertSame(1,$data['movements']->first()->id);
    }
}
