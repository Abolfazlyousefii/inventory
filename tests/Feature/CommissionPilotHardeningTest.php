<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\CommissionCampaignTarget;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocument;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionSetting;
use App\Models\CommissionSettlement;
use App\Models\CommissionTarget;
use App\Models\User;
use App\Services\Commissions\CommissionCampaignService;
use App\Services\Commissions\CommissionDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pilotHardeningUser(string $roleName, array $permissions, array $attributes = []): User
{
    $role = Role::findOrCreate($roleName, 'web');
    if (in_array('dashboard.view', $permissions, true)) {
        $permissions[] = 'page.dashboard';
    }

    foreach (array_unique($permissions) as $key) {
        if (! DB::table('permissions')->where('key', $key)->exists()) {
            DB::table('permissions')->insert([
                'key' => $key, 'name' => $key, 'group' => 'commission-pilot-test',
                'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
    }

    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

function pilotHardeningPeriod(): CommissionPeriod
{
    return CommissionPeriod::query()->create([
        'label' => 'Pilot period', 'start_at' => now()->startOfMonth(), 'end_at' => now()->addMonth()->startOfMonth(),
        'cycle_day_snapshot' => 10, 'status' => CommissionPeriod::STATUS_OPEN, 'needs_recalculation' => false,
    ]);
}

function pilotHardeningLedger(CommissionPeriod $period, User $seller, int $amount): void
{
    CommissionLedgerEntry::query()->create([
        'commission_period_id' => $period->id, 'seller_id' => $seller->id,
        'invoice_number_snapshot' => (string) Str::uuid(), 'invoice_date_snapshot' => now(),
        'product_name_snapshot' => 'کالای Pilot', 'quantity_snapshot' => 1,
        'gross_amount_snapshot' => $amount * 10, 'discount_amount_snapshot' => 0, 'net_amount_snapshot' => $amount * 10,
        'base_rate_snapshot' => '10.0000', 'campaign_rate_snapshot' => '0.0000', 'effective_rate_snapshot' => '10.0000',
        'base_commission_amount' => $amount, 'campaign_commission_amount' => 0, 'total_commission_amount' => $amount,
        'missing_rate' => false, 'calculation_version' => 1, 'calculation_fingerprint' => hash('sha256', Str::uuid()->toString()),
        'status' => CommissionLedgerEntry::STATUS_ACTIVE, 'active_marker' => 1, 'calculated_at' => now(),
    ]);
}

it('uses fail safe pilot feature defaults', function () {
    $settings = CommissionSetting::current();

    expect($settings->pilot_mode)->toBeTrue()
        ->and($settings->seller_visibility_enabled)->toBeFalse()
        ->and($settings->targets_enabled)->toBeFalse();
});

it('keeps retired seller commission off the dashboard even when pilot visibility is enabled', function () {
    pilotHardeningPeriod();
    $seller = pilotHardeningUser('pilot-hidden-seller', ['dashboard.view', 'page.commercial.commissions'], [
        'name' => 'فروشنده Pilot', 'is_seller' => true, 'is_active' => true, 'can_access_erp' => true,
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $this->actingAs($seller)->get(route('dashboard'))->assertOk()->assertDontSee('seller-commission-widget', false);
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'commission_periods') || str_contains($sql, 'commission_ledger_entries')))->toBeFalse();
    $this->actingAs($seller)->get(route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'));

    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);
    $queries = [];
    $this->actingAs($seller)->get(route('dashboard'))->assertOk()
        ->assertDontSee('seller-commission-widget', false)
        ->assertDontSee('پورسانت دوره جاری');
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'commission_periods') || str_contains($sql, 'commission_ledger_entries')))->toBeFalse()
        ->and(CommissionSetting::current()->seller_visibility_enabled)->toBeTrue()
        ->and($seller->can('page.commercial.commissions'))->toBeTrue();
    $this->actingAs($seller)->get(route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'));
});

it('keeps commission target records while the retired target UI remains unavailable', function () {
    $period = pilotHardeningPeriod();
    $seller = User::factory()->create(['is_seller' => true, 'is_active' => true, 'can_access_erp' => true]);
    CommissionTarget::query()->create([
        'commission_period_id' => $period->id, 'seller_id' => $seller->id, 'target_amount' => 250_000_000,
        'created_by' => $seller->id, 'updated_by' => $seller->id,
    ]);
    $manager = pilotHardeningUser('pilot-target-manager', ['page.commercial.commissions', 'commissions.manage_targets']);

    $this->actingAs($manager)->get(route('commercial.commissions.index', ['period' => $period->id]))
        ->assertRedirect(route('finance.seller-sales.index'));
    expect(CommissionTarget::query()->count())->toBe(1);

    CommissionSetting::current()->update(['targets_enabled' => true]);
    $this->actingAs($manager)->get(route('commercial.commissions.index', ['period' => $period->id]))
        ->assertRedirect(route('finance.seller-sales.index'));
    expect(CommissionTarget::query()->count())->toBe(1)
        ->and(CommissionSetting::current()->targets_enabled)->toBeTrue();
});

it('keeps retired seller document and settlement pages closed regardless of pilot visibility', function () {
    $period = pilotHardeningPeriod();
    $period->update(['status' => CommissionPeriod::STATUS_CLOSED]);
    $seller = pilotHardeningUser('pilot-direct-seller', ['page.commercial.commissions'], [
        'is_seller' => true, 'is_active' => true, 'can_access_erp' => true,
    ]);
    $document = CommissionDocument::query()->create([
        'document_number' => 'PILOT-DOC', 'seller_id' => $seller->id, 'commission_period_id' => $period->id,
        'status' => CommissionDocument::STATUS_FINALIZED, 'created_by' => $seller->id,
    ]);
    $settlement = CommissionSettlement::query()->create([
        'settlement_number' => 'PILOT-SETTLEMENT', 'seller_id' => $seller->id,
        'commission_period_id' => $period->id, 'commission_document_id' => $document->id,
        'net_sales_snapshot' => 1_000_000_000, 'base_commission_snapshot' => 100_000_000,
        'campaign_commission_snapshot' => 0, 'return_reversal_snapshot' => 0,
        'seller_correction_snapshot' => 0, 'manual_adjustment_snapshot' => 0,
        'net_payable' => 100_000_000, 'paid_amount' => 0, 'remaining_amount' => 100_000_000,
        'source_fingerprint' => hash('sha256', 'pilot-settlement'), 'settled_at' => now(), 'created_by' => $seller->id,
    ]);

    $this->actingAs($seller)->get(route('commercial.commissions.documents.show', $document))
        ->assertRedirect(route('finance.seller-sales.index'));
    $this->actingAs($seller)->get(route('commercial.commissions.settlements.show', $settlement))
        ->assertRedirect(route('finance.seller-sales.index'));

    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);
    $this->actingAs($seller)->get(route('commercial.commissions.documents.show', $document))
        ->assertRedirect(route('finance.seller-sales.index'));
    $this->actingAs($seller)->get(route('commercial.commissions.settlements.show', $settlement))
        ->assertRedirect(route('finance.seller-sales.index'));

    $manager = pilotHardeningUser('pilot-payment-manager', ['page.commercial.commissions', 'commissions.view_settlements', 'commissions.record_payments']);
    $this->assertTrue($manager->can('commissions.record_payments'));
    $this->actingAs($manager)->get(route('commercial.commissions.settlements.show', $settlement))
        ->assertRedirect(route('finance.seller-sales.index'));
    $this->actingAs($manager)->post(route('commercial.commissions.settlements.payments.store', $settlement), [
        'amount' => 1_000_000,
    ])->assertStatus(410);
    expect($settlement->fresh()->paid_amount)->toBe(0);
});

it('keeps campaign targets operational when period target ui is disabled', function () {
    CommissionSetting::current()->update(['targets_enabled' => false]);
    $category = Category::query()->create(['name' => 'دسته کمپین Pilot']);
    $actor = User::factory()->create();

    $campaign = app(CommissionCampaignService::class)->save([
        'name' => 'کمپین مستقل', 'bonus_percentage' => '2.5',
        'start_at' => now()->addDay(), 'end_at' => now()->addDays(10),
        'targets' => ['category:'.$category->id],
    ], $actor);

    expect($campaign->targets)->toHaveCount(1)
        ->and(CommissionCampaignTarget::query()->value('target_key'))->toBe('category:'.$category->id)
        ->and(CommissionSetting::current()->targets_enabled)->toBeFalse();
});

it('rejects retired pilot settings writes without changing settings or audit history', function () {
    $manager = pilotHardeningUser('pilot-settings-manager', ['page.commercial.commissions', 'commissions.manage_periods']);

    $this->actingAs($manager)->get(route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'));
    $this->actingAs($manager)->put(route('commercial.commissions.settings.features.update'), [
        'pilot_mode' => 'invalid', 'seller_visibility_enabled' => false, 'targets_enabled' => false,
    ])->assertStatus(410);
    expect(CommissionSetting::current()->pilot_mode)->toBeTrue();

    $this->actingAs($manager)->put(route('commercial.commissions.settings.features.update'), [
        'pilot_mode' => false, 'seller_visibility_enabled' => true, 'targets_enabled' => true,
    ])->assertStatus(410);

    expect(CommissionSetting::current()->fresh()->only(['pilot_mode', 'seller_visibility_enabled', 'targets_enabled']))
        ->toBe(['pilot_mode' => true, 'seller_visibility_enabled' => false, 'targets_enabled' => false])
        ->and(ActivityLog::query()->whereIn('action', [
            'commission_pilot_mode.updated', 'commission_seller_visibility.updated', 'commission_targets_visibility.updated',
        ])->count())->toBe(0);
    $this->actingAs($manager)->get(route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'));
});

it('keeps retired manager commission cards off the dashboard while preserving exact KPI calculations', function () {
    $period = pilotHardeningPeriod();
    $seller = User::factory()->create(['is_seller' => true, 'is_active' => true, 'can_access_erp' => true]);
    pilotHardeningLedger($period, $seller, 60_000_000);
    pilotHardeningLedger($period, $seller, 40_000_000);
    $manager = pilotHardeningUser('pilot-dashboard-manager', ['dashboard.view', 'page.commercial.commissions', 'commissions.view_seller_details']);

    expect($manager->can('commissions.view_seller_details'))->toBeTrue();
    $this->actingAs($manager)->get(route('dashboard'))->assertOk()
        ->assertDontSee('seller-commission-widget', false)
        ->assertDontSee('10,000,000 تومان')
        ->assertDontSee('تأییدشده مالی')
        ->assertDontSee('در انتظار بررسی')
        ->assertDontSee('برگشتی و اصلاحات')
        ->assertDontSee('فروشنده دارای فعالیت')
        ->assertDontSee('مشاهده سیستم پورسانت')
        ->assertDontSee('مجموع تارگت')
        ->assertDontSee('پیشرفت تیم');

    $summary = app(CommissionDashboardService::class)->build($period, null, false);
    expect($summary['team_summary']['sellers_with_calculation_count'])->toBe(1)
        ->and($summary['totals']['calculated_commission'])->toBe(100_000_000)
        ->and($summary['totals']['approved_commission'])->toBe(0)
        ->and($summary['totals']['pending_review_count'])->toBe(0)
        ->and($summary['totals']['returns_and_corrections'])->toBe(0);
});

it('returns exact calculated approved pending and signed correction overview values', function () {
    $period = pilotHardeningPeriod();
    $seller = User::factory()->create(['is_seller' => true, 'is_active' => true, 'can_access_erp' => true]);
    pilotHardeningLedger($period, $seller, 1_333_000_000);
    CommissionCorrectionEntry::query()->create([
        'event_type' => 'return_reversal', 'identity_key' => 'pilot-return',
        'commission_period_id' => $period->id, 'source_period_id' => $period->id, 'seller_id' => $seller->id,
        'quantity_delta' => -1, 'net_amount' => -48_000_000, 'base_commission_amount' => -48_000_000,
        'campaign_commission_amount' => 0, 'total_commission_amount' => -48_000_000,
        'status' => CommissionCorrectionEntry::STATUS_ASSIGNED,
    ]);
    $document = CommissionDocument::query()->create([
        'document_number' => 'PILOT-KPI-DOC', 'seller_id' => $seller->id,
        'commission_period_id' => $period->id, 'status' => CommissionDocument::STATUS_DRAFT, 'created_by' => $seller->id,
    ]);
    foreach ([['approved', 962_000_000], ['pending', 323_000_000]] as [$status, $amount]) {
        CommissionDocumentItem::query()->create([
            'commission_document_id' => $document->id, 'invoice_number_snapshot' => (string) Str::uuid(),
            'invoice_date_snapshot' => now(), 'customer_name_snapshot' => 'مشتری Pilot',
            'seller_id_snapshot' => $seller->id, 'source_period_id' => $period->id,
            'net_sales_snapshot' => $amount * 10, 'base_commission_snapshot' => $amount,
            'campaign_commission_snapshot' => 0, 'total_commission_snapshot' => $amount,
            'ledger_entry_count' => 1, 'calculation_version' => 1,
            'source_fingerprint' => hash('sha256', Str::uuid()->toString()), 'status' => $status,
            'added_by' => $seller->id, 'added_at' => now(),
            'approved_by' => $status === 'approved' ? $seller->id : null,
            'approved_at' => $status === 'approved' ? now() : null,
        ]);
    }

    $summary = app(CommissionDashboardService::class)->build($period, null, false);

    expect($summary['totals']['calculated_commission'])->toBe(1_285_000_000)
        ->and($summary['totals']['approved_commission'])->toBe(962_000_000)
        ->and($summary['totals']['pending_review_count'])->toBe(1)
        ->and($summary['totals']['returns_and_corrections'])->toBe(-48_000_000)
        ->and($summary['team_summary']['sellers_with_calculation_count'])->toBe(1);
});
