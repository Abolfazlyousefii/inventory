<?php

use App\Models\Category;
use App\Models\CommissionDocument;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\CommissionSetting;
use App\Models\CommissionTarget;
use App\Models\User;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionTargetService;
use App\Services\Commissions\CurrentCommissionPeriodResolver;
use App\Support\Currency;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function phaseSixSeller(string $name): User
{
    return User::factory()->create([
        'name' => $name,
        'is_seller' => true,
        'is_active' => true,
        'can_access_erp' => true,
    ]);
}

function phaseSixPeriod(string $start = '2026-08-01', string $end = '2026-09-01'): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => $start.' تا '.$end,
        'start_at' => $start,
        'end_at' => $end,
        'cycle_day_snapshot' => 10,
        'status' => CommissionPeriod::STATUS_OPEN,
        'needs_recalculation' => false,
    ]);
}

function phaseSixLedger(CommissionPeriod $period, User $seller, int $commission, int $campaign = 0): CommissionLedgerEntry
{
    return CommissionLedgerEntry::query()->create([
        'commission_period_id' => $period->id,
        'seller_id' => $seller->id,
        'invoice_number_snapshot' => (string) Str::uuid(),
        'invoice_date_snapshot' => $period->start_at->copy()->addDay(),
        'product_name_snapshot' => 'کالای تست',
        'quantity_snapshot' => 1,
        'gross_amount_snapshot' => $commission * 10,
        'discount_amount_snapshot' => 0,
        'net_amount_snapshot' => $commission * 10,
        'base_rate_snapshot' => '10.0000',
        'campaign_rate_snapshot' => $campaign > 0 ? '1.0000' : '0.0000',
        'effective_rate_snapshot' => $campaign > 0 ? '11.0000' : '10.0000',
        'base_commission_amount' => $commission - $campaign,
        'campaign_commission_amount' => $campaign,
        'total_commission_amount' => $commission,
        'missing_rate' => false,
        'calculation_version' => 1,
        'calculation_fingerprint' => hash('sha256', Str::uuid()->toString()),
        'status' => CommissionLedgerEntry::STATUS_ACTIVE,
        'active_marker' => 1,
        'calculated_at' => now(),
    ]);
}

function phaseSixUserWithPermissions(string $roleName, array $keys, array $attributes = []): User
{
    $role = Role::findOrCreate($roleName, 'web');
    if (in_array('dashboard.view', $keys, true)) {
        $keys[] = 'page.dashboard';
    }
    foreach ($keys as $key) {
        if (! DB::table('permissions')->where('key', $key)->exists()) {
            DB::table('permissions')->insert([
                'key' => $key,
                'name' => $key,
                'group' => 'phase-six-test',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
    }
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

it('stores a toman target as integer rial and upserts the seller period pair', function () {
    $seller = phaseSixSeller('فروشنده تارگت');
    $actor = User::factory()->create();
    $period = phaseSixPeriod();
    $service = app(CommissionTargetService::class);

    $service->save($seller, $period, '20,000,000', $actor, 'نسخه اول');
    $service->save($seller, $period, '25,000,000', $actor, 'نسخه دوم');

    expect(CommissionTarget::query()->count())->toBe(1)
        ->and(CommissionTarget::query()->firstOrFail()->target_amount)->toBe(250_000_000)
        ->and(Currency::formatToman(200_000_000))->toBe('20,000,000 تومان')
        ->and(Currency::tomanInput('۲۰٬۰۰۰٬۰۰۰'))->toBe(200_000_000);
});

it('distinguishes no target and calculates remaining exceeded and daily requirement without clamping progress', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    $actor = User::factory()->create();
    $period = phaseSixPeriod();
    $seller = phaseSixSeller('فروشنده میانی');
    $withoutTarget = phaseSixSeller('بدون تارگت');
    app(CommissionTargetService::class)->save($seller, $period, '20,000,000', $actor);
    phaseSixLedger($period, $seller, 140_000_000, 20_000_000);

    $summary = app(CommissionDashboardService::class)->build($period);
    $row = $summary['seller_rows']->firstWhere('seller_id', $seller->id);
    $missing = $summary['seller_rows']->firstWhere('seller_id', $withoutTarget->id);

    expect($row['progress_percent'])->toBe(70.0)
        ->and($row['remaining_amount'])->toBe(60_000_000)
        ->and($row['exceeded_amount'])->toBe(0)
        ->and($row['days_remaining'])->toBe(12)
        ->and($row['required_daily_commission'])->toBe(5_000_000)
        ->and($missing['has_target'])->toBeFalse()
        ->and($missing['progress_percent'])->toBeNull();

    phaseSixLedger($period, $seller, 100_000_000);
    $over = app(CommissionDashboardService::class)->build($period)['seller_rows']->firstWhere('seller_id', $seller->id);
    expect($over['progress_percent'])->toBe(120.0)
        ->and($over['progress_bar_percent'])->toBe(100)
        ->and($over['remaining_amount'])->toBe(0)
        ->and($over['exceeded_amount'])->toBe(40_000_000)
        ->and($over['required_daily_commission'])->toBeNull();
});

it('returns no daily requirement after the period ends', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    $actor = User::factory()->create();
    $period = phaseSixPeriod();
    $seller = phaseSixSeller('فروشنده دوره تمام‌شده');
    app(CommissionTargetService::class)->save($seller, $period, '20,000,000', $actor);
    phaseSixLedger($period, $seller, 100_000_000);

    $row = app(CommissionDashboardService::class)->build($period)['seller_rows']->first();
    expect($row['days_remaining'])->toBe(0)
        ->and($row['period_ended'])->toBeTrue()
        ->and($row['required_daily_commission'])->toBeNull();
});

it('calculates weighted team progress and excludes sellers without targets from reached denominator', function () {
    $actor = User::factory()->create();
    $period = phaseSixPeriod();
    $sellerA = phaseSixSeller('فروشنده الف');
    $sellerB = phaseSixSeller('فروشنده ب');
    $sellerWithoutTarget = phaseSixSeller('فروشنده بدون تارگت');
    app(CommissionTargetService::class)->save($sellerA, $period, '10,000,000', $actor);
    app(CommissionTargetService::class)->save($sellerB, $period, '30,000,000', $actor);
    phaseSixLedger($period, $sellerA, 100_000_000);
    phaseSixLedger($period, $sellerB, 150_000_000);
    phaseSixLedger($period, $sellerWithoutTarget, 90_000_000);

    $team = app(CommissionDashboardService::class)->build($period)['team_summary'];
    expect($team['total_target'])->toBe(400_000_000)
        ->and($team['total_calculated_commission'])->toBe(340_000_000)
        ->and($team['targeted_calculated_commission'])->toBe(250_000_000)
        ->and($team['team_progress_percent'])->toBe(62.5)
        ->and($team['reached_target_count'])->toBe(1)
        ->and($team['targeted_seller_count'])->toBe(2);
});

it('copies previous targets idempotently and never overwrites an existing current target', function () {
    $actor = User::factory()->create();
    $previous = phaseSixPeriod('2026-07-01', '2026-08-01');
    $current = phaseSixPeriod();
    $sellerA = phaseSixSeller('کپی الف');
    $sellerB = phaseSixSeller('کپی ب');
    $service = app(CommissionTargetService::class);
    $service->save($sellerA, $previous, '20,000,000', $actor);
    $service->save($sellerB, $previous, '30,000,000', $actor);
    $service->save($sellerA, $current, '25,000,000', $actor);

    $first = $service->copyPrevious($current, $actor);
    $second = $service->copyPrevious($current, $actor);

    expect($first['copied'])->toBe(1)
        ->and($first['existing'])->toBe(1)
        ->and($second['copied'])->toBe(0)
        ->and(CommissionTarget::query()->where('commission_period_id', $current->id)->count())->toBe(2)
        ->and(CommissionTarget::query()->where('commission_period_id', $current->id)->where('seller_id', $sellerA->id)->value('target_amount'))->toBe(250_000_000);
});

it('resolves and auto creates the same current period idempotently', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');
    $resolver = app(CurrentCommissionPeriodResolver::class);
    $ids = collect(range(1, 10))->map(fn () => $resolver->resolve()->id);

    expect($ids->unique()->count())->toBe(1)
        ->and(CommissionPeriod::query()->count())->toBe(1)
        ->and(DB::table('commission_settings')->count())->toBe(1);
});

it('keeps seller dashboard commission data isolated and exposes team data only to an authorized manager', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');
    $period = phaseSixPeriod();
    $actor = User::factory()->create();
    $sellerA = phaseSixUserWithPermissions('phase-six-seller-a', ['dashboard.view'], [
        'name' => 'فروشنده داشبورد الف', 'is_seller' => true, 'is_active' => true, 'can_access_erp' => true,
    ]);
    $sellerB = phaseSixSeller('فروشنده محرمانه ب');
    app(CommissionTargetService::class)->save($sellerA, $period, '20,000,000', $actor);
    app(CommissionTargetService::class)->save($sellerB, $period, '99,000,000', $actor);
    phaseSixLedger($period, $sellerA, 100_000_000);
    phaseSixLedger($period, $sellerB, 990_000_000);
    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);

    $this->actingAs($sellerA)->get(route('dashboard'))->assertOk()
        ->assertSee('پورسانت دوره جاری')
        ->assertSee('10,000,000 تومان')
        ->assertDontSee('فروشنده محرمانه ب')
        ->assertDontSee('99,000,000 تومان');

    $manager = phaseSixUserWithPermissions('phase-six-manager', ['dashboard.view', 'commissions.view_seller_details']);
    $this->actingAs($manager)->get(route('dashboard'))->assertOk()
        ->assertSee('پورسانت دوره جاری')
        ->assertSee('109,000,000 تومان');
});

it('protects target mutations with the target management permission', function () {
    $period = phaseSixPeriod();
    $seller = phaseSixSeller('فروشنده مجوز');
    $viewer = phaseSixUserWithPermissions('phase-six-target-viewer', ['page.commercial.commissions']);
    $manager = phaseSixUserWithPermissions('phase-six-target-manager', ['page.commercial.commissions', 'commissions.manage_targets']);
    $payload = ['target_amount' => '20,000,000'];
    CommissionSetting::current()->update(['targets_enabled' => true]);

    $this->actingAs($viewer)->put(route('commercial.commissions.targets.update', [$period, $seller]), $payload)->assertForbidden();
    $this->actingAs($manager)->put(route('commercial.commissions.targets.update', [$period, $seller]), $payload)->assertRedirect();
    expect(CommissionTarget::query()->where('seller_id', $seller->id)->value('target_amount'))->toBe(200_000_000);
    $this->actingAs($manager)->get(route('commercial.commissions.index', ['period' => $period->id]))
        ->assertOk()
        ->assertSee('commissionTargetForm'.$seller->id, false)
        ->assertSee('20,000,000');
});

it('prevents a seller from viewing another seller commission document', function () {
    $period = phaseSixPeriod();
    $sellerA = phaseSixUserWithPermissions('phase-six-document-seller-a', ['page.commercial.commissions'], [
        'name' => 'فروشنده سند الف', 'is_seller' => true, 'is_active' => true, 'can_access_erp' => true,
    ]);
    $sellerB = phaseSixSeller('فروشنده سند ب');
    $ownDocument = CommissionDocument::query()->create([
        'document_number' => 'COM-OWN', 'seller_id' => $sellerA->id, 'commission_period_id' => $period->id,
        'status' => CommissionDocument::STATUS_DRAFT, 'created_by' => $sellerA->id,
    ]);
    $otherDocument = CommissionDocument::query()->create([
        'document_number' => 'COM-OTHER', 'seller_id' => $sellerB->id, 'commission_period_id' => $period->id,
        'status' => CommissionDocument::STATUS_DRAFT, 'created_by' => $sellerA->id,
    ]);
    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);

    $this->actingAs($sellerA)->get(route('commercial.commissions.documents.show', $ownDocument))->assertOk();
    $this->actingAs($sellerA)->get(route('commercial.commissions.documents.show', $otherDocument))->assertForbidden();
});

it('surfaces dirty periods in dashboard summaries', function () {
    $period = phaseSixPeriod();
    $period->update(['needs_recalculation' => true]);

    expect(app(CommissionDashboardService::class)->build($period)['is_stale'])->toBeTrue();
});

it('keeps commission dashboard queries bounded as ledger rows grow', function () {
    $period = phaseSixPeriod();
    $seller = phaseSixSeller('فروشنده پرتراکنش');
    foreach (range(1, 80) as $index) {
        phaseSixLedger($period, $seller, 1_000_000 + $index);
    }
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $summary = app(CommissionDashboardService::class)->build($period);

    expect($summary['seller_rows'])->toHaveCount(1)
        ->and($queries)->toBeLessThanOrEqual(10);
});

it('renders real rate names distinct zero and missing states campaign terminology and document empty state', function () {
    $period = phaseSixPeriod();
    $actor = User::factory()->create();
    $zeroCategory = Category::query()->create(['name' => 'دسته بدون پورسانت']);
    Category::query()->create(['name' => 'دسته فاقد نرخ']);
    CommissionRateRevision::query()->create([
        'target_type' => 'category', 'target_id' => $zeroCategory->id,
        'target_key' => 'category:'.$zeroCategory->id, 'active_marker' => 1,
        'category_id' => $zeroCategory->id, 'percentage' => '0.0000',
        'effective_from' => now(), 'created_by' => $actor->id,
    ]);
    $viewer = phaseSixUserWithPermissions('phase-six-ui-viewer', ['page.commercial.commissions']);

    $this->actingAs($viewer)->get(route('commercial.commissions.index', ['period' => $period->id]))
        ->assertOk()
        ->assertSee('دسته بدون پورسانت')
        ->assertSee('دسته فاقد نرخ')
        ->assertSee('بدون پورسانت')
        ->assertSee('فاقد نرخ')
        ->assertSee('اقلام کمپین')
        ->assertDontSee('هدف کمپین')
        ->assertSee('هنوز سند پورسانتی برای این دوره ثبت نشده است.');
});
