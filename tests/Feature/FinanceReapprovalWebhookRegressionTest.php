<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryWebhookSetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Support\SalesDocumentTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function reapprovalActor(): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $user->assignRole(Role::findOrCreate('Owner', 'web'));

    return $user;
}

function reapprovalInvoice(int $quantity = 2, int $price = 5000): Invoice
{
    $category = Category::create(['name' => 'دسته تایید مجدد']);

    $product = Product::create([
        'category_id' => $category->id,
        'name' => 'کالای تایید مجدد',
        'sku' => 'REAPPROVE-' . Str::random(6),
        'stock' => 100,
        'price' => $price,
        'is_sellable' => true,
    ]);

    $customer = Customer::create([
        'first_name' => 'مشتری',
        'last_name' => 'تست',
        'mobile' => '0912' . random_int(1000000, 9999999),
        'opening_balance' => 0,
        'is_active' => true,
    ]);

    $lineTotal = $quantity * $price;

    $invoice = Invoice::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'customer_name' => 'مشتری تست',
        'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
        'subtotal' => $lineTotal,
        'product_discount_amount' => 0,
        'invoice_discount_amount' => 0,
        'discount_amount' => 0,
        'total' => $lineTotal,
        'paid_amount' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'quantity' => $quantity,
        'price' => $price,
    ]);

    $invoice->load('items');
    $totals = SalesDocumentTotals::fromDocument($invoice);
    $invoice->update([
        'discount_breakdown' => SalesDocumentTotals::canonicalBreakdown($invoice, $totals),
    ]);

    return $invoice->fresh('items');
}

function enableInventoryWebhook(): InventoryWebhookSetting
{
    return InventoryWebhookSetting::create([
        'endpoint_url' => 'https://crm.example.test/hooks/inventory',
        'is_enabled' => true,
        'timeout_seconds' => 5,
        'secret' => 'test-secret',
    ]);
}

it('moves the invoice from pending finance reapproval to ready to ship', function (): void {
    Http::fake();
    $invoice = reapprovalInvoice();

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_READY_TO_SHIP);
});

it('does not change invoice items or payments during finance reapproval', function (): void {
    Http::fake();
    $invoice = reapprovalInvoice();

    $itemsBefore = $invoice->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total'])->toArray();
    $paidBefore = (int) $invoice->paid_amount;
    $totalBefore = (int) $invoice->total;

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertRedirect();

    $fresh = $invoice->fresh('items');

    expect($fresh->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total'])->toArray())->toBe($itemsBefore)
        ->and((int) $fresh->paid_amount)->toBe($paidBefore)
        ->and((int) $fresh->total)->toBe($totalBefore);
});

it('does not move any stock during finance reapproval', function (): void {
    Http::fake();
    $invoice = reapprovalInvoice();

    $movementsBefore = DB::table('stock_movements')->count();
    $stocksBefore = DB::table('warehouse_stocks')->sum('quantity');

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertRedirect();

    expect(DB::table('stock_movements')->count())->toBe($movementsBefore)
        ->and(DB::table('warehouse_stocks')->sum('quantity'))->toBe($stocksBefore);
});

it('sends the invoice.collection.completed webhook with the documented payload', function (): void {
    Http::fake();
    enableInventoryWebhook();
    $invoice = reapprovalInvoice(2, 5000);

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertRedirect();

    Http::assertSent(function ($request) use ($invoice): bool {
        $body = $request->data();

        if (($body['event'] ?? null) !== 'invoice.collection.completed') {
            return false;
        }

        $payload = $body['payload'] ?? [];

        return array_key_exists('invoice_id', $payload)
            && array_key_exists('external_order_id', $payload)
            && array_key_exists('crm_customer_id', $payload)
            && array_key_exists('total', $payload)
            && array_key_exists('paid_amount', $payload)
            && array_key_exists('credit_amount', $payload)
            && array_key_exists('collection_adjustment_id', $payload)
            && (int) $payload['invoice_id'] === $invoice->id
            && (int) $payload['total'] === 10000
            && (int) $payload['paid_amount'] === 0
            && (int) $payload['credit_amount'] === 0
            && $payload['collection_adjustment_id'] === 'invoice-' . $invoice->id;
    });
});

it('never performs the outbound webhook request while a database transaction is still open', function (): void {
    // Under RefreshDatabase the suite itself holds one wrapping transaction,
    // so the ambient level - not zero - is the "nothing of ours is open" baseline.
    $ambientLevel = DB::transactionLevel();

    $transactionLevels = [];

    Http::fake(function () use (&$transactionLevels) {
        $transactionLevels[] = DB::transactionLevel();

        return Http::response(['ok' => true], 200);
    });

    enableInventoryWebhook();
    $invoice = reapprovalInvoice();

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertRedirect();

    expect($transactionLevels)->not->toBeEmpty()
        ->and($transactionLevels)->each->toBe($ambientLevel);
});

it('sends no webhook when finance reapproval is rejected before commit', function (): void {
    Http::fake();
    enableInventoryWebhook();

    $invoice = reapprovalInvoice();
    // Break the stored total so the canonical integrity check rejects the document.
    DB::table('invoices')->where('id', $invoice->id)->update(['total' => 999999]);

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid));

    Http::assertNothingSent();

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PENDING_FINANCE_REAPPROVAL);
});

it('rejects finance reapproval from an invalid status', function (): void {
    Http::fake();
    $invoice = reapprovalInvoice();
    $invoice->update(['status' => Invoice::STATUS_READY_TO_SHIP]);

    $this->actingAs(reapprovalActor())
        ->post(route('finance.invoices.reapprove', $invoice->uuid))
        ->assertStatus(422);

    Http::assertNothingSent();
});
