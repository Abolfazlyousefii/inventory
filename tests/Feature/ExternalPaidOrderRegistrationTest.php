<?php

use App\Models\Category;
use App\Models\City;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Province;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.external_sync.token' => 'shared-secret']);
    Http::fake();
    // The recovered baseline predates the production bundle snapshot column.
    if (!\Illuminate\Support\Facades\Schema::hasColumn('invoice_items', 'bundle_components_snapshot')) {
        \Illuminate\Support\Facades\Schema::table('invoice_items', fn ($table) => $table->json('bundle_components_snapshot')->nullable());
    }
});

function externalPaidOrderCatalog(int $stock = 10): array
{
    $category = Category::query()->create(['name' => 'لوازم جانبی']);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'قاب گوشی',
        'sku' => 'CASE-100',
        'price' => 100_000,
        'stock' => $stock,
        'external_id' => '700',
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'variant_name' => 'iPhone 16',
        'variety_name' => 'مشکی',
        'variant_code' => 'CASE-100-BLK',
        'variety_id' => 9001,
        'sell_price' => 100_000,
        'stock' => $stock,
        'is_active' => true,
        'sales_enabled' => true,
    ]);
    $warehouseId = WarehouseStockService::centralWarehouseId();
    WarehouseStock::query()->create([
        'warehouse_id' => $warehouseId,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $stock,
    ]);

    return [$product, $variant, $warehouseId];
}

function externalPaidOrderPayload(array $overrides = []): array
{
    $province = Province::query()->firstOrCreate(['name' => 'تهران']);
    $city = City::query()->firstOrCreate([
        'province_id' => $province->id,
        'name' => 'تهران',
    ]);

    return array_replace_recursive([
        'event' => 'order.paid',
        'currency' => 'IRR',
        'crm_order_id' => '4242',
        'occurred_at' => '2026-07-27T12:30:00+03:30',
        'user' => [
            'id' => 81,
            'crm_user_id' => '81',
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'name' => 'علی رضایی',
            'mobile' => '09121234567',
            'password_hash' => 'must-not-be-stored',
        ],
        'shipping_address' => [
            'name' => 'علی رضایی',
            'mobile' => '09121234567',
            'province_id' => $province->id,
            'province' => ['id' => $province->id, 'name' => 'تهران'],
            'city_id' => $city->id,
            'city' => ['id' => $city->id, 'name' => 'تهران'],
            'postal_code' => '1234567890',
            'address' => 'تهران، خیابان مثال، پلاک ۱',
        ],
        'order' => [
            'id' => 4242,
            'shipping_price' => 20_000,
            'total' => 210_000,
            'transactions' => [
                ['id' => 501, 'tracking_code' => 'TRACK-501'],
            ],
        ],
        'items' => [
            [
                'id' => 301,
                'product_id' => 700,
                'quantity' => 2,
                'price' => 100_000,
                'line_total' => 200_000,
                'discount_amount' => 10_000,
                'product' => ['id' => 700, 'name' => 'قاب گوشی'],
                'price_variant' => [
                    'id' => 9001,
                    'variant_name' => 'iPhone 16',
                    'variety_name' => 'مشکی',
                ],
            ],
        ],
    ], $overrides);
}

it('converts default site toman consistently to rial and does not reconvert duplicate imports', function (): void {
    externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload();
    unset($payload['currency']);
    $this->withHeader('X-CRM-Token', 'shared-secret')->postJson('/api/external/orders', $payload)->assertCreated();
    $invoice = Invoice::with(['items', 'payments'])->sole();
    expect($invoice->total)->toBe(2_100_000)
        ->and($invoice->subtotal)->toBe(2_000_000)
        ->and($invoice->shipping_price)->toBe(200_000)
        ->and($invoice->items->sole()->price)->toBe(1_000_000)
        ->and($invoice->items->sole()->line_discount_amount)->toBe(100_000)
        ->and($invoice->payments->sole()->amount)->toBe(2_100_000);
    expect((int) \App\Models\CustomerLedger::where('type', 'debit')->sum('amount'))->toBe(2_100_000);
    expect((int) \App\Models\CustomerLedger::where('type', 'credit')->sum('amount'))->toBe(2_100_000);
    $this->postJson('/api/external/orders', $payload)->assertOk()->assertJsonPath('created', false);
    expect(Invoice::count())->toBe(1);
    expect($invoice->fresh()->total)->toBe(2_100_000);
});

it('rejects conflicting currency and monetary overflow without importing any financial rows', function (): void {
    externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload(['currency' => 'IRT', 'order' => ['currency' => 'IRR']]);
    $this->withHeader('X-CRM-Token', 'shared-secret')->postJson('/api/external/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('currency');
    unset($payload['order']['currency']);
    $payload['items'][0]['price'] = 1_000_000_000_000_000;
    $this->postJson('/api/external/orders', $payload)->assertUnprocessable();
    expect(Invoice::count())->toBe(0);
    expect(\App\Models\CustomerLedger::count())->toBe(0);
});

it('records ten million site tomans as one hundred million rials', function (): void {
    externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload();
    unset($payload['currency']);
    $payload['items'][0]['quantity'] = 1;
    $payload['items'][0]['price'] = 10_000_000;
    $payload['items'][0]['discount_amount'] = 0;
    $payload['items'][0]['line_total'] = 10_000_000;
    $payload['order']['shipping_price'] = 0;
    $payload['order']['total'] = 10_000_000;
    $this->withHeader('X-CRM-Token', 'shared-secret')->postJson('/api/external/orders', $payload)->assertCreated();
    $invoice = Invoice::with('payments')->sole();
    expect($invoice->total)->toBe(100_000_000)
        ->and($invoice->payments->sole()->amount)->toBe(100_000_000);
    expect((int) \App\Models\CustomerLedger::where('type', 'credit')->sum('amount'))->toBe(100_000_000);
});

it('accepts an explicit nested toman currency and keeps invoice-wide discounts and shipping in rial', function (): void {
    externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload(['order' => ['currency' => 'IRT', 'total' => 200_000]]);
    unset($payload['currency']);
    $this->withHeader('X-CRM-Token', 'shared-secret')->postJson('/api/external/orders', $payload)->assertCreated();
    $invoice = Invoice::sole();
    expect($invoice->total)->toBe(2_000_000)
        ->and($invoice->invoice_discount_amount)->toBe(100_000)
        ->and($invoice->product_discount_amount)->toBe(100_000)
        ->and($invoice->shipping_price)->toBe(200_000);
});

it('registers a paid store order with its variant in collecting status', function (): void {
    [$product, $variant, $warehouseId] = externalPaidOrderCatalog();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer shared-secret',
        'X-CRM-Token' => 'shared-secret',
    ])->postJson('/api/external/orders', externalPaidOrderPayload());

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('crm_order_id', '4242')
        ->assertJsonPath('status', Invoice::STATUS_COLLECTING);

    $invoice = Invoice::query()->with(['items', 'payments'])->sole();
    $item = $invoice->items->sole();

    expect($invoice->external_order_id)->toBe(4242)
        ->and($invoice->status)->toBe(Invoice::STATUS_COLLECTING)
        ->and($invoice->collection_started_at)->not->toBeNull()
        ->and($invoice->warehouse_received_at)->not->toBeNull()
        ->and($invoice->subtotal)->toBe(200_000)
        ->and($invoice->product_discount_amount)->toBe(10_000)
        ->and($invoice->shipping_price)->toBe(20_000)
        ->and($invoice->total)->toBe(210_000)
        ->and($item->product_id)->toBe($product->id)
        ->and($item->variant_id)->toBe($variant->id)
        ->and($item->quantity)->toBe(2)
        ->and($item->price)->toBe(100_000)
        ->and($item->line_discount_amount)->toBe(10_000)
        ->and($item->line_total)->toBe(190_000)
        ->and($invoice->payments)->toHaveCount(1)
        ->and($invoice->payments->first()->method)->toBe('online')
        ->and($invoice->payments->first()->amount)->toBe(210_000)
        ->and($invoice->payments->first()->payment_identifier)->toBe('TRACK-501');

    $customer = Customer::query()->sole();
    expect($customer->crm_customer_id)->toBe('81')
        ->and($customer->mobile)->toBe('09121234567')
        ->and($customer->address)->toBe('تهران، خیابان مثال، پلاک ۱')
        ->and($customer->last_crm_payload)->not->toHaveKey('password_hash');

    expect(
        WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variant->id)
            ->value('quantity')
    )->toBe(8)
        ->and($variant->fresh()->stock)->toBe(8)
        ->and($product->fresh()->stock)->toBe(8);
});

it('is idempotent and does not consume stock or create payments twice', function (): void {
    [, $variant, $warehouseId] = externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload();

    $this->withHeader('X-CRM-Token', 'shared-secret')
        ->postJson('/api/external/orders', $payload)
        ->assertCreated();

    $this->withHeader('X-CRM-Token', 'shared-secret')
        ->postJson('/api/external/orders', $payload)
        ->assertOk()
        ->assertJsonPath('created', false);

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1)
        ->and(Invoice::query()->firstOrFail()->payments()->count())->toBe(1)
        ->and(
            WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variant->id)
                ->value('quantity')
        )->toBe(8);
});

it('rolls the whole import back when the store variant cannot be resolved', function (): void {
    [, $variant, $warehouseId] = externalPaidOrderCatalog();
    $payload = externalPaidOrderPayload([
        'items' => [
            [
                'price_variant' => ['id' => 999999],
            ],
        ],
    ]);

    $this->withHeader('X-CRM-Token', 'shared-secret')
        ->postJson('/api/external/orders', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.price_variant');

    expect(Invoice::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(
            WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variant->id)
                ->value('quantity')
        )->toBe(10);
});

it('rolls the whole import back when variant stock is insufficient', function (): void {
    [, $variant, $warehouseId] = externalPaidOrderCatalog(1);

    $this->withHeader('X-CRM-Token', 'shared-secret')
        ->postJson('/api/external/orders', externalPaidOrderPayload())
        ->assertUnprocessable();

    expect(Invoice::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(
            WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variant->id)
                ->value('quantity')
        )->toBe(1);
});

it('rejects paid order requests with an invalid token', function (): void {
    externalPaidOrderCatalog();

    $this->withHeader('X-CRM-Token', 'wrong-token')
        ->postJson('/api/external/orders', externalPaidOrderPayload())
        ->assertUnauthorized();

    expect(Invoice::query()->count())->toBe(0);
});
