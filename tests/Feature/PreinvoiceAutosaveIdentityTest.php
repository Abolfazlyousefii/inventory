<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

uses(RefreshDatabase::class);

const AUTOSAVE_BASE_VERSION_MESSAGE = 'نسخه پیش‌نویس ذخیره‌شده همراه درخواست ارسال نشد. صفحه را یک بار دوباره بارگذاری کنید تا ذخیره خودکار دوباره فعال شود.';

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->actingAs(User::factory()->create());
    $this->payload = [
        'reservation_token' => (string) Str::uuid(),
        'customer_id' => null,
        'customer_name' => 'مشتری پیش‌فاکتور',
        'customer_mobile' => '09120000000',
        'products' => array_slice(largePayloadProducts(2), 0, 2),
    ];
});

it('rejects a draft_uuid without base_version with a Persian message', function () {
    $saved = $this->postJson(route('preinvoice.autosave'), $this->payload)->assertOk()->json();

    $response = $this->postJson(route('preinvoice.autosave'), $this->payload + ['draft_uuid' => $saved['uuid']])
        ->assertUnprocessable()
        ->assertJsonPath('errors.base_version.0', AUTOSAVE_BASE_VERSION_MESSAGE);

    expect($response->getContent())->not->toContain('The base version field is required');
});

it('creates a new draft when neither draft_uuid nor base_version is sent', function () {
    $first = $this->postJson(route('preinvoice.autosave'), $this->payload)->assertOk()->json();
    $firstId = PreinvoiceOrder::query()->where('uuid', $first['uuid'])->where('is_auto_draft', true)->value('id');
    expect($first['uuid'])->not->toBeEmpty()
        ->and($first['version'])->toHaveLength(64)
        ->and($firstId)->not->toBeNull();

    // The discard banner flow: after the draft is discarded, the reset client
    // sends no identity and must get a fresh autosave instead of a locked form.
    // (The 5-digit code may be reused once freed, so compare rows, not codes.)
    $this->postJson(route('preinvoice.autosave.discard', $first['uuid']))->assertOk();
    $second = $this->postJson(route('preinvoice.autosave'), $this->payload)->assertOk()->json();

    $secondId = PreinvoiceOrder::query()->where('uuid', $second['uuid'])->where('is_auto_draft', true)->value('id');
    expect($secondId)->not->toBeNull()
        ->and($secondId)->not->toBe($firstId)
        ->and($second['version'])->toHaveLength(64);
});

it('updates the same draft when draft_uuid and base_version are both correct', function () {
    $saved = $this->postJson(route('preinvoice.autosave'), $this->payload)->assertOk()->json();
    $payload = $this->payload;
    $payload['customer_name'] = 'مشتری ویرایش‌شده';

    $updated = $this->postJson(route('preinvoice.autosave'), $payload + [
        'draft_uuid' => $saved['uuid'],
        'base_version' => $saved['version'],
    ])->assertOk()->json();

    expect($updated['uuid'])->toBe($saved['uuid'])
        ->and($updated['version'])->not->toBe($saved['version'])
        ->and(PreinvoiceOrder::query()->where('uuid', $saved['uuid'])->value('customer_name'))->toBe('مشتری ویرایش‌شده')
        ->and(PreinvoiceOrder::query()->where('is_auto_draft', true)->count())->toBe(1);
});

it('does not create another document when a stale autosave identity is submitted', function (string $intent) {
    $saved = $this->postJson(route('preinvoice.autosave'), $this->payload)->assertOk()->json();
    $this->postJson(route('preinvoice.autosave.discard', $saved['uuid']))->assertOk();

    $before = PreinvoiceOrder::query()->count();
    $rows = $this->payload['products'];
    $response = $this->postJson(route('preinvoice.draft.save'), [
        'intent' => $intent,
        'autosave_uuid' => $saved['uuid'],
        'reservation_token' => $this->payload['reservation_token'],
        'customer_name' => $this->payload['customer_name'],
        'customer_mobile' => $this->payload['customer_mobile'],
        'products_payload' => json_encode($rows, JSON_THROW_ON_ERROR),
        'products_payload_count' => count($rows),
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => array_sum(array_column($rows, 'quantity')),
        'products_payload_gross_total' => array_sum(array_map(fn ($row) => $row['quantity'] * $row['price'], $rows)),
    ])->assertStatus(409);

    expect($response->json('message'))->toContain('دیگر قابل ذخیره نیست')
        ->and(PreinvoiceOrder::query()->count())->toBe($before);
})->with(['draft', 'submit']);

it('never misspells the autosave identity variable in the preinvoice form', function () {
    $source = file_get_contents(resource_path('views/preinvoice/create.blade.php'));

    // A misspelling silently creates a new global and leaves the real uuid set.
    expect($source)->not->toContain('currentAutosaveUUID')
        ->and($source)->toContain('function resetAutosaveIdentity()');

    // Clearing the identity goes only through the helper, which resets uuid and version together.
    expect(preg_match_all('/(?<!let )currentAutosaveUuid = null/', $source))->toBe(1)
        ->and(preg_match_all('/(?<!let )currentAutosaveVersion = null/', $source))->toBe(1);
});
