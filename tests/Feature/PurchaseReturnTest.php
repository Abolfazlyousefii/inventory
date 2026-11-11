<?php

namespace Tests\Feature;

use App\Models\{Purchase, PurchaseReturn};
use App\Services\PurchaseReturnService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('site_stock_sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->default(0);
            $table->string('origin', 16)->default('inventory');
            $table->dateTime('created_at');
            $table->dateTime('available_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claimed_by', 36)->nullable();
            $table->dateTime('locked_until')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->index(
                ['available_at', 'id'],
                'site_stock_outbox_due_idx'
            );
        });
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('app/purchase-returns-20261011'));
        Schema::create('suppliers', function(Blueprint $t){$t->id();$t->string('name');$t->boolean('is_inventory_adjustment_source')->default(false);$t->timestamps();});
        Schema::create('products', function(Blueprint $t){$t->id();$t->string('name');$t->integer('stock')->default(0);$t->timestamps();});
        Schema::create('product_variants', function(Blueprint $t){
            $t->boolean('sales_enabled')->default(true);$t->id();$t->integer('product_id');$t->string('variant_name');$t->integer('stock')->default(0);$t->integer('reserved')->default(0);$t->timestamps();});
        Schema::create('warehouses', function(Blueprint $t){$t->id();$t->string('name');$t->string('type');$t->boolean('is_active')->default(true);$t->timestamps();});
        Schema::create('warehouse_stocks', function(Blueprint $t){$t->id();$t->integer('warehouse_id');$t->integer('product_id');$t->integer('product_variant_id')->nullable();$t->integer('quantity');$t->timestamps();});
        Schema::create('purchases', function(Blueprint $t){$t->id();$t->integer('supplier_id');$t->integer('warehouse_id');$t->integer('user_id')->nullable();$t->integer('total_amount')->default(10000);$t->timestamp('purchased_at')->nullable();$t->timestamps();});
        Schema::create('purchase_items', function(Blueprint $t){$t->id();$t->integer('purchase_id');$t->integer('product_id');$t->integer('product_variant_id')->nullable();$t->integer('quantity');$t->integer('buy_price');$t->string('product_name');$t->string('variant_name')->nullable();$t->string('product_code')->nullable();$t->timestamps();});
        Schema::create('stock_movements', function(Blueprint $t){$t->id();foreach(['product_id','product_variant_id','warehouse_id','user_id','stock_before','stock_after','reference_id'] as $f)$t->integer($f)->nullable();foreach(['type','reason','transaction_type','reference','reference_type','note'] as $f)$t->string($f)->nullable();$t->integer('quantity');$t->timestamps();});
        Schema::create('supplier_ledgers', function(Blueprint $t){$t->id();$t->integer('amount');});
        Schema::create('customer_ledgers', function(Blueprint $t){$t->id();$t->integer('amount');});
        Schema::create('activity_logs', function(Blueprint $t){$t->id();$t->integer('user_id')->nullable();$t->string('action');$t->string('subject_type');$t->integer('subject_id');$t->text('description');$t->json('properties');$t->timestamp('occurred_at');$t->timestamps();});
        $migration = require base_path('database/migrations/2026_10_11_160000_create_purchase_returns.php');
        $migration->up();
        DB::table('suppliers')->insert([['id'=>1,'name'=>'تأمین‌کننده'],['id'=>2,'name'=>'دیگر']]);
        DB::table('products')->insert(['id'=>1,'name'=>'کالا','stock'=>6]);
        DB::table('product_variants')->insert(['id'=>1,'product_id'=>1,'variant_name'=>'مشکی','stock'=>6,'reserved'=>4]);
        DB::table('warehouses')->insert(['id'=>1,'name'=>'انبار مرکزی','type'=>'central']);
        DB::table('warehouse_stocks')->insert(['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>6]);
        DB::table('purchases')->insert([['id'=>1,'supplier_id'=>1,'warehouse_id'=>1,'total_amount'=>10000],['id'=>2,'supplier_id'=>2,'warehouse_id'=>1,'total_amount'=>10000]]);
        DB::table('purchase_items')->insert([['id'=>1,'purchase_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10,'buy_price'=>1000,'product_name'=>'کالا','variant_name'=>'مشکی'],['id'=>2,'purchase_id'=>2,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10,'buy_price'=>1000,'product_name'=>'دیگر','variant_name'=>'مشکی']]);
    }

    public function test_new_purchase_return_rejects_inventory_adjustment_source_without_moving_stock(): void
    {
        DB::table('suppliers')->where('id',1)->update([
            'name'=>'کسری و اضافه',
            'is_inventory_adjustment_source'=>true,
        ]);

        $this->invalid(fn()=> $this->service()->create($this->payload(2),1));
        $this->assertSame(6,$this->quantity());
        $this->assertDatabaseCount('purchase_returns',0);
        $this->assertDatabaseCount('stock_movements',0);

        $real = $this->service()->create($this->payload(2,[
            'supplier_id'=>2,
            'purchase_id'=>2,
            'items'=>[['purchase_item_id'=>2,'quantity'=>2]],
        ]),1);
        $this->assertSame(2,(int)$real->supplier_id);
        $this->assertSame(4,$this->quantity());
    }

    public function test_adjustment_source_does_not_appear_as_new_return_supplier_but_legacy_return_is_editable(): void
    {
        $legacy = $this->service()->create($this->payload(2),1);
        DB::table('suppliers')->where('id',1)->update([
            'name'=>'کسری و اضافه',
            'is_inventory_adjustment_source'=>true,
        ]);

        $controller=app(\\App\\Http\\Controllers\\PurchaseReturnController::class);
        $new=$controller->create(\\Illuminate\\Http\\Request::create('/purchase-returns/create'))->getData();
        $this->assertFalse($new['suppliers']->contains('id',1));
        $this->assertTrue($new['suppliers']->contains('id',2));

        $this->invalid(fn()=> $controller->create(
            \\Illuminate\\Http\\Request::create('/purchase-returns/create','GET',[
                'supplier_id'=>1, 'purchase_id'=>1
            ])
        ));

        $edit=$controller->edit(
            \\Illuminate\\Http\\Request::create('/purchase-returns/'.$legacy->id.'/edit','GET'),
            $legacy
        )->getData();
        $this->assertSame(1,(int)$edit['supplierId']);
        $updated=$this->service()->update($legacy,$this->payload(3,['version'=>1]),1);
        $this->assertSame(3,(int)$updated->items()->first()->quantity);
        $this->assertSame(3,$this->quantity());
        $this->assertDatabaseCount('supplier_ledgers',0);
    }

    private function secondaryWarehouse(int $quantity=5, bool $active=true): void
    {
        DB::table('warehouses')->insert(['id'=>2,'name'=>'انبار مرجوعی','type'=>'return','is_active'=>$active]);
        DB::table('warehouse_stocks')->insert(['warehouse_id'=>2,'product_id'=>1,'product_variant_id'=>1,'quantity'=>$quantity]);
    }

    public function test_return_from_selected_warehouse_leaves_central_and_reservations_unchanged(): void
    {
        $this->secondaryWarehouse();
        $doc=$this->service()->create($this->payload(2,['warehouse_id'=>2]),1);
        $this->assertSame(2,(int)$doc->warehouse_id);
        $this->assertSame(3,(int)DB::table('warehouse_stocks')->where('warehouse_id',2)->value('quantity'));
        $this->assertSame(6,$this->quantity());
        $this->assertSame(6,(int)DB::table('product_variants')->value('stock'));
        $this->assertSame(4,(int)DB::table('product_variants')->value('reserved'));
        $this->assertDatabaseHas('stock_movements',['warehouse_id'=>2,'type'=>'out','quantity'=>2]);
        $this->assertDatabaseCount('supplier_ledgers',0);$this->assertDatabaseCount('customer_ledgers',0);
    }

    public function test_insufficient_selected_warehouse_does_not_borrow_central_stock(): void
    {
        $this->secondaryWarehouse(1);
        $this->invalid(fn()=> $this->service()->create($this->payload(2,['warehouse_id'=>2]),1));
        $this->assertSame(6,$this->quantity());$this->assertDatabaseCount('purchase_returns',0);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_edit_and_delete_restore_only_the_original_selected_warehouse_even_if_inactive(): void
    {
        $this->secondaryWarehouse();
        $doc=$this->service()->create($this->payload(2,['warehouse_id'=>2]),1);
        $doc=$this->service()->update($doc,$this->payload(4,['warehouse_id'=>2,'version'=>1]),1);
        $this->assertSame(1,(int)DB::table('warehouse_stocks')->where('warehouse_id',2)->value('quantity'));
        $this->invalid(fn()=> $this->service()->update($doc,$this->payload(1,['warehouse_id'=>1,'version'=>2]),1));
        DB::table('warehouses')->where('id',2)->update(['is_active'=>false]);
        $this->service()->delete($doc,2,1,'بازگرداندن به همان مبدأ');
        $this->assertSame(5,(int)DB::table('warehouse_stocks')->where('warehouse_id',2)->value('quantity'));
        $this->assertSame(6,$this->quantity());
    }

    public function test_inactive_or_missing_warehouse_cannot_create_a_return(): void
    {
        $this->secondaryWarehouse(5,false);
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['warehouse_id'=>2]),1));
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['warehouse_id'=>999]),1));
        $this->assertDatabaseCount('purchase_returns',0);$this->assertSame(6,$this->quantity());
    }

    public function test_same_submission_cannot_silently_switch_warehouses(): void
    {
        $this->secondaryWarehouse();$data=$this->payload(1,['warehouse_id'=>2]);
        $doc=$this->service()->create($data,1);
        $this->assertSame($doc->id,$this->service()->create($data,1)->id);
        $this->invalid(fn()=> $this->service()->create(array_replace($data,['warehouse_id'=>1]),1));
        $this->assertDatabaseCount('purchase_returns',1);$this->assertDatabaseCount('stock_movements',1);
    }

    public function test_purchase_return_limit_is_shared_across_warehouses(): void
    {
        $this->secondaryWarehouse(20);
        $this->service()->create($this->payload(6,['warehouse_id'=>1]),1);
        $this->invalid(fn()=> $this->service()->create($this->payload(5,['warehouse_id'=>2]),1));
        $this->service()->create($this->payload(4,['warehouse_id'=>2]),1);
        $this->assertSame(10,$this->service()->returnedQuantity(1));
        $this->assertSame(16,(int)DB::table('warehouse_stocks')->where('warehouse_id',2)->value('quantity'));
    }

    public function test_form_loads_capacity_for_selected_source_and_edit_keeps_its_source(): void
    {
        $this->secondaryWarehouse(3);
        $controller=app(\App\Http\Controllers\PurchaseReturnController::class);
        $request=\Illuminate\Http\Request::create('/purchase-returns/create','GET',['supplier_id'=>1,'purchase_id'=>1,'warehouse_id'=>2]);
        $data=$controller->create($request)->getData();
        $this->assertSame(2,(int)$data['warehouseId']);$this->assertSame(3,$data['rows']->first()->free);
        $this->assertSame(3,$data['rows']->first()->limit);
        $doc=$this->service()->create($this->payload(2,['warehouse_id'=>2]),1);
        $edit=$controller->edit(\Illuminate\Http\Request::create('/edit','GET',['warehouse_id'=>1]),$doc)->getData();
        $this->assertSame(2,(int)$edit['warehouseId']);$this->assertSame(3,$edit['rows']->first()->capacity);
    }

    public function test_controller_requires_and_forwards_the_selected_warehouse(): void
    {
        $this->secondaryWarehouse();
        $data=$this->payload(2,['warehouse_id'=>2])+['returned_at_fa'=>'1405/07/19'];
        $request=\Illuminate\Http\Request::create('/purchase-returns','POST',$data);
        $request->setUserResolver(fn()=> (object)['id'=>1]);
        $response=app(\App\Http\Controllers\PurchaseReturnController::class)->store($request);
        $this->assertSame(302,$response->getStatusCode());
        $this->assertSame(2,(int)PurchaseReturn::firstOrFail()->warehouse_id);
        $missing=\Illuminate\Http\Request::create('/purchase-returns','POST',$this->payload()+['returned_at_fa'=>'1405/07/19']);
        $this->invalid(fn()=> app(\App\Http\Controllers\PurchaseReturnController::class)->store($missing));
        $this->assertDatabaseCount('purchase_returns',1);
    }

    public function test_inactive_source_form_fails_without_redirecting_back_to_itself(): void
    {
        $this->secondaryWarehouse(3,false);
        $request=\Illuminate\Http\Request::create('/purchase-returns/create','GET',['warehouse_id'=>2]);
        try {
            app(\App\Http\Controllers\PurchaseReturnController::class)->create($request);
            $this->fail('Inactive warehouse must not be selected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422,$e->getStatusCode());
        }
    }

    private function payload(int $qty = 2, array $extra = []): array
    {
        return $extra + ['supplier_id'=>1,'purchase_id'=>1,'returned_at'=>'2026-10-11','submission_token'=>(string) \Illuminate\Support\Str::uuid(),'items'=>[['purchase_item_id'=>1,'quantity'=>$qty]],'note'=>'نمونه'];
    }
    private function quantity(): int { return (int) DB::table('warehouse_stocks')->where('product_variant_id',1)->value('quantity'); }
    private function service(): PurchaseReturnService { return app(PurchaseReturnService::class); }
    private function invalid(callable $call): void
    {
        try { $call(); $this->fail('Expected validation failure'); } catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
    }

    public function test_registration_is_immediate_idempotent_and_financially_neutral(): void
    {
        $data=$this->payload();$doc=$this->service()->create($data,1);
        $this->assertSame('00001',$doc->document_number);$this->assertSame(4,$this->quantity());
        $this->assertSame(2000,(int)$doc->total_amount);$this->assertSame(4,(int)DB::table('product_variants')->value('reserved'));
        $again=$this->service()->create($data,1);$this->assertSame($doc->id,$again->id);$this->assertSame(4,$this->quantity());
        $this->assertDatabaseCount('stock_movements',1);$this->assertDatabaseCount('purchase_return_revisions',1);
        $this->assertDatabaseCount('supplier_ledgers',0);$this->assertDatabaseCount('customer_ledgers',0);
        $this->assertDatabaseHas('stock_movements',['type'=>'out','reason'=>'purchase_return','quantity'=>2,'stock_before'=>6,'stock_after'=>4]);
    }

    public function test_partial_returns_are_limited_by_both_purchase_and_free_stock(): void
    {
        $this->invalid(fn()=> $this->service()->create($this->payload(7),1));$this->assertSame(6,$this->quantity());
        $first=$this->service()->create($this->payload(4),1);$this->assertSame(2,$this->quantity());
        DB::table('warehouse_stocks')->update(['quantity'=>30]);
        $this->invalid(fn()=> $this->service()->create($this->payload(7),1));
        $second=$this->service()->create($this->payload(6),1);$this->assertSame('00002',$second->document_number);
        $this->invalid(fn()=> $this->service()->create($this->payload(1),1));
        $this->assertSame(24,$this->quantity());$this->assertDatabaseCount('purchase_returns',2);
    }

    public function test_edit_applies_only_difference_and_stale_requests_cannot_write(): void
    {
        $doc=$this->service()->create($this->payload(2),1);
        $doc=$this->service()->update($doc,$this->payload(4,['version'=>1]),1);$this->assertSame(2,$this->quantity());
        $this->invalid(fn()=> $this->service()->update($doc,$this->payload(3,['version'=>1]),1));
        $doc=$this->service()->update($doc,$this->payload(1,['version'=>2]),1);$this->assertSame(5,$this->quantity());
        $this->assertSame(1000,(int)$doc->total_amount);$this->assertDatabaseCount('stock_movements',3);
        $this->service()->delete($doc,3,1,'اشتباه ثبت');$this->assertSame(6,$this->quantity());
        $this->assertNotNull($doc->fresh()->deleted_at);$this->assertDatabaseCount('purchase_return_revisions',4);
        $this->assertDatabaseCount('stock_movements',4);
        $this->invalid(fn()=> $this->service()->delete($doc,3,1,'دوباره'));$this->assertSame(6,$this->quantity());
        $next=$this->service()->create($this->payload(1),1);$this->assertSame('00002',$next->document_number);
    }

    public function test_foreign_supplier_row_duplicates_and_no_variant_aggregate_are_rejected(): void
    {
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['supplier_id'=>2]),1));
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['items'=>[['purchase_item_id'=>2,'quantity'=>1]]]),1));
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['items'=>[['purchase_item_id'=>1,'quantity'=>1],['purchase_item_id'=>1,'quantity'=>1]]]),1));
        DB::table('purchase_items')->where('id',1)->update(['product_variant_id'=>null]);
        $this->invalid(fn()=> $this->service()->create($this->payload(),1));$this->assertSame(6,$this->quantity());
    }

    public function test_shared_variant_rows_check_the_combined_delta_and_rollback_everything(): void
    {
        DB::table('purchase_items')->insert(['id'=>3,'purchase_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10,'buy_price'=>2000,'product_name'=>'کالا']);
        $this->invalid(fn()=> $this->service()->create($this->payload(1,['items'=>[['purchase_item_id'=>1,'quantity'=>4],['purchase_item_id'=>3,'quantity'=>4]]]),1));
        $this->assertSame(6,$this->quantity());$this->assertDatabaseCount('purchase_returns',0);$this->assertDatabaseCount('stock_movements',0);
        $this->assertSame(0,(int)DB::table('purchase_return_sequences')->value('last_number'));
    }

    public function test_purchase_with_active_return_is_protected_but_cancelled_return_does_not_block(): void
    {
        $doc=$this->service()->create($this->payload(),1);
        $this->invalid(fn()=> $this->service()->assertPurchaseMutable(Purchase::findOrFail(1)));
        $this->service()->delete($doc,1,1,'اشتباه');$this->service()->assertPurchaseMutable(Purchase::findOrFail(1));
        $this->assertSame(6,$this->quantity());
    }

    public function test_pages_render_persian_dates_escape_data_and_read_without_writes(): void
    {
        view()->share('errors',new \Illuminate\Support\ViewErrorBag());
        Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('categories',function(Blueprint $t){$t->id();$t->string('name');});
        Schema::create('permissions',function(Blueprint $t){$t->id();$t->string('name')->nullable();$t->string('key')->nullable();$t->string('guard_name')->nullable();});
        DB::table('users')->insert(['id'=>1,'name'=>'انبار']);
        DB::table('purchase_items')->where('id',1)->update(['product_name'=>'کالا <script>alert(1)</script>']);
        $this->secondaryWarehouse(3);
        DB::table('warehouses')->insert([['id'=>3,'name'=>'انبار ضایعات','type'=>'scrap'],['id'=>4,'name'=>'انبار پرسنل','type'=>'personnel'],['id'=>5,'name'=>'انبار شوروم','type'=>'showroom']]);
        $doc=$this->service()->create($this->payload(2,['warehouse_id'=>2]),1);
        DB::enableQueryLog();DB::flushQueryLog();
        $controller=app(\App\Http\Controllers\PurchaseReturnController::class);
        $request=\Illuminate\Http\Request::create('/purchase-returns/create','GET',['supplier_id'=>1,'purchase_id'=>1,'warehouse_id'=>2]);
        app()->instance('request',$request);
        $form=$controller->create($request)->render();
        $this->assertStringContainsString('data-pr-quantity',$form);$this->assertStringContainsString('&lt;script&gt;',$form);
        $this->assertStringNotContainsString('<script>alert(1)</script>',$form);
        $this->assertStringContainsString('data-pr-warehouse',$form);$this->assertStringContainsString('name="warehouse_id" value="2"',$form);
        $show=$controller->show($doc)->render();$this->assertStringContainsString('۰۰۰۰۱',$show);$this->assertStringContainsString('۱۴۰۵/۰۷/۱۹',$show);
        $print=$controller->print($doc)->render();$this->assertStringContainsString('۰۰۰۰۱',$print);
        $this->assertStringContainsString('۱۴۰۵/۰۷/۱۹',$print);
        $this->assertStringContainsString('انبار مبدأ: انبار مرجوعی',$print);
        $index=$controller->index(\Illuminate\Http\Request::create('/purchase-returns'))->render();$this->assertStringContainsString('برگشت جدید',$index);
        $writes=array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete|alter|create|drop)/i',$q['query']));$this->assertEmpty($writes);
        $dir=storage_path('app/purchase-returns-20261011');
        foreach(['form'=>$form,'show'=>$show,'print'=>$print,'index'=>$index] as $name=>$html)file_put_contents($dir.'/'.$name.'.html',$html);
        $central=\Illuminate\Http\Request::create('/purchase-returns/create','GET',['supplier_id'=>1,'purchase_id'=>1,'warehouse_id'=>1]);
        app()->instance('request',$central);
        file_put_contents($dir.'/form-central.html',$controller->create($central)->render());
    }

    public function test_http_requires_authentication_and_jalali_validation_preserves_inventory(): void
    {
        $this->get('/purchase-returns')->assertRedirect('/login');
        $this->post('/purchase-returns',$this->payload())->assertRedirect('/login');
        $request=\Illuminate\Http\Request::create('/purchase-returns','POST',$this->payload()+['returned_at_fa'=>'1405/13/01']);
        $this->invalid(fn()=>app(\App\Http\Controllers\PurchaseReturnController::class)->store($request));
        $this->assertSame(6,$this->quantity());$this->assertDatabaseCount('purchase_returns',0);
        $this->assertSame(['page.warehouse.purchases'],\App\Support\PageAccessCatalog::permissionsForRoute('purchase-returns.store'));
    }

    public function test_revision_failure_rolls_back_stock_and_number_and_edit_cannot_change_source(): void
    {
        \App\Models\PurchaseReturnRevision::creating(fn()=>throw new \RuntimeException('audit unavailable'));
        try {$this->service()->create($this->payload(),1);$this->fail('expected rollback');}catch(\RuntimeException $e){$this->assertSame('audit unavailable',$e->getMessage());}
        \App\Models\PurchaseReturnRevision::flushEventListeners();
        $this->assertSame(6,$this->quantity());$this->assertDatabaseCount('stock_movements',0);$this->assertDatabaseCount('purchase_returns',0);
        $doc=$this->service()->create($this->payload(),1);$this->assertSame('00001',$doc->document_number);
        $this->invalid(fn()=> $this->service()->update($doc,$this->payload(1,['purchase_id'=>2,'supplier_id'=>2,'version'=>1]),1));
        $this->assertSame(4,$this->quantity());$this->assertSame(1,$doc->fresh()->version);
    }
}
