<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

uses(RefreshDatabase::class);

it('creates nullable seller columns with distinct user foreign keys and no constraint named one', function () {
    expect(Schema::hasColumn('invoices','seller_id'))->toBeTrue()
        ->and(Schema::hasColumn('preinvoice_orders','seller_id'))->toBeTrue();
    expect(collect(Schema::getColumns('invoices'))->firstWhere('name','seller_id')['nullable'])->toBeTrue()
        ->and(collect(Schema::getColumns('preinvoice_orders'))->firstWhere('name','seller_id')['nullable'])->toBeTrue();
    $invoiceFks=collect(Schema::getForeignKeys('invoices'))->filter(fn($fk)=>in_array('seller_id',$fk['columns']??[],true));
    $preinvoiceFks=collect(Schema::getForeignKeys('preinvoice_orders'))->filter(fn($fk)=>in_array('seller_id',$fk['columns']??[],true));
    expect($invoiceFks)->toHaveCount(1)->and($preinvoiceFks)->toHaveCount(1)
        ->and($invoiceFks->pluck('name'))->not->toContain('1')->and($preinvoiceFks->pluck('name'))->not->toContain('1');
});

it('repairs a partial migration where the column and index exist but the invoice foreign key does not', function () {
    Schema::table('invoices',fn(Blueprint $table)=>$table->dropForeign(['seller_id']));
    expect(collect(Schema::getForeignKeys('invoices'))->filter(fn($fk)=>in_array('seller_id',$fk['columns']??[],true)))->toHaveCount(0);
    $migration=require database_path('migrations/2026_08_01_200000_add_seller_ownership_to_sales_documents.php');
    $migration->up();
    expect(Schema::hasColumn('invoices','seller_id'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('invoices'))->filter(fn($fk)=>in_array('seller_id',$fk['columns']??[],true)))->toHaveCount(1);
});

it('is idempotent and only backfills a verified legacy seller and its linked invoice', function () {
    $seller=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'is_seller'=>true]);
    $technical=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'is_seller'=>false]);
    $base=['status'=>'draft','customer_name'=>'Test','customer_mobile'=>'09120000000','customer_address'=>'Test','province_id'=>1];
    $owned=PreinvoiceOrder::query()->create($base+['uuid'=>fake()->uuid(),'created_by'=>$seller->id,'seller_id'=>null]);
    $unknown=PreinvoiceOrder::query()->create($base+['uuid'=>fake()->uuid(),'created_by'=>$technical->id,'seller_id'=>null]);
    $invoice=Invoice::query()->create(['uuid'=>fake()->uuid(),'preinvoice_order_id'=>$owned->id,'seller_id'=>null,'total'=>1,'subtotal'=>1]);
    $migration=require database_path('migrations/2026_08_01_200000_add_seller_ownership_to_sales_documents.php');
    $migration->up(); $migration->up();
    expect($owned->fresh()->seller_id)->toBe($seller->id)
        ->and($invoice->fresh()->seller_id)->toBe($seller->id)
        ->and($unknown->fresh()->seller_id)->toBeNull();
});
