<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

function multiShortfallUser(array $permissions): User
{
    $role = Role::findOrCreate('multi-shortfall-'.Str::random(10), 'web');

    foreach ($permissions as $key) {
        $permission = Permission::query()->where('key', $key)->first()
            ?? Permission::findOrCreate($key, 'web');

        if (($permission->key ?? null) !== $key) {
            $permission->forceFill(['key' => $key])->save();
        }

        $role->givePermissionTo($permission);
    }

    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['is_seller' => true])->save();

    return $user;
}

/** @return array{product:Product,variant:ProductVariant} */
function multiShortfallItem(string $label, int $centralQuantity): array
{
    $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Multi shortfall '.Str::uuid()]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Product '.$label,
        'sku' => 'MS-'.Str::uuid(),
        'code' => (string) random_int(100000, 999999),
        'stock' => $centralQuantity,
        'reserved' => 0,
        'price' => 1000,
        'is_sellable' => true,
    ]));
    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Variant '.$label,
        'variant_code' => 'MSV-'.Str::uuid(),
        'sell_price' => 1000,
        'stock' => $centralQuantity,
        'reserved' => 0,
    ]));
    WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'quantity' => $centralQuantity,
    ]));

    return compact('product', 'variant');
}

function multiShortfallSyncPayload(string $token, array $lines): array
{
    return [
        'reservation_token' => $token,
        'submission_token' => $token,
        'is_in_person' => false,
        'items' => array_map(fn (array $line) => [
            'product_id' => $line[0]['product']->id,
            'variant_id' => $line[0]['variant']->id,
            'quantity' => $line[1],
        ], $lines),
    ];
}

function multiShortfallCentral(array $item): int
{
    return (int) WarehouseStock::query()
        ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
        ->where('product_variant_id', $item['variant']->id)
        ->value('quantity');
}

/** Every stock and reservation row, for before/after equality checks. */
function multiShortfallStockSnapshot(): array
{
    return [
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get(['id', 'product_id', 'product_variant_id', 'quantity'])->map(fn ($r) => (array) $r)->all(),
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'stock_movements' => DB::table('stock_movements')->count(),
    ];
}

it('reports all three short items of one sync in a single error', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $a = multiShortfallItem('A', 1);
    $b = multiShortfallItem('B', 2);
    $c = multiShortfallItem('C', 0);
    $before = multiShortfallStockSnapshot();

    $response = $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload((string) Str::uuid(), [[$a, 5], [$b, 6], [$c, 3]]))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonCount(3, 'item_errors')
        ->assertJsonCount(3, 'errors.items');

    expect(collect($response->json('item_errors'))->pluck('variant_id')->sort()->values()->all())
        ->toBe(collect([$a, $b, $c])->map(fn ($i) => $i['variant']->id)->sort()->values()->all());

    foreach ($response->json('item_errors') as $error) {
        expect($error)->toHaveKeys([
            'product_id', 'variant_id', 'product_name', 'product_code', 'variant_name', 'variant_code',
            'available_quantity', 'requested_quantity', 'max_allowed', 'message',
        ]);
    }

    expect(multiShortfallStockSnapshot())->toBe($before);
});

it('keeps the single-shortfall sync error exactly as before', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $ok = multiShortfallItem('OK', 10);
    $short = multiShortfallItem('SHORT', 2);
    $before = multiShortfallStockSnapshot();
    $variantName = $short['variant']->variant_name;
    $productName = $short['product']->name;
    $legacyMessage = "موجودی «{$variantName}» ({$productName}) کافی نیست. موجودی: 2 | درخواست: 5";

    $response = $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload((string) Str::uuid(), [[$ok, 3], [$short, 5]]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'موجودی یک یا چند قلم برای رزرو کافی نیست.')
        ->assertJsonPath('errors', ['items' => [$legacyMessage]])
        ->assertJsonCount(1, 'item_errors');

    // Legacy keys and values are untouched; max_allowed is the only addition.
    expect($response->json('item_errors.0'))->toBe([
        'product_id' => $short['product']->id,
        'variant_id' => $short['variant']->id,
        'product_name' => $productName,
        'product_code' => $short['product']->code,
        'variant_name' => $variantName,
        'variant_code' => '',
        'available_quantity' => 2,
        'requested_quantity' => 5,
        'max_allowed' => 2,
        'message' => $legacyMessage,
    ]);

    // All or nothing: the in-stock line was not reserved either.
    expect(multiShortfallStockSnapshot())->toBe($before);
});

it('reserves normally with an unchanged database footprint when every item is in stock', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $a = multiShortfallItem('A', 10);
    $b = multiShortfallItem('B', 4);
    $c = multiShortfallItem('C', 7);
    $token = (string) Str::uuid();
    $movementsBefore = DB::table('stock_movements')->count();

    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload($token, [[$a, 3], [$b, 4], [$c, 1]]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonCount(3, 'data.reserved')
        ->assertJsonPath('data.reservation_scope', 'temporary_online')
        ->assertJsonMissingPath('item_errors')
        ->assertJsonMissingPath('suggested_items');

    expect(multiShortfallCentral($a))->toBe(7)
        ->and(multiShortfallCentral($b))->toBe(0)
        ->and(multiShortfallCentral($c))->toBe(6)
        // Temporary reservation moves central stock without a movement row (pre-change behavior).
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore);

    $rows = PreinvoiceDraftReservation::query()->where('token', $token)->orderBy('variant_id')->get();
    expect($rows)->toHaveCount(3);
    foreach ([[$a, 3], [$b, 4], [$c, 1]] as [$item, $qty]) {
        $row = $rows->firstWhere('variant_id', $item['variant']->id);
        expect($row->quantity)->toBe($qty)
            ->and((int) $row->user_id)->toBe($seller->id)
            ->and($row->reservation_scope)->toBe('temporary_online')
            ->and($row->preinvoice_order_id)->toBeNull()
            ->and($row->converted_at)->toBeNull()
            ->and($row->released_at)->toBeNull()
            ->and($row->release_reason)->toBeNull()
            ->and($row->expires_at)->not->toBeNull();
    }

    // Re-sending the same list is a no-op, exactly as before.
    $snapshot = multiShortfallStockSnapshot();
    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload($token, [[$a, 3], [$b, 4], [$c, 1]]))
        ->assertOk();
    $after = multiShortfallStockSnapshot();
    expect($after['warehouse_stocks'])->toBe($snapshot['warehouse_stocks'])
        ->and($after['stock_movements'])->toBe($snapshot['stock_movements']);
});

it('computes max_allowed as the reservable total for the line', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $held = multiShortfallItem('HELD', 5);
    $fresh = multiShortfallItem('FRESH', 4);
    $token = (string) Str::uuid();

    // The line already holds 3; 2 remain free in central stock.
    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload($token, [[$held, 3]]))
        ->assertOk();

    $response = $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload($token, [[$held, 10], [$fresh, 9]]))
        ->assertStatus(422)
        ->assertJsonCount(2, 'item_errors');

    $errors = collect($response->json('item_errors'))->keyBy('variant_id');
    expect($errors[$held['variant']->id]['available_quantity'])->toBe(2)
        ->and($errors[$held['variant']->id]['requested_quantity'])->toBe(7)
        ->and($errors[$held['variant']->id]['max_allowed'])->toBe(5)
        ->and($errors[$fresh['variant']->id]['available_quantity'])->toBe(4)
        ->and($errors[$fresh['variant']->id]['max_allowed'])->toBe(4);

    // Syncing exactly max_allowed succeeds.
    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload($token, [[$held, 5], [$fresh, 4]]))
        ->assertOk();
});

it('returns suggested_items lowered to max_allowed and flags zero lines for removal', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $ok = multiShortfallItem('OK', 10);
    $partial = multiShortfallItem('PARTIAL', 3);
    $empty = multiShortfallItem('EMPTY', 0);

    $response = $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), multiShortfallSyncPayload((string) Str::uuid(), [[$ok, 2], [$partial, 5], [$empty, 2]]))
        ->assertStatus(422)
        ->assertJsonCount(3, 'suggested_items')
        // suggested_items must not leak into the flattened error text.
        ->assertJsonMissingPath('errors.suggested_items');

    $suggested = collect($response->json('suggested_items'))->keyBy('variant_id');
    expect($suggested[$ok['variant']->id])->toMatchArray(['quantity' => 2, 'requested_quantity' => 2, 'remove' => false])
        ->and($suggested[$partial['variant']->id])->toMatchArray(['quantity' => 3, 'requested_quantity' => 5, 'remove' => false])
        ->and($suggested[$empty['variant']->id])->toMatchArray(['quantity' => 0, 'requested_quantity' => 2, 'remove' => true]);

    // Applying the suggestion (dropping removed lines) syncs cleanly.
    $fixed = collect($response->json('suggested_items'))->reject(fn ($row) => $row['remove'])
        ->map(fn ($row) => ['product_id' => $row['product_id'], 'variant_id' => $row['variant_id'], 'quantity' => $row['quantity']])
        ->values()->all();
    $token = (string) Str::uuid();
    $this->actingAs($seller)
        ->postJson(route('preinvoice.api.reservations.sync'), ['reservation_token' => $token, 'submission_token' => $token, 'is_in_person' => false, 'items' => $fixed])
        ->assertOk();
});

it('returns every shortfall with suggested_items on final submit before writing anything', function () {
    $seller = multiShortfallUser(['page.sales.preinvoices']);
    $ok = multiShortfallItem('OK', 10);
    $b = multiShortfallItem('B', 1);
    $c = multiShortfallItem('C', 0);
    $before = multiShortfallStockSnapshot();
    $ordersBefore = PreinvoiceOrder::query()->count();
    $lines = [[$ok, 2], [$b, 4], [$c, 3]];

    $payloadRows = array_map(fn (array $line) => [
        'item_id' => null,
        'id' => $line[0]['product']->id,
        'product_id' => $line[0]['product']->id,
        'variety_id' => $line[0]['variant']->id,
        'variant_id' => $line[0]['variant']->id,
        'quantity' => $line[1],
        'price' => 1000,
        'line_discount_amount' => 0,
    ], $lines);

    $response = $this->actingAs($seller)->postJson(route('preinvoice.draft.save'), [
        'intent' => 'submit',
        'customer_name' => 'Multi shortfall customer',
        'customer_mobile' => '09120000000',
        'is_in_person' => 0,
        'discount_amount' => 0,
        'products_payload' => json_encode($payloadRows, JSON_THROW_ON_ERROR),
        'products_payload_count' => 3,
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => 9,
        'products_payload_gross_total' => 9000,
    ])->assertStatus(422)->assertJsonCount(2, 'item_errors')->assertJsonCount(3, 'suggested_items');

    $errors = collect($response->json('item_errors'))->keyBy('variant_id');
    expect($errors[$b['variant']->id]['max_allowed'])->toBe(1)
        ->and($errors[$c['variant']->id]['max_allowed'])->toBe(0);

    $suggested = collect($response->json('suggested_items'))->keyBy('variant_id');
    expect($suggested[$ok['variant']->id])->toMatchArray(['quantity' => 2, 'remove' => false])
        ->and($suggested[$b['variant']->id])->toMatchArray(['quantity' => 1, 'remove' => false])
        ->and($suggested[$c['variant']->id])->toMatchArray(['quantity' => 0, 'remove' => true]);

    expect(multiShortfallStockSnapshot())->toBe($before)
        ->and(PreinvoiceOrder::query()->count())->toBe($ordersBefore);
});

it('reports every shortfall on finance finalize before any stock or reservation write', function () {
    $finance = multiShortfallUser(['page.sales.preinvoice_finance_review']);
    $covered = multiShortfallItem('COVERED', 10);
    $b = multiShortfallItem('B', 1);
    $c = multiShortfallItem('C', 2);

    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->forceCreate([
        'uuid' => (string) random_int(10000, 99999),
        'created_by' => $finance->id,
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'Finance shortfall customer',
        'customer_mobile' => '09120000000',
        'shipping_price' => 0,
        'discount_amount' => 0,
        'total_price' => 11 * 1000,
        'stock_frozen_until' => now()->addHours(3),
    ]));
    foreach ([[$covered, 2], [$b, 5], [$c, 4]] as $i => [$item, $qty]) {
        $order->items()->create([
            'product_id' => $item['product']->id,
            'variant_id' => $item['variant']->id,
            'quantity' => $qty,
            'price' => 1000,
            'line_total' => $qty * 1000,
            'line_discount_amount' => 0,
            'sort_order' => $i + 1,
        ]);
    }
    // Official rows match every line (finance approvable), but the reserved
    // projection of B and C has drifted to 0, so finalize must re-reserve them
    // from central stock, which is too low for both.
    foreach ([[$covered, 2], [$b, 5], [$c, 4]] as [$item, $qty]) {
        PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $finance->id,
            'preinvoice_order_id' => $order->id,
            'product_id' => $item['product']->id,
            'variant_id' => $item['variant']->id,
            'quantity' => $qty,
            'expires_at' => now()->addHours(3),
            'reservation_tier' => 'normal',
            'reservation_scope' => 'official',
        ]);
    }
    $covered['variant']->forceFill(['reserved' => 2])->saveQuietly();

    $before = multiShortfallStockSnapshot();

    $this->actingAs($finance)
        ->from(route('preinvoice.draft.finance', $order->uuid))
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertRedirect(route('preinvoice.draft.finance', $order->uuid))
        ->assertSessionHasErrors('products');

    $messages = session('errors')->get('products');
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain($b['product']->name)
        ->and($messages[1])->toContain($c['product']->name)
        ->and(session('preinvoice_item_errors'))->toHaveCount(2)
        ->and(collect(session('preinvoice_suggested_items'))->keyBy('variant_id')[$c['variant']->id])
        ->toMatchArray(['quantity' => 2, 'remove' => false]);

    expect(multiShortfallStockSnapshot())->toBe($before)
        ->and(Invoice::query()->where('preinvoice_order_id', $order->id)->exists())->toBeFalse()
        ->and($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE);
});

/**
 * Pending-finance order whose official rows match every line (finance approvable).
 * Lines listed in $projected keep a matching reserved projection; the rest have
 * drifted to 0, so finalize must re-reserve them from central stock.
 */
function multiShortfallFinanceOrder(User $finance, array $lines, array $projected): PreinvoiceOrder
{
    $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->forceCreate([
        'uuid' => (string) random_int(10000, 99999),
        'created_by' => $finance->id,
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'Finance shortfall customer',
        'customer_mobile' => '09120000000',
        'shipping_price' => 0,
        'discount_amount' => 0,
        'total_price' => array_sum(array_column($lines, 1)) * 1000,
        'stock_frozen_until' => now()->addHours(3),
    ]));

    foreach ($lines as $i => [$item, $qty]) {
        $order->items()->create([
            'product_id' => $item['product']->id,
            'variant_id' => $item['variant']->id,
            'quantity' => $qty,
            'price' => 1000,
            'line_total' => $qty * 1000,
            'line_discount_amount' => 0,
            'sort_order' => $i + 1,
        ]);
        PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $finance->id,
            'preinvoice_order_id' => $order->id,
            'product_id' => $item['product']->id,
            'variant_id' => $item['variant']->id,
            'quantity' => $qty,
            'expires_at' => now()->addHours(3),
            'reservation_tier' => 'normal',
            'reservation_scope' => 'official',
        ]);
        if (in_array($i, $projected, true)) {
            $item['variant']->forceFill(['reserved' => $qty])->saveQuietly();
        }
    }

    return $order;
}

it('keeps the legacy message for a single finance shortfall and still returns suggested_items', function () {
    $finance = multiShortfallUser(['page.sales.preinvoice_finance_review']);
    $covered = multiShortfallItem('COVERED', 10);
    $short = multiShortfallItem('SHORT', 1);
    $order = multiShortfallFinanceOrder($finance, [[$covered, 2], [$short, 5]], [0]);
    $before = multiShortfallStockSnapshot();

    // Exact text reserveStockForItem produced before this change.
    $legacyMessage = "موجودی کافی برای ثبت نهایی وجود ندارد. کالا: {$short['product']->name} | تنوع: {$short['variant']->variant_name} | تعداد درخواستی: 5 | موجودی قابل فروش: 1";

    $this->actingAs($finance)
        ->from(route('preinvoice.draft.finance', $order->uuid))
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertRedirect(route('preinvoice.draft.finance', $order->uuid));

    expect(session('errors')->getBag('default')->toArray())->toBe(['products' => [$legacyMessage]])
        ->and(session('preinvoice_item_errors'))->toHaveCount(1)
        ->and(session('preinvoice_item_errors.0.max_allowed'))->toBe(1);
    $suggested = collect(session('preinvoice_suggested_items'))->keyBy('variant_id');
    expect($suggested)->toHaveCount(2)
        ->and($suggested[$covered['variant']->id])->toMatchArray(['quantity' => 2, 'remove' => false])
        ->and($suggested[$short['variant']->id])->toMatchArray(['quantity' => 1, 'requested_quantity' => 5, 'remove' => false]);

    // JSON clients get the same message/errors a single-message ValidationException gave.
    $this->actingAs($finance)
        ->postJson(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertStatus(422)
        ->assertJsonPath('message', $legacyMessage)
        ->assertJsonPath('errors', ['products' => [$legacyMessage]])
        ->assertJsonCount(1, 'item_errors')
        ->assertJsonCount(2, 'suggested_items');

    expect(multiShortfallStockSnapshot())->toBe($before)
        ->and(Invoice::query()->where('preinvoice_order_id', $order->id)->exists())->toBeFalse();
});

it('keeps checking past an inactive variant and reports the shortfall after it', function () {
    $finance = multiShortfallUser(['page.sales.preinvoice_finance_review']);
    $covered = multiShortfallItem('COVERED', 10);
    $inactive = multiShortfallItem('INACTIVE', 10);
    $short = multiShortfallItem('SHORT', 1);
    $inactive['variant']->forceFill(['is_active' => false])->saveQuietly();
    $order = multiShortfallFinanceOrder($finance, [[$covered, 2], [$inactive, 3], [$short, 4]], [0]);
    $before = multiShortfallStockSnapshot();

    $this->actingAs($finance)
        ->from(route('preinvoice.draft.finance', $order->uuid))
        ->post(route('preinvoice.draft.finalize', $order->uuid), [])
        ->assertRedirect(route('preinvoice.draft.finance', $order->uuid))
        ->assertSessionHasErrors('products');

    expect(session('errors')->get('products'))->toHaveCount(1)
        ->and(session('errors')->first('products'))->toContain($short['product']->name)
        ->and(collect(session('preinvoice_item_errors'))->pluck('variant_id')->all())->toBe([$short['variant']->id])
        ->and(collect(session('preinvoice_suggested_items'))->keyBy('variant_id')[$short['variant']->id])
        ->toMatchArray(['quantity' => 1, 'remove' => false]);

    expect(multiShortfallStockSnapshot())->toBe($before);
});
