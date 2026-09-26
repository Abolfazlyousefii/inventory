<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows only todays expired reservations in finance while retaining older documents for the seller', function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
    $this->travelTo(now()->startOfDay()->addHours(12));
    $seller = User::factory()->create();
    $this->actingAs($seller);

    $makeOrder = function (string $code, string $status, $expiresAt = null, $releasedAt = null) use ($seller) {
        return PreinvoiceOrder::query()->create([
            'uuid' => $code,
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'status' => $status,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09120000000',
            'total_price' => 1000,
            'stock_frozen_until' => $expiresAt,
            'stock_released_at' => $releasedAt,
        ]);
    };

    $active = $makeOrder('FQ-ACTIVE', PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->addHour());
    $overdue = $makeOrder('FQ-OVERDUE', PreinvoiceOrder::STATUS_PENDING_FINANCE, now()->subMinute());
    $expiredToday = $makeOrder('FQ-EXPIRED-TODAY', PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->startOfDay());
    $expiredYesterday = $makeOrder('FQ-EXPIRED-YESTERDAY', PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->startOfDay()->subSecond());
    $expiredWeekAgo = $makeOrder('FQ-EXPIRED-WEEK', PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, null, now()->subWeek());

    $this->get(route('preinvoice.draft.index'))
        ->assertOk()
        ->assertSee($active->uuid)
        ->assertDontSee($overdue->uuid)
        ->assertDontSee($expiredToday->uuid)
        ->assertDontSee($expiredYesterday->uuid)
        ->assertDontSee($expiredWeekAgo->uuid)
        ->assertSee('رزروهای منقضی‌شده')
        ->assertViewHas('pendingCount', 1);

    $this->get(route('preinvoice.draft.index', ['tab' => 'expired']))
        ->assertOk()
        ->assertViewHas('activeTab', 'expired')
        ->assertViewHas('expiredCount', 1)
        ->assertSee($expiredToday->uuid)
        ->assertDontSee($expiredYesterday->uuid)
        ->assertDontSee($expiredWeekAgo->uuid);

    $this->travelTo(now()->addDay());
    $this->get(route('preinvoice.draft.index', ['tab' => 'expired']))
        ->assertOk()
        ->assertViewHas('expiredCount', 0)
        ->assertDontSee($expiredToday->uuid);

    $this->get(route('preinvoice.my.index', ['tab' => 'active']))
        ->assertOk()
        ->assertSee($expiredWeekAgo->uuid)
        ->assertSee('بررسی و ثبت مجدد');

    expect($expiredWeekAgo->fresh()->status)->toBe(PreinvoiceOrder::STATUS_RESERVATION_EXPIRED);
});
