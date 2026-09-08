<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\InventoryWebhookSetting;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\PreinvoiceDraftReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->seller = User::factory()->create(['is_seller' => true]);
    $this->actingAs($this->seller);
    $this->rows = largePayloadProducts(2);
    $this->token = (string) Str::uuid();
});

function hardeningPayload(array $rows, string $token, ?string $uuid = null): array
{
    return [
        'reservation_token' => $token, 'submission_token' => $token, 'preinvoice_uuid' => $uuid,
        'items' => array_map(fn ($r) => array_intersect_key($r, array_flip(['product_id', 'variant_id', 'quantity'])), $rows),
    ];
}

function hardeningStock(array $row, int $stock, int $reserved): void
{
    expect((int) ProductVariant::findOrFail($row['variant_id'])->stock)->toBe($stock)
        ->and((int) ProductVariant::findOrFail($row['variant_id'])->reserved)->toBe($reserved)
        ->and((int) Product::findOrFail($row['product_id'])->stock)->toBe($stock)
        ->and((int) Product::findOrFail($row['product_id'])->reserved)->toBe($reserved)
        ->and((int) WarehouseStock::where('product_variant_id', $row['variant_id'])->value('quantity'))->toBe($stock);
}

it('creates and retries a desired temporary reservation without double freezing', function () {
    $row = array_replace($this->rows[0], ['quantity' => 20]);
    $payload = hardeningPayload([$row], $this->token);
    foreach (range(1, 3) as $_) {
        $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk();
        hardeningStock($row, 980, 20);
    }
    expect(PreinvoiceDraftReservation::count())->toBe(1);
});

it('releases the last product and repeated empty syncs do not inflate stock', function () {
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($this->rows, $this->token))->assertOk();
    foreach (range(1, 2) as $_) {
        $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([], $this->token))->assertOk();
        foreach ($this->rows as $row) hardeningStock($row, 1000, 0);
    }
    expect(PreinvoiceDraftReservation::whereNull('released_at')->count())->toBe(0);
});

it('requires the items key so a malformed request cannot release the selection', function () {
    $payload = hardeningPayload($this->rows, $this->token);
    $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk();
    unset($payload['items']);
    $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertUnprocessable();
    hardeningStock($this->rows[0], 998, 2);
});

it('converts temporary reservations and skips every old-token mutation including new variants', function () {
    $rows = [$this->rows[0]];
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($rows, $this->token))->assertOk();
    $reservation = PreinvoiceDraftReservation::sole();
    $this->post(route('preinvoice.draft.save'), largePayloadPost($rows, ['reservation_token' => $this->token]))->assertSessionHasNoErrors();
    $reservation->refresh();
    expect($reservation->reservation_scope)->toBe('official')->and($reservation->preinvoice_order_id)->not->toBeNull();
    $before = $reservation->getRawOriginal();
    Log::spy();
    foreach ([[], $this->rows, [array_replace($rows[0], ['quantity' => 50])]] as $desired) {
        $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($desired, $this->token))
            ->assertOk()->assertJsonPath('data.skipped', true);
    }
    expect($reservation->fresh()->getRawOriginal())->toBe($before);
    hardeningStock($rows[0], 998, 2);
    hardeningStock($this->rows[1], 1000, 0);
    Log::shouldHaveReceived('warning')->with('RESERVATION_SYNC_SKIPPED', Mockery::type('array'))->times(3);
});

it('protects converted rows even if their order link is missing', function () {
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$this->rows[0]], $this->token))->assertOk();
    $reservation = PreinvoiceDraftReservation::sole();
    $reservation->forceFill(['converted_at' => now()])->save();
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($this->rows, $this->token))
        ->assertOk()->assertJsonPath('data.skipped', true);
    expect($reservation->fresh()->converted_at)->not->toBeNull();
    hardeningStock($this->rows[0], 998, 2);
    hardeningStock($this->rows[1], 1000, 0);
});

it('does not take over another sellers token', function () {
    $payload = hardeningPayload($this->rows, $this->token);
    $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk();
    $this->actingAs(User::factory()->create(['is_seller' => true]));
    $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk()->assertJsonPath('data.skipped', true);
    expect(PreinvoiceDraftReservation::where('user_id', $this->seller->id)->count())->toBe(2);
    hardeningStock($this->rows[0], 998, 2);
});

it('syncs add increase decrease and removal in an authorized edit without recreating rows', function () {
    $order = largePayloadOrder($this->seller, $this->rows);
    $row = $this->rows[0];
    foreach ([20, 30, 10] as $qty) {
        $row['quantity'] = $qty;
        $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$row], $this->token, $order->uuid))->assertOk();
        hardeningStock($row, 1000 - $qty, $qty);
    }
    expect(PreinvoiceDraftReservation::count())->toBe(1);
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$row, $this->rows[1]], $this->token, $order->uuid))->assertOk();
    hardeningStock($this->rows[1], 998, 2);
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([], $this->token, $order->uuid))->assertOk();
    foreach ($this->rows as $item) hardeningStock($item, 1000, 0);
});

it('submits an edited draft using its existing freeze even when free stock is exhausted', function () {
    $order = largePayloadOrder($this->seller, [$this->rows[0]]);
    $row = array_replace($this->rows[0], ['quantity' => 1000, 'item_id' => $order->items->first()->id]);
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$row], $this->token, $order->uuid))->assertOk();
    $this->put(route('preinvoice.draft.update', $order->uuid), largePayloadPost([$row], ['reservation_token' => $this->token]))->assertSessionHasNoErrors();
    hardeningStock($row, 0, 1000);
    expect($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and(PreinvoiceDraftReservation::sole()->preinvoice_order_id)->toBe($order->id);
});

it('rejects edit sync for another seller and for a finance-queued document', function () {
    $order = largePayloadOrder(User::factory()->create(), $this->rows);
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($this->rows, $this->token, $order->uuid))->assertForbidden();
    $order->update(['created_by' => $this->seller->id, 'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE]);
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($this->rows, $this->token, $order->uuid))->assertForbidden();
    hardeningStock($this->rows[0], 1000, 0);
});

it('rejects the competing seller demand after the first reservation wins', function () {
    $row = $this->rows[0];
    // Leave exactly 100 available using the same reservation path.
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([array_replace($row, ['quantity' => 900])], (string) Str::uuid()))->assertOk();
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([array_replace($row, ['quantity' => 80])], $this->token))->assertOk();
    $this->actingAs(User::factory()->create(['is_seller' => true]));
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([array_replace($row, ['quantity' => 50])], (string) Str::uuid()))->assertUnprocessable();
    hardeningStock($row, 20, 980);
});

it('never lets product lookup expire or expose an official reservation as temporary', function () {
    $rows = [$this->rows[0]];
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($rows, $this->token))->assertOk();
    $this->post(route('preinvoice.draft.save'), largePayloadPost($rows, ['reservation_token' => $this->token]))->assertSessionHasNoErrors();
    $reservation = PreinvoiceDraftReservation::sole();
    $reservation->update(['expires_at' => now()->subHour()]);
    $this->getJson(route('preinvoice.api.product', $rows[0]['product_id']).'?reservation_token='.$this->token)->assertOk();
    expect($reservation->fresh()->released_at)->toBeNull();
    hardeningStock($rows[0], 998, 2);
});

it('cleanup protects official rows while releasing stale temporary stock exactly once', function () {
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$this->rows[0]], $this->token))->assertOk();
    $this->post(route('preinvoice.draft.save'), largePayloadPost([$this->rows[0]], ['reservation_token' => $this->token]))->assertSessionHasNoErrors();
    $official = PreinvoiceDraftReservation::sole();
    $otherToken = (string) Str::uuid();
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload([$this->rows[1]], $otherToken))->assertOk();
    PreinvoiceDraftReservation::query()->update(['last_seen_at' => now()->subMinutes(20)]);
    foreach (range(1, 2) as $_) app(PreinvoiceDraftReservationService::class)->cleanupStaleTemporaryReservations();
    expect($official->fresh()->released_at)->toBeNull();
    hardeningStock($this->rows[0], 998, 2);
    hardeningStock($this->rows[1], 1000, 0);
});

it('defers reservation webhooks until commit and discards them on rollback', function () {
    InventoryWebhookSetting::create(['is_enabled' => true, 'endpoint_url' => 'https://example.test/webhook', 'timeout_seconds' => 1]);
    DB::beginTransaction();
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token, $this->seller->id, hardeningPayload($this->rows, $this->token)['items']);
    Http::assertNothingSent();
    DB::rollBack();
    Http::assertNothingSent();
    hardeningStock($this->rows[0], 1000, 0);
    DB::beginTransaction();
    app(PreinvoiceDraftReservationService::class)->syncReservationRows($this->token, $this->seller->id, hardeningPayload($this->rows, $this->token)['items']);
    Http::assertNothingSent();
    DB::commit();
    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/webhook');
});

it('rolls back every item when any desired quantity cannot be reserved', function () {
    $rows = $this->rows;
    $rows[1]['quantity'] = 1001;
    $this->postJson(route('preinvoice.api.reservations.sync'), hardeningPayload($rows, $this->token))->assertUnprocessable();
    foreach ($rows as $row) hardeningStock($row, 1000, 0);
    expect(PreinvoiceDraftReservation::count())->toBe(0);
});
