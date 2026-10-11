<?php

namespace Tests\Feature;

use App\Http\Controllers\PreinvoiceController;
use App\Models\User;
use App\Services\SalesDocumentAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class PreinvoiceDisabledSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Schema::create('users', function (Blueprint $t) {$t->id();$t->string('name');});
        Schema::create('products', function (Blueprint $t) {$t->id();$t->string('name');$t->boolean('is_sellable');});
        Schema::create('product_variants', function (Blueprint $t) {$t->id();$t->integer('product_id');$t->string('variant_name');$t->boolean('is_active');$t->boolean('sales_enabled');});
        Schema::create('preinvoice_orders', function (Blueprint $t) {$t->id();$t->string('uuid');$t->integer('created_by');$t->string('status');$t->string('customer_name');$t->string('customer_mobile');$t->timestamps();});
        Schema::create('preinvoice_order_items', function (Blueprint $t) {$t->id();$t->integer('preinvoice_order_id');$t->integer('product_id');$t->integer('variant_id');$t->integer('quantity');$t->integer('sort_order');});
        Schema::create('invoices', function (Blueprint $t) {$t->id();$t->integer('preinvoice_order_id');});
        foreach (['warehouse_stocks','preinvoice_draft_reservations','stock_movements'] as $table) {
            Schema::create($table, function (Blueprint $t) {$t->id();$t->integer('quantity');});
            DB::table($table)->insert(['quantity'=>10]);
        }
        DB::table('users')->insert(['id'=>1,'name'=>'فروشنده']);
        $this->actingAs(User::findOrFail(1));
        $this->mock(SalesDocumentAccessService::class)->shouldReceive('canSellerEditPreinvoiceItems')->andReturn(true);
        DB::table('preinvoice_orders')->insert(['id'=>1,'uuid'=>'01879','created_by'=>1,'status'=>'draft','customer_name'=>'محمود محمودی','customer_mobile'=>'09120005766']);
        DB::table('products')->insert(['id'=>743,'name'=>'سیلیکون GB کپی سامسونگ','is_sellable'=>false]);
        foreach ([7399=>'Galaxy A05 آبی',7367=>'Galaxy A12 آبی'] as $id=>$name) {
            DB::table('product_variants')->insert(['id'=>$id,'product_id'=>743,'variant_name'=>$name,'is_active'=>true,'sales_enabled'=>false]);
            DB::table('preinvoice_order_items')->insert(['preinvoice_order_id'=>1,'product_id'=>743,'variant_id'=>$id,'quantity'=>1,'sort_order'=>0]);
        }
    }

    private function payload(): array
    {
        return ['intent'=>'submit','customer_name'=>'نام ویرایش‌شده','customer_mobile'=>'09120005766',
            'products'=>[['id'=>743,'variety_id'=>7399,'quantity'=>1],['id'=>743,'variety_id'=>7367,'quantity'=>1]]];
    }

    private function snapshot(): array
    {
        $out=[];
        foreach (['preinvoice_orders','preinvoice_order_items','invoices','warehouse_stocks','preinvoice_draft_reservations','stock_movements'] as $table) {
            $out[$table]=DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $out;
    }

    public function test_submit_redirects_to_edit_with_every_disabled_variant_and_preserves_input_and_data(): void
    {
        $before=$this->snapshot();DB::enableQueryLog();
        $url=route('preinvoice.draft.edit','01879');
        $this->from($url)->put(route('preinvoice.draft.update','01879'),$this->payload())
            ->assertRedirect($url)->assertSessionHasErrors(['products.0.variety_id','products.1.variety_id'])
            ->assertSessionHasInput('customer_name','نام ویرایش‌شده')->assertSessionHasInput('products.1.variety_id',7367);
        $messages=implode(' ',session('errors')->all());
        $this->assertStringContainsString('سیلیکون GB کپی سامسونگ',$messages);
        $this->assertStringContainsString('Galaxy A05 آبی',$messages);
        $this->assertStringContainsString('Galaxy A12 آبی',$messages);
        $this->assertStringContainsString('غیرفعال',$messages);
        $this->assertSame($before,$this->snapshot());
        $this->assertSame([],array_values(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query']))));
    }

    public function test_json_submit_returns_validation_errors_instead_of_404(): void
    {
        $before=$this->snapshot();
        $this->putJson(route('preinvoice.draft.update','01879'),$this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['products.0.variety_id','products.1.variety_id']);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_an_individually_disabled_or_structurally_inactive_variant_is_also_reported(): void
    {
        DB::table('products')->update(['is_sellable'=>true]);
        DB::table('product_variants')->where('id',7399)->update(['sales_enabled'=>true,'is_active'=>false]);
        $before=$this->snapshot();
        $this->putJson(route('preinvoice.draft.update','01879'),$this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['products.0.variety_id','products.1.variety_id']);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_sellable_rows_pass_the_guard_without_writing_inventory(): void
    {
        DB::table('products')->update(['is_sellable'=>true]);
        DB::table('product_variants')->update(['sales_enabled'=>true]);
        $before=$this->snapshot();
        $guard=new \ReflectionMethod(PreinvoiceController::class,'assertDraftItemsSellableForSubmission');
        DB::transaction(fn()=> $guard->invoke(app(PreinvoiceController::class),$this->payload()['products']));
        $this->assertSame($before,$this->snapshot());
    }

    public function test_existing_disabled_rows_can_still_be_validated_for_draft_storage(): void
    {
        $payload=$this->payload();$payload['intent']='draft';
        $validator=new \ReflectionMethod(PreinvoiceController::class,'validateDraftPayload');
        $order=\App\Models\PreinvoiceOrder::with('items')->findOrFail(1);
        $validated=$validator->invoke(app(PreinvoiceController::class),\Illuminate\Http\Request::create('/','PUT',$payload),false,$order);
        $this->assertSame($payload['products'],$validated['products']);
    }
}
