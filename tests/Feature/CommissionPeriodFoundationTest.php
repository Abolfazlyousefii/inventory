<?php

use App\Models\CommissionPeriod;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Services\Commissions\CommissionPeriodService;
use App\Services\Commissions\CurrentCommissionPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Morilog\Jalali\Jalalian;

uses(RefreshDatabase::class);

it('auto creates the current commission period as open', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');

    $period = app(CurrentCommissionPeriodResolver::class)->resolve();

    expect($period->status)->toBe(CommissionPeriod::STATUS_OPEN)
        ->and($period->display_status)->toBe(CommissionPeriod::STATUS_OPEN)
        ->and($period->end_at->isFuture())->toBeTrue();
});

it('maps every commission period status to its correct persian label', function () {
    expect(CommissionPeriod::statusLabels())->toBe([
        CommissionPeriod::STATUS_OPEN => 'باز',
        CommissionPeriod::STATUS_REVIEW => 'در حال بررسی',
        CommissionPeriod::STATUS_CLOSED => 'بسته',
        CommissionPeriod::STATUS_PAID => 'پرداخت‌شده',
    ]);
});

it('repairs only an empty invalid active period and preserves paid history', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');
    $active = CommissionPeriod::query()->create([
        'label' => 'Active invalid', 'start_at' => '2026-08-01', 'end_at' => '2026-09-01',
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_PAID,
        'review_started_at' => now()->subHours(3), 'closed_at' => now()->subHours(2), 'paid_at' => now()->subHour(),
    ]);
    $historical = CommissionPeriod::query()->create([
        'label' => 'Historical paid', 'start_at' => '2026-07-01', 'end_at' => '2026-08-01',
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_PAID,
        'review_started_at' => now()->subMonth(), 'closed_at' => now()->subMonth(), 'paid_at' => now()->subMonth(),
    ]);

    $migration = require database_path('migrations/2026_08_14_000008_reopen_invalid_empty_active_commission_periods.php');
    $migration->up();

    expect($active->fresh()->status)->toBe(CommissionPeriod::STATUS_OPEN)
        ->and($active->fresh()->closed_at)->toBeNull()
        ->and($active->fresh()->paid_at)->toBeNull()
        ->and($active->events()->where('event_type', 'invalid_empty_period_reopened')->exists())->toBeTrue()
        ->and($historical->fresh()->status)->toBe(CommissionPeriod::STATUS_PAID)
        ->and($historical->fresh()->paid_at)->not->toBeNull();
});

it('creates adjacent jalali periods with inclusive start and exclusive end', function () {
    $service = app(CommissionPeriodService::class);
    $first = $service->createForDate(Carbon::parse('2026-08-15 12:00:00'), 10);
    $second = $service->createForDate($first->end_at->copy()->addSecond(), 10);

    expect($first->cycle_day_snapshot)->toBe(10)
        ->and($first->end_at->equalTo($second->start_at))->toBeTrue()
        ->and($first->contains($first->start_at))->toBeTrue()
        ->and($first->contains($first->end_at))->toBeFalse();
});

it('keeps existing period snapshots when cycle day changes and clamps short jalali months', function () {
    $service = app(CommissionPeriodService::class);
    $actor = User::factory()->create();
    $old = $service->createForDate(Carbon::parse('2026-08-15'), 10);
    $oldStart = $old->start_at->copy();
    $service->updateCycleDay('۳۱', $actor);
    $future = $service->createForDate(Carbon::parse('2027-03-15'), CommissionSetting::current()->cycle_day);
    $futureJalali = Jalalian::fromDateTime($future->start_at);

    expect($old->fresh()->cycle_day_snapshot)->toBe(10)
        ->and($old->fresh()->start_at->equalTo($oldStart))->toBeTrue()
        ->and($future->cycle_day_snapshot)->toBe(31)
        ->and($futureJalali->getDay())->toBe($futureJalali->getMonthDays());
});

it('does not overlap the existing current period after a cycle day change', function () {
    $service = app(CommissionPeriodService::class);
    $old = $service->createForDate(Carbon::parse('2026-08-15'), 10);
    $same = $service->createForDate($old->start_at->copy()->addDay(), 5);
    $next = $service->createForDate($old->end_at->copy()->addSecond(), 5);

    expect($same->id)->toBe($old->id)
        ->and($next->start_at->gte($old->end_at))->toBeTrue()
        ->and($old->end_at->lte($next->start_at))->toBeTrue();
});
