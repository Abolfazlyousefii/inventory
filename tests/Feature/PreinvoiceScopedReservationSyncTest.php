<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
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

    $role = Role::findOrCreate('scoped-sync-'.Str::random(10), 'web');
    $permission = Permission::query()->where('key', 'page.sales.preinvoices')->first()
        ?? Permission::findOrCreate('page.sales.preinvoices', 'web');
    if (($permission->key ?? null) !== 'page.sales.preinvoices') {
        $permission->forceFill(['key' => 'page.sales.preinvoices'])->save();
    }
    $role->givePermissionTo($permission);

    $this->seller = User::factory()->create();
    $this->seller->assignRole($role);
    $this->seller->forceFill(['is_seller' => true])->save();
    $this->actingAs($this->seller);
    $this->token = (string) Str::uuid();
});

/** @return array{product:Product,variant:ProductVariant} */
function scopedSyncItem(string $label, int $centralQuantity): array
{
    $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Scoped sync '.Str::uuid()]));
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Scoped '.$label,
        'sku' => 'SS-'.Str::uuid(),
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
        'variant_name' => 'Scoped variant '.$label,
        'variant_code' => 'SSV-'.Str::uuid(),
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

function scopedSyncPayload(string $token, array $lines, ?array $scope = null): array
{
    $payload = [
        'reservation_token' => $token,
        'submission_token' => $token,
        'is_in_person' => false,
        'items' => array_map(fn (array $line) => [
            'product_id' => $line[0]['product']->id,
            'variant_id' => $line[0]['variant']->id,
            'quantity' => $line[1],
        ], $lines),
    ];

    return $scope === null ? $payload : $payload + ['scope_product_ids' => $scope];
}

function scopedSyncCentral(array $item): int
{
    return (int) WarehouseStock::query()
        ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
        ->where('product_variant_id', $item['variant']->id)
        ->value('quantity');
}

/** The active temporary reservation row of one item on the token, or null. */
function scopedSyncActiveRow(string $token, array $item): ?PreinvoiceDraftReservation
{
    return PreinvoiceDraftReservation::query()
        ->where('token', $token)
        ->where('variant_id', $item['variant']->id)
        ->whereNull('released_at')
        ->whereNull('release_reason')
        ->first();
}

function scopedSyncSnapshot(): array
{
    return [
        'warehouse_stocks' => DB::table('warehouse_stocks')->orderBy('id')->get(['id', 'product_id', 'product_variant_id', 'quantity'])->map(fn ($r) => (array) $r)->all(),
        'reservations' => DB::table('preinvoice_draft_reservations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

function scopedSyncSubmitPayload(string $token, array $lines): array
{
    $rows = array_map(fn (array $line) => [
        'item_id' => null,
        'id' => $line[0]['product']->id,
        'product_id' => $line[0]['product']->id,
        'variety_id' => $line[0]['variant']->id,
        'variant_id' => $line[0]['variant']->id,
        'quantity' => $line[1],
        'price' => 1000,
        'line_discount_amount' => 0,
    ], $lines);
    $totalQuantity = array_sum(array_column($lines, 1));

    return [
        'intent' => 'submit',
        'reservation_token' => $token,
        'customer_name' => 'Scoped sync customer',
        'customer_mobile' => '09120000000',
        'is_in_person' => 0,
        'discount_amount' => 0,
        'products_payload' => json_encode($rows, JSON_THROW_ON_ERROR),
        'products_payload_count' => count($rows),
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => $totalQuantity,
        'products_payload_gross_total' => $totalQuantity * 1000,
    ];
}

it('syncs only the scoped product while another product is short', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 3]]))->assertOk();
    $bRowBefore = scopedSyncActiveRow($this->token, $b)->toArray();

    // B now asks for far more than stock; the user edits only A.
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 5], [$b, 20]], [$a['product']->id]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonCount(1, 'data.reserved')
        ->assertJsonPath('data.reserved.0.product_id', $a['product']->id)
        ->assertJsonPath('data.reserved.0.quantity', 5);

    expect(scopedSyncActiveRow($this->token, $a)->quantity)->toBe(5)
        ->and(scopedSyncCentral($a))->toBe(5)
        ->and(scopedSyncActiveRow($this->token, $b)->toArray())->toBe($bRowBefore)
        ->and(scopedSyncCentral($b))->toBe(7);
});

it('keeps the unscoped sync all-or-nothing with the full item_errors', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 3]]))->assertOk();
    $before = scopedSyncSnapshot();

    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 5], [$b, 20]]))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonCount(1, 'item_errors')
        ->assertJsonPath('item_errors.0.variant_id', $b['variant']->id)
        ->assertJsonPath('item_errors.0.available_quantity', 7)
        ->assertJsonPath('item_errors.0.requested_quantity', 17)
        ->assertJsonPath('item_errors.0.max_allowed', 10)
        ->assertJsonCount(2, 'suggested_items');

    // A's valid edit is rolled back too, exactly as before.
    expect(scopedSyncSnapshot())->toBe($before)
        ->and(scopedSyncActiveRow($this->token, $a)->quantity)->toBe(2);
});

it('does not release an out-of-scope reservation that is missing from items', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 3]]))->assertOk();
    $bRowBefore = scopedSyncActiveRow($this->token, $b)->toArray();

    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 4]], [$a['product']->id]))
        ->assertOk();

    expect(scopedSyncActiveRow($this->token, $a)->quantity)->toBe(4)
        ->and(scopedSyncActiveRow($this->token, $b)?->toArray())->toBe($bRowBefore)
        ->and(scopedSyncCentral($b))->toBe(7);
});

it('releases only the deleted group when its product is scoped and absent from items', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 3]]))->assertOk();
    $bRowBefore = scopedSyncActiveRow($this->token, $b)->toArray();
    $aRowId = scopedSyncActiveRow($this->token, $a)->id;

    // B may even be short in the list; deleting A must still work.
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$b, 20]], [$a['product']->id]))
        ->assertOk()
        ->assertJsonCount(0, 'data.reserved');

    $aRow = PreinvoiceDraftReservation::query()->findOrFail($aRowId);
    expect(scopedSyncActiveRow($this->token, $a))->toBeNull()
        ->and($aRow->release_reason)->toBe('manual_release')
        ->and($aRow->released_at)->not->toBeNull()
        ->and(scopedSyncCentral($a))->toBe(10)
        ->and(scopedSyncActiveRow($this->token, $b)->toArray())->toBe($bRowBefore)
        ->and(scopedSyncCentral($b))->toBe(7);
});

it('rejects an invalid scope with a validation error and writes nothing', function (mixed $scope, string $errorKey) {
    $a = scopedSyncItem('A', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2]]))->assertOk();
    $before = scopedSyncSnapshot();

    $payload = scopedSyncPayload($this->token, [[$a, 5]]);
    $payload['scope_product_ids'] = $scope;

    $this->postJson(route('preinvoice.api.reservations.sync'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$errorKey]);

    expect(scopedSyncSnapshot())->toBe($before);
})->with([
    'string item' => [['abc'], 'scope_product_ids.0'],
    'negative id' => [[-3], 'scope_product_ids.0'],
    'zero id' => [[0], 'scope_product_ids.0'],
    'not an array' => ['abc', 'scope_product_ids'],
    'too many ids' => [range(1, 51), 'scope_product_ids'],
]);

it('still checks every product on final submit after a scoped sync left B unreserved', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 1);
    $c = scopedSyncItem('C', 0);
    // Only A was ever synced (scoped); B and C were never reserved.
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 4], [$c, 2]], [$a['product']->id]))
        ->assertOk();
    $before = scopedSyncSnapshot();
    $ordersBefore = PreinvoiceOrder::query()->count();

    $response = $this->postJson(route('preinvoice.draft.save'), scopedSyncSubmitPayload($this->token, [[$a, 2], [$b, 4], [$c, 2]]))
        ->assertStatus(422)
        ->assertJsonCount(2, 'item_errors')
        ->assertJsonCount(3, 'suggested_items');

    $errors = collect($response->json('item_errors'))->keyBy('variant_id');
    expect($errors->keys()->sort()->values()->all())->toBe(collect([$b['variant']->id, $c['variant']->id])->sort()->values()->all())
        ->and($errors[$b['variant']->id]['max_allowed'])->toBe(1)
        ->and($errors[$c['variant']->id]['max_allowed'])->toBe(0);

    expect(scopedSyncSnapshot())->toBe($before)
        ->and(PreinvoiceOrder::query()->count())->toBe($ordersBefore);
});

it('covers a temporary reservation smaller than the item quantity from stock on final submit', function () {
    $a = scopedSyncItem('A', 10);
    $b = scopedSyncItem('B', 10);
    $this->postJson(route('preinvoice.api.reservations.sync'), scopedSyncPayload($this->token, [[$a, 2], [$b, 3]]))->assertOk();

    // The submitted list asks for 5 of B while only 3 are temporarily reserved.
    $this->post(route('preinvoice.draft.save'), scopedSyncSubmitPayload($this->token, [[$a, 2], [$b, 5]]))
        ->assertSessionHasNoErrors();

    $order = PreinvoiceOrder::query()->where('created_by', $this->seller->id)->latest('id')->firstOrFail();
    $official = PreinvoiceDraftReservation::query()
        ->where('preinvoice_order_id', $order->id)
        ->where('reservation_scope', 'official')
        ->whereNull('released_at')
        ->get()
        ->keyBy('variant_id');

    expect($order->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and((int) $official[$b['variant']->id]->quantity)->toBe(5)
        ->and((int) $official[$a['variant']->id]->quantity)->toBe(2)
        // The missing 2 of B came out of central stock exactly once.
        ->and(scopedSyncCentral($b))->toBe(5)
        ->and(scopedSyncCentral($a))->toBe(8);
});
