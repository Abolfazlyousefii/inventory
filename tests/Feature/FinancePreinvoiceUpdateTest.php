<?php

use App\Models\CustomerLedger;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\PreinvoiceOrderReview;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function financeEditorUser(): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('finance', 'web');
    $user->assignRole($role);

    return $user;
}

function financeEditableOrderFixture(): array
{
    $category = Category::query()->create(['name' => 'Finance category '.uniqid()]);

    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Finance product',
        'sku' => 'FIN-PRODUCT-'.uniqid(),
        'stock' => 50,
        'reserved' => 0,
        'price' => 12_000_000,
    ]));

    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Default',
        'variant_code' => 'FIN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'sell_price' => 12_000_000,
        'stock' => 50,
        'reserved' => 0,
    ]));

    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'finance-edit-'.uniqid(),
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'مشتری مالی',
        'shipping_price' => 0,
        'discount_amount' => 500_000,
        'total_price' => 19_500_000,
        'discount_allocation_mode' => 'product_lines',
    ]);

    $item = PreinvoiceOrderItem::query()->create([
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 10_000_000,
        'line_discount_amount' => 500_000,
        'line_total' => 19_500_000,
    ]);

    return compact('product', 'variant', 'order', 'item');
}

it('saves finance edits behaviorally without finalizing or creating invoices', function () {
    ['product' => $product, 'order' => $order, 'item' => $item] = financeEditableOrderFixture();
    $user = financeEditorUser();
    $oldStatus = $order->status;

    $response = $this->actingAs($user)->put(route('preinvoice.draft.finance.update', $order->uuid), [
        'intent' => 'save',
        'action' => 'save',
        'items' => [[
            'id' => $item->id,
            'quantity' => 2,
            'price' => '11,000,000',
        ]],
        'product_discounts' => [[
            'product_id' => $product->id,
            'type' => 'percent',
            'value' => '10',
        ]],
        'invoice_discount_type' => 'percent',
        'invoice_discount_value' => '5',
        'edit_reason' => 'اصلاح تست مالی',
    ]);

    $response->assertRedirect(route('preinvoice.draft.finance.edit', $order->uuid));

    $item->refresh();
    $order->refresh();

    expect($item->quantity)->toBe(2)
        ->and($item->price)->toBe(11_000_000)
        ->and($item->line_discount_amount)->toBe(2_200_000)
        ->and($item->line_total)->toBe(19_800_000)
        ->and($order->status)->toBe($oldStatus)
        ->and($order->product_discount_amount)->toBe(2_200_000)
        ->and($order->invoice_discount_type)->toBe('percent')
        ->and($order->invoice_discount_value)->toBe(5)
        ->and($order->invoice_discount_amount)->toBe(990_000)
        ->and($order->discount_amount)->toBe(3_190_000)
        ->and($order->total_price)->toBe(18_810_000)
        ->and($order->discount_breakdown['groups'][0]['discount_type'])->toBe('percent')
        ->and($order->discount_breakdown['groups'][0]['discount_value'])->toBe(10)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceItem::query()->count())->toBe(0)
        ->and(CustomerLedger::query()->count())->toBe(0)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->where('action', 'finance_edited')->exists())->toBeTrue();
});

it('keeps request intent and action contracts aligned with save and save_and_finalize', function () {
    $rules = (new App\Http\Requests\FinanceUpdatePreinvoiceRequest())->rules();

    expect($rules['intent'])->toContain('in:save,save_and_finalize')
        ->and($rules['action'])->toContain('in:save,save_and_finalize');
});
