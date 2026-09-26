<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InvoiceReapprovalRevisionRecorder;
use App\Services\SalesHavalehService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('stores real before and after rows for a removed item and an added item', function () {
    $actor = User::factory()->create();
    $category = Category::query()->create(['name' => 'Revision items']);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای بازبینی',
        'sku' => 'REV-ITEM-1',
        'stock' => 10,
        'price' => 5000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع بازبینی',
        'variant_code' => 'REV-VARIANT-1',
        'sell_price' => 5000,
        'stock' => 10,
    ]);
    $invoice = Invoice::query()->create([
        'uuid' => 'REV-RECORD-1',
        'customer_name' => 'مشتری بازبینی',
        'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
        'total' => 10000,
    ]);
    $old = InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 5000,
        'line_discount_amount' => 0,
    ]);
    $before = $invoice->items()->with(['product', 'variant'])->get()->map(fn ($item) => clone $item);
    $old->delete();
    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'price' => 8000,
        'line_discount_amount' => 0,
    ]);

    app(InvoiceReapprovalRevisionRecorder::class)->record(
        $invoice, $before, $invoice->items()->with(['product', 'variant'])->get(),
        10000, 8000, 'other', 'تعویض کالا', $actor->id,
    );

    $revision = DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->first();
    $rows = DB::table('invoice_collection_revision_items')->where('invoice_collection_revision_id', $revision->id)->orderBy('id')->get();
    expect((int) $revision->old_total)->toBe(10000)
        ->and((int) $revision->new_total)->toBe(8000)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->change_type)->toBe('removed')
        ->and((int) $rows[0]->old_quantity)->toBe(2)
        ->and($rows[0]->new_quantity)->toBeNull()
        ->and($rows[1]->change_type)->toBe('added')
        ->and($rows[1]->old_quantity)->toBeNull()
        ->and((int) $rows[1]->new_quantity)->toBe(1);
});

it('records a structured revision during the actual sales voucher edit', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($actor);
    $category = Category::query()->create(['name' => 'Voucher revision']);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'کالای حواله',
        'sku' => 'REV-VOUCHER-1',
        'stock' => 10,
        'price' => 5000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'تنوع حواله',
        'variant_code' => 'REV-VOUCHER-VARIANT',
        'sell_price' => 5000,
        'stock' => 10,
    ]);
    $invoice = Invoice::query()->create([
        'uuid' => 'REV-VOUCHER-INVOICE',
        'customer_name' => 'مشتری حواله',
        'status' => Invoice::STATUS_PENDING_COLLECTION,
        'total' => 10000,
        'subtotal' => 10000,
    ]);
    $item = InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 5000,
        'line_discount_amount' => 0,
    ]);

    app(SalesHavalehService::class)->updateItemsForInvoice($invoice, [
        ['id' => $item->id, 'quantity' => 2, 'price' => 6000],
    ], $actor->id, 'price_correction', 'اصلاح قیمت');

    $revision = DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->first();
    $row = DB::table('invoice_collection_revision_items')->where('invoice_collection_revision_id', $revision->id)->first();
    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
        ->and((int) $revision->old_total)->toBe(10000)
        ->and((int) $revision->new_total)->toBe(12000)
        ->and((int) $row->old_price)->toBe(5000)
        ->and((int) $row->new_price)->toBe(6000);
});
