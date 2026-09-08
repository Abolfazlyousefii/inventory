<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->actingAs(User::factory()->create());
    $this->rows = largePayloadProducts(3);
    $this->payload = [
        'reservation_token' => (string) Str::uuid(),
        'customer_id' => null,
        'customer_name' => 'مشتری پیش‌فاکتور',
        'customer_mobile' => '09120000000',
        'payment_terms_note' => 'پرداخت هنگام تحویل کالا',
        'products' => array_slice($this->rows, 0, 2),
    ];
});

function autosaveStoredState(): array
{
    return [
        DB::table('preinvoice_orders')->orderBy('id')->get()->toJson(),
        DB::table('preinvoice_order_items')->orderBy('id')->get()->toJson(),
    ];
}

function autosaveInventoryState(): array
{
    return collect(['preinvoice_draft_reservations', 'warehouse_stocks', 'product_variants', 'products'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

function autosaveExistingPayload($test): array
{
    $saved = $test->postJson(route('preinvoice.autosave'), $test->payload)->assertOk()->json();

    return $test->payload + ['draft_uuid' => $saved['uuid'], 'base_version' => $saved['version']];
}

it('preserves the entire snapshot when an autosave loses information', function (string $change) {
    $payload = autosaveExistingPayload($this);
    $before = autosaveStoredState();
    switch ($change) {
        case 'missing products': unset($payload['products']); break;
        case 'empty products': $payload['products'] = []; break;
        case 'missing row': array_pop($payload['products']); break;
        case 'lower quantity': $payload['products'][0]['quantity'] = 1; break;
        case 'lower price': $payload['products'][0]['price'] = 10; break;
        case 'zero quantity': $payload['products'][0]['quantity'] = 0; break;
        case 'missing price': unset($payload['products'][0]['price']); break;
        case 'missing customer': unset($payload['customer_name']); break;
        case 'empty customer': $payload['customer_name'] = ''; break;
        case 'shorter mobile': $payload['customer_mobile'] = '0912'; break;
        case 'empty note': $payload['payment_terms_note'] = ''; break;
        case 'invalid variant': $payload['products'][0]['variety_id'] = $this->rows[2]['variety_id']; break;
        case 'duplicate variant': $payload['products'][] = $payload['products'][0]; break;
        case 'invalid discount': $payload['discount_breakdown'] = '{'; break;
        case 'lower total': $payload['invoice_discount_value'] = 1000; break;
        case 'missing row masked by increase':
            array_pop($payload['products']);
            $payload['products'][0]['quantity'] = 100;
            break;
    }

    $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
    expect(autosaveStoredState())->toBe($before);
})->with([
    'missing products', 'empty products', 'missing row', 'lower quantity', 'lower price',
    'zero quantity', 'missing price', 'missing customer', 'empty customer', 'shorter mobile',
    'empty note', 'invalid variant', 'duplicate variant', 'invalid discount', 'lower total',
    'missing row masked by increase',
]);

it('allows only an explicit confirmation bound to the exact reduced snapshot', function (string $change) {
    $payload = autosaveExistingPayload($this);
    if ($change === 'delete') {
        array_pop($payload['products']);
    } elseif ($change === 'delete all') {
        $payload['products'] = [];
    } else {
        $payload['products'][0]['quantity'] = 1;
    }
    $before = autosaveStoredState();
    $payload['action'] = 'confirm_changes';
    $challenge = $this->postJson(route('preinvoice.autosave'), $payload)
        ->assertUnprocessable()->assertJsonPath('code', 'snapshot_reduction')->json('confirmation_token');
    expect(autosaveStoredState())->toBe($before);

    $payload['confirmation_token'] = $challenge;
    $this->postJson(route('preinvoice.autosave'), $payload)->assertOk();
    $order = PreinvoiceOrder::where('uuid', $payload['draft_uuid'])->firstOrFail();
    expect($order->items()->count())->toBe(count($payload['products']))
        ->and((int) $order->items()->sum('quantity'))->toBe(array_sum(array_column($payload['products'], 'quantity')));
})->with(['delete', 'delete all', 'quantity']);

it('does not allow confirmation tokens to authorize different data or ordinary autosave', function () {
    $payload = autosaveExistingPayload($this);
    array_pop($payload['products']);
    $token = $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable()->json('confirmation_token');
    $before = autosaveStoredState();
    $payload['confirmation_token'] = $token;
    $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
    $payload['action'] = 'confirm_changes';
    $payload['products'][0]['quantity'] = 1;
    $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
    expect(autosaveStoredState())->toBe($before);
});

it('accepts additional items and increased quantities without a confirmation', function () {
    $payload = autosaveExistingPayload($this);
    $payload['products'][] = $this->rows[2];
    $payload['products'][0]['quantity'] = 10;
    $response = $this->postJson(route('preinvoice.autosave'), $payload)->assertOk();
    expect($response->json('version'))->not->toBe($payload['base_version']);
    $this->getJson(route('preinvoice.autosave.latest'))->assertOk()->assertJsonCount(3, 'draft.items');
});

it('rejects stale writes even within the same timestamp second and with a confirmation', function () {
    $this->freezeTime();
    $old = autosaveExistingPayload($this);
    $reduced = $old;
    array_pop($reduced['products']);
    $token = $this->postJson(route('preinvoice.autosave'), $reduced)->assertUnprocessable()->json('confirmation_token');
    $new = $old;
    $new['products'][] = $this->rows[2];
    $saved = $this->postJson(route('preinvoice.autosave'), $new)->assertOk()->json();
    $before = autosaveStoredState();

    $this->postJson(route('preinvoice.autosave'), $old)->assertConflict();
    $this->postJson(route('preinvoice.autosave'), $reduced + ['action' => 'confirm_changes', 'confirmation_token' => $token])->assertConflict();
    expect(autosaveStoredState())->toBe($before)
        ->and($saved['version'])->not->toBe($old['base_version']);
});

it('requires the base version and never falls back to a different draft', function () {
    $payload = autosaveExistingPayload($this);
    $before = autosaveStoredState();
    unset($payload['base_version']);
    $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
    $this->postJson(route('preinvoice.autosave'), $this->payload)->assertConflict();
    $payload['base_version'] = str_repeat('a', 64);
    $payload['draft_uuid'] = 'unknown';
    $this->postJson(route('preinvoice.autosave'), $payload)->assertConflict();
    expect(autosaveStoredState())->toBe($before);
});

it('does not overwrite another owner, a manual draft or a submitted preinvoice', function (string $state) {
    $payload = autosaveExistingPayload($this);
    $order = PreinvoiceOrder::where('uuid', $payload['draft_uuid'])->firstOrFail();
    if ($state === 'other owner') {
        $this->actingAs(User::factory()->create());
    } elseif ($state === 'manual draft') {
        $order->update(['is_auto_draft' => false]);
    } else {
        $order->update(['status' => PreinvoiceOrder::STATUS_PENDING_FINANCE, 'is_auto_draft' => false]);
    }
    $before = autosaveStoredState();
    $this->postJson(route('preinvoice.autosave'), $payload)->assertConflict();
    expect(autosaveStoredState())->toBe($before);
})->with(['other owner', 'manual draft', 'submitted']);

it('keeps stock and reservations unchanged for accepted and rejected autosaves', function () {
    $before = autosaveInventoryState();
    $payload = autosaveExistingPayload($this);
    $payload['products'][0]['quantity'] = 1;
    $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable();
    expect(autosaveInventoryState())->toBe($before);
});

it('recovers the same items and version after a refresh and preserves omitted optional fields', function () {
    $payload = autosaveExistingPayload($this);
    $latest = $this->getJson(route('preinvoice.autosave.latest'))->assertOk()->assertJsonCount(2, 'draft.items')->json('draft');
    expect($latest['version'])->toBe($payload['base_version'])
        ->and($latest['items'][0]['quantity'])->toBe(2)
        ->and($latest['customer']['name'])->toBe($payload['customer_name']);
    unset($payload['payment_terms_note']);
    $payload['base_version'] = $latest['version'];
    $this->postJson(route('preinvoice.autosave'), $payload)->assertOk();
    $this->assertDatabaseHas('preinvoice_orders', ['uuid' => $latest['uuid'], 'payment_terms_note' => $this->payload['payment_terms_note']]);
});
