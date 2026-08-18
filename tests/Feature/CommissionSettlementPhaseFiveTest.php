<?php

use App\Models\CommissionAdjustment;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionDocument;
use App\Models\CommissionDocumentCorrection;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use App\Models\CommissionSetting;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\Commissions\CommissionAdjustmentService;
use App\Services\Commissions\CommissionDocumentService;
use App\Services\Commissions\CommissionPaymentService;
use App\Services\Commissions\CommissionPeriodWorkflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function p5User(string $name = 'Finance'): User
{
    return User::factory()->create(['name' => $name, 'is_active' => true, 'can_access_erp' => true, 'is_seller' => true]);
}

function p5PageUser(?string $action = null): User
{
    $role = Role::findOrCreate('P5-'.($action ?: 'viewer'), 'web');
    $role->givePermissionTo(Permission::query()->where('key', 'page.commercial.commissions')->firstOrFail());
    if ($action) {
        $role->givePermissionTo(Permission::query()->where('key', $action)->firstOrFail());
    }
    $user = p5User('P5 '.($action ?: 'Viewer'));
    $user->assignRole($role);

    return $user;
}

function p5Period(string $status = 'open', string $start = '2026-08-01', string $end = '2026-09-01'): CommissionPeriod
{
    return CommissionPeriod::query()->create(['label' => "$start/$end", 'start_at' => $start, 'end_at' => $end, 'cycle_day_snapshot' => 10, 'status' => $status, 'needs_recalculation' => false]);
}

function p5Ledger(CommissionPeriod $period, User $seller, int $base = 10_000_000, int $campaign = 2_000_000): CommissionLedgerEntry
{
    return CommissionLedgerEntry::query()->create(['commission_period_id' => $period->id, 'seller_id' => $seller->id,
        'invoice_number_snapshot' => 'P5-SALE', 'invoice_date_snapshot' => $period->start_at->copy()->addDay(),
        'product_name_snapshot' => 'P5', 'quantity_snapshot' => 1, 'gross_amount_snapshot' => 100_000_000,
        'discount_amount_snapshot' => 0, 'net_amount_snapshot' => 100_000_000, 'base_rate_snapshot' => 10,
        'campaign_rate_snapshot' => 2, 'effective_rate_snapshot' => 12, 'base_commission_amount' => $base,
        'campaign_commission_amount' => $campaign, 'total_commission_amount' => $base + $campaign,
        'missing_rate' => false, 'calculation_version' => 1, 'calculation_fingerprint' => hash('sha256', uniqid('', true)),
        'status' => 'active', 'active_marker' => 1, 'calculated_at' => now()]);
}

function p5Document(CommissionPeriod $period, User $seller, User $actor, int $base = 10_000_000, int $campaign = 2_000_000): CommissionDocument
{
    $document = CommissionDocument::query()->create(['document_number' => 'COM-P5-'.$seller->id, 'seller_id' => $seller->id,
        'commission_period_id' => $period->id, 'status' => 'draft', 'needs_recalculation' => false,
        'created_by' => $actor->id, 'updated_by' => $actor->id]);
    CommissionDocumentItem::query()->create(['commission_document_id' => $document->id, 'invoice_number_snapshot' => 'P5-SALE',
        'invoice_date_snapshot' => $period->start_at->copy()->addDay(), 'customer_name_snapshot' => 'Customer',
        'seller_id_snapshot' => $seller->id, 'source_period_id' => $period->id, 'net_sales_snapshot' => 100_000_000,
        'base_commission_snapshot' => $base, 'campaign_commission_snapshot' => $campaign,
        'total_commission_snapshot' => $base + $campaign, 'ledger_entry_count' => 1, 'calculation_version' => 1,
        'source_fingerprint' => hash('sha256', 'item-'.$document->id), 'status' => 'approved', 'is_stale' => false,
        'added_by' => $actor->id, 'added_at' => now(), 'approved_by' => $actor->id, 'approved_at' => now()]);

    return $document->fresh();
}

function p5Correction(CommissionDocument $document, User $seller, string $type, int $amount): void
{
    $entry = CommissionCorrectionEntry::query()->create(['event_type' => $type, 'identity_key' => uniqid('p5-', true),
        'commission_period_id' => $document->commission_period_id, 'seller_id' => $seller->id,
        'quantity_delta' => 0, 'net_amount' => 0, 'base_commission_amount' => $amount,
        'campaign_commission_amount' => 0, 'total_commission_amount' => $amount, 'status' => 'assigned']);
    CommissionDocumentCorrection::query()->create(['commission_document_id' => $document->id,
        'commission_correction_entry_id' => $entry->id, 'type' => $type, 'description' => $type,
        'base_amount' => $amount, 'campaign_amount' => 0, 'total_amount' => $amount,
        'source_fingerprint' => hash('sha256', 'correction-'.$entry->id), 'status' => 'approved', 'is_stale' => false, 'added_at' => now()]);
}

function p5Adjustment(CommissionDocument $document, User $seller, int $amount, string $status = 'approved'): CommissionAdjustment
{
    $adjustment = CommissionAdjustment::query()->create(['seller_id' => $seller->id, 'commission_period_id' => $document->commission_period_id,
        'source_type' => 'manual', 'type' => 'manual', 'amount' => $amount, 'reason' => 'P5 adjustment', 'status' => $status]);
    $document->adjustments()->create(['commission_adjustment_id' => $adjustment->id, 'amount_snapshot' => $amount,
        'type_snapshot' => 'manual', 'reason_snapshot' => 'P5 adjustment', 'source_fingerprint' => hash('sha256', 'adjustment-'.$adjustment->id),
        'status' => $status, 'is_stale' => false, 'added_at' => now()]);

    return $adjustment;
}

it('moves only a clean open period to review and blocks dirty periods', function () {
    $actor = p5User();
    $period = p5Period();
    $service = app(CommissionPeriodWorkflowService::class);
    expect($service->startReview($period, $actor)->status)->toBe('review');
    $dirty = p5Period('open', '2026-09-01', '2026-10-01');
    $dirty->update(['needs_recalculation' => true]);
    expect(fn () => $service->startReview($dirty, $actor))->toThrow(ValidationException::class);
});

it('does not close or mark paid an empty period through vacuous workflow checks', function () {
    $actor = p5User();
    $review = p5Period('review');
    $closed = p5Period('closed', '2026-09-01', '2026-10-01');
    $service = app(CommissionPeriodWorkflowService::class);

    expect(fn () => $service->close($review, $actor))->toThrow(ValidationException::class)
        ->and(fn () => $service->markPaid($closed, $actor))->toThrow(ValidationException::class)
        ->and($review->fresh()->status)->toBe(CommissionPeriod::STATUS_REVIEW)
        ->and($closed->fresh()->status)->toBe(CommissionPeriod::STATUS_CLOSED);
});

it('never presents an invalid active paid period as paid but preserves a valid paid state', function () {
    Carbon::setTestNow('2026-08-15 12:00:00');
    $actor = p5User();
    $period = p5Period('paid');
    $period->update(['closed_at' => now()->subMinute(), 'paid_at' => now()]);

    expect($period->fresh()->display_status)->toBe(CommissionPeriod::STATUS_OPEN)
        ->and($period->fresh()->status_label)->toBe('باز');

    $document = CommissionDocument::query()->create([
        'document_number' => 'COM-VALID-ZERO', 'seller_id' => $actor->id,
        'commission_period_id' => $period->id, 'status' => CommissionDocument::STATUS_FINALIZED,
        'final_commission_total' => 0, 'created_by' => $actor->id,
    ]);
    CommissionSettlement::query()->create([
        'settlement_number' => 'SET-VALID-ZERO', 'seller_id' => $actor->id,
        'commission_period_id' => $period->id, 'commission_document_id' => $document->id,
        'net_sales_snapshot' => 0, 'base_commission_snapshot' => 0, 'campaign_commission_snapshot' => 0,
        'return_reversal_snapshot' => 0, 'seller_correction_snapshot' => 0, 'manual_adjustment_snapshot' => 0,
        'net_payable' => 0, 'paid_amount' => 0, 'remaining_amount' => 0,
        'status' => CommissionSettlement::STATUS_ZERO, 'source_fingerprint' => hash('sha256', 'valid-zero'),
        'settled_at' => now(),
    ]);

    expect($period->fresh()->display_status)->toBe(CommissionPeriod::STATUS_PAID)
        ->and($period->fresh()->status_label)->toBe('پرداخت‌شده');
});

it('finalizes only fully reviewed documents and makes financial decisions immutable', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller);
    $document = p5Document($period, $seller, $actor);
    $document->items()->first()->update(['status' => 'pending']);
    expect(fn () => app(CommissionDocumentService::class)->finalize($document, $actor))->toThrow(ValidationException::class);
    $document->items()->first()->update(['status' => 'approved']);
    $final = app(CommissionDocumentService::class)->finalize($document->fresh(), $actor);
    expect($final->status)->toBe('finalized')->and($final->final_commission_total)->toBe(12_000_000);
    expect(fn () => app(CommissionDocumentService::class)->remove($final->items()->first(), $actor, 'change'))->toThrow(ValidationException::class);
});

it('blocks close for missing or unfinished seller documents', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller);
    $service = app(CommissionPeriodWorkflowService::class);
    expect($service->closeBlockers($period))->toContain("فروشنده {$seller->name} فعالیت پورسانتی دارد اما سند ندارد.");
    p5Document($period, $seller, $actor);
    expect(fn () => $service->close($period, $actor))->toThrow(ValidationException::class);
});

it('closes atomically with an exact decomposed settlement snapshot and is idempotent', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller);
    $document = p5Document($period, $seller, $actor);
    p5Correction($document, $seller, 'return_reversal', -1_000_000);
    p5Correction($document, $seller, 'seller_reassignment_correction', 500_000);
    p5Adjustment($document, $seller, -200_000);
    app(CommissionDocumentService::class)->finalize($document->fresh(), $actor);
    $service = app(CommissionPeriodWorkflowService::class);
    $service->close($period, $actor);
    $service->close($period->fresh(), $actor);
    $settlement = CommissionSettlement::query()->sole();
    expect($settlement->base_commission_snapshot)->toBe(10_000_000)
        ->and($settlement->campaign_commission_snapshot)->toBe(2_000_000)
        ->and($settlement->return_reversal_snapshot)->toBe(-1_000_000)
        ->and($settlement->seller_correction_snapshot)->toBe(500_000)
        ->and($settlement->manual_adjustment_snapshot)->toBe(-200_000)
        ->and($settlement->net_payable)->toBe(11_300_000)
        ->and(CommissionSettlement::query()->count())->toBe(1)
        ->and($period->fresh()->approved_commission_snapshot)->toBe(11_300_000);
});

it('rolls back every settlement and the period transition when one seller snapshot fails', function () {
    $actor = p5User();
    $firstSeller = p5User('First Seller');
    $secondSeller = p5User('Second Seller');
    $period = p5Period('review');
    p5Ledger($period, $firstSeller, 1_000, 0);
    p5Ledger($period, $secondSeller, 2_000, 0);
    $first = p5Document($period, $firstSeller, $actor, 1_000, 0);
    $second = p5Document($period, $secondSeller, $actor, 2_000, 0);
    $documents = app(CommissionDocumentService::class);
    $documents->finalize($first, $actor);
    $documents->finalize($second, $actor);
    DB::statement("CREATE TRIGGER p5_fail_second_settlement BEFORE INSERT ON commission_settlements WHEN NEW.seller_id = {$secondSeller->id} BEGIN SELECT RAISE(ABORT, 'simulated settlement failure'); END");

    $failed = false;
    try {
        app(CommissionPeriodWorkflowService::class)->close($period, $actor);
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(CommissionSettlement::query()->count())->toBe(0)
        ->and($period->fresh()->status)->toBe(CommissionPeriod::STATUS_REVIEW)
        ->and($period->fresh()->closed_at)->toBeNull();
});

it('creates an idempotent system carry forward for a negative settlement', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    $next = p5Period('open', '2026-09-01', '2026-10-01');
    $document = CommissionDocument::query()->create(['document_number' => 'COM-NEG', 'seller_id' => $seller->id, 'commission_period_id' => $period->id,
        'status' => 'draft', 'needs_recalculation' => false, 'created_by' => $actor->id]);
    p5Adjustment($document, $seller, -1_000_000);
    app(CommissionDocumentService::class)->finalize($document->fresh(), $actor);
    app(CommissionPeriodWorkflowService::class)->close($period, $actor);
    $settlement = CommissionSettlement::query()->sole();
    $carry = CommissionAdjustment::query()->where('type', 'carry_forward')->sole();
    expect($settlement->net_payable)->toBe(-1_000_000)->and($settlement->carry_forward_created)->toBeTrue()
        ->and($carry->commission_period_id)->toBe($next->id)->and($carry->amount)->toBe(-1_000_000)
        ->and($carry->source_period_id)->toBe($period->id);
});

it('records partial and full payments idempotently rejects overpayment and marks period paid', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller, 10_000_000, 0);
    $document = p5Document($period, $seller, $actor, 10_000_000, 0);
    app(CommissionDocumentService::class)->finalize($document, $actor);
    $workflow = app(CommissionPeriodWorkflowService::class);
    $workflow->close($period, $actor);
    $settlement = CommissionSettlement::query()->sole();
    $payments = app(CommissionPaymentService::class);
    $first = ['amount' => 4_000_000, 'paid_at' => now(), 'idempotency_key' => 'p5-pay-1'];
    $payments->record($settlement, $first, $actor);
    $payments->record($settlement->fresh(), $first, $actor);
    expect($settlement->fresh()->status)->toBe('partially_paid')->and($settlement->fresh()->remaining_amount)->toBe(6_000_000)
        ->and(CommissionPayment::query()->count())->toBe(1);
    expect(fn () => $payments->record($settlement->fresh(), ['amount' => 7_000_000, 'paid_at' => now()], $actor))->toThrow(ValidationException::class);
    $payments->record($settlement->fresh(), ['amount' => 6_000_000, 'paid_at' => now()], $actor);
    expect($settlement->fresh()->status)->toBe('paid')->and($settlement->fresh()->remaining_amount)->toBe(0);
    expect($workflow->markPaid($period->fresh(), $actor)->status)->toBe('paid');
});

it('validates manual adjustments reviews them in documents and blocks closed periods', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period();
    $document = CommissionDocument::query()->create(['document_number' => 'COM-ADJ', 'seller_id' => $seller->id, 'commission_period_id' => $period->id,
        'status' => 'draft', 'created_by' => $actor->id]);
    $service = app(CommissionAdjustmentService::class);
    expect(fn () => $service->create(['seller_id' => $seller->id, 'commission_period_id' => $period->id, 'amount' => 0, 'reason' => 'x'], $actor))->toThrow(ValidationException::class);
    $adjustment = $service->create(['seller_id' => $seller->id, 'commission_period_id' => $period->id, 'amount' => 500_000, 'reason' => 'bonus'], $actor);
    $row = $document->adjustments()->sole();
    expect($adjustment->status)->toBe('pending')->and(app(CommissionDocumentService::class)->totals($document)['approved_adjustment'])->toBe(0);
    $service->review($row, $actor, true);
    expect(app(CommissionDocumentService::class)->totals($document)['approved_adjustment'])->toBe(500_000);
    $period->update(['status' => 'closed']);
    expect(fn () => $service->create(['seller_id' => $seller->id, 'commission_period_id' => $period->id, 'amount' => -1, 'reason' => 'late'], $actor))->toThrow(ValidationException::class);
});

it('audits settlements in dry run and apply only repairs deterministic payment caches', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('closed');
    $document = CommissionDocument::query()->create(['document_number' => 'COM-AUDIT', 'seller_id' => $seller->id, 'commission_period_id' => $period->id,
        'status' => 'finalized', 'final_commission_total' => 1000, 'created_by' => $actor->id]);
    $settlement = CommissionSettlement::query()->create(['settlement_number' => 'SET-COM-999999', 'seller_id' => $seller->id,
        'commission_period_id' => $period->id, 'commission_document_id' => $document->id, 'net_sales_snapshot' => 0,
        'base_commission_snapshot' => 1000, 'campaign_commission_snapshot' => 0, 'return_reversal_snapshot' => 0,
        'seller_correction_snapshot' => 0, 'manual_adjustment_snapshot' => 0, 'net_payable' => 1000,
        'paid_amount' => 0, 'remaining_amount' => 1000, 'status' => 'unpaid', 'source_fingerprint' => hash('sha256', 'audit'), 'settled_at' => now()]);
    CommissionPayment::query()->create(['commission_settlement_id' => $settlement->id, 'seller_id' => $seller->id,
        'commission_period_id' => $period->id, 'amount' => 400, 'paid_at' => now(), 'status' => 'recorded', 'created_by' => $actor->id]);
    $this->artisan('commissions:audit-settlements')->assertSuccessful();
    expect($settlement->fresh()->paid_amount)->toBe(0);
    $this->artisan('commissions:audit-settlements --apply')->assertSuccessful();
    expect($settlement->fresh()->paid_amount)->toBe(400)->and($settlement->fresh()->remaining_amount)->toBe(600)->and($settlement->fresh()->status)->toBe('partially_paid');
});

it('creates an auditable zero settlement and never creates a payment for it', function () {
    $actor = p5User();
    $seller = p5User('Zero Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller, 0, 0);
    $document = p5Document($period, $seller, $actor, 0, 0);
    app(CommissionDocumentService::class)->finalize($document, $actor);
    app(CommissionPeriodWorkflowService::class)->close($period, $actor);
    $settlement = CommissionSettlement::query()->sole();
    expect($settlement->net_payable)->toBe(0)->and($settlement->status)->toBe('zero')->and($settlement->remaining_amount)->toBe(0);
    expect(fn () => app(CommissionPaymentService::class)->record($settlement, ['amount' => 1, 'paid_at' => now()], $actor))->toThrow(ValidationException::class);
});

it('keeps voided payment history recalculates balances and forbids void after period paid', function () {
    $actor = p5User();
    $seller = p5User('Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller, 1_000, 0);
    $document = p5Document($period, $seller, $actor, 1_000, 0);
    app(CommissionDocumentService::class)->finalize($document, $actor);
    $workflow = app(CommissionPeriodWorkflowService::class);
    $workflow->close($period, $actor);
    $settlement = CommissionSettlement::query()->sole();
    $service = app(CommissionPaymentService::class);
    $partial = $service->record($settlement, ['amount' => 400, 'paid_at' => now()], $actor);
    $service->void($partial, 'ثبت اشتباه', $actor);
    expect($partial->fresh()->status)->toBe('void')->and($settlement->fresh()->paid_amount)->toBe(0)->and($settlement->fresh()->status)->toBe('unpaid');
    $full = $service->record($settlement->fresh(), ['amount' => 1_000, 'paid_at' => now()], $actor);
    $workflow->markPaid($period->fresh(), $actor);
    expect(fn () => $service->void($full, 'late error', $actor))->toThrow(ValidationException::class);
});

it('reports pending correction and adjustment blockers explicitly', function () {
    $actor = p5User();
    $seller = p5User('Blocked Seller');
    $period = p5Period('review');
    p5Ledger($period, $seller);
    $document = p5Document($period, $seller, $actor);
    p5Correction($document, $seller, 'return_reversal', -100);
    $document->corrections()->update(['status' => 'pending']);
    p5Adjustment($document, $seller, 100, 'pending');
    $blockers = app(CommissionPeriodWorkflowService::class)->closeBlockers($period);
    expect(collect($blockers)->contains(fn ($value) => str_contains($value, 'اصلاح pending')))->toBeTrue()
        ->and(collect($blockers)->contains(fn ($value) => str_contains($value, 'تعدیل pending')))->toBeTrue();
});

it('enforces workflow permissions seller isolation and settlement print contract', function () {
    $period = p5Period();
    $this->actingAs(p5PageUser())->post(route('commercial.commissions.periods.review', $period))->assertForbidden();
    $this->actingAs(p5PageUser('commissions.close_periods'))->post(route('commercial.commissions.periods.review', $period))->assertRedirect();

    $sellerA = p5PageUser();
    $sellerB = p5PageUser();
    $closed = p5Period('closed', '2026-09-01', '2026-10-01');
    $document = CommissionDocument::query()->create(['document_number' => 'COM-PRINT', 'seller_id' => $sellerA->id, 'commission_period_id' => $closed->id,
        'status' => 'finalized', 'final_commission_total' => 500, 'created_by' => $sellerA->id]);
    $settlement = CommissionSettlement::query()->create(['settlement_number' => 'SET-COM-000500', 'seller_id' => $sellerA->id,
        'commission_period_id' => $closed->id, 'commission_document_id' => $document->id, 'net_sales_snapshot' => 0,
        'base_commission_snapshot' => 500, 'campaign_commission_snapshot' => 0, 'return_reversal_snapshot' => 0,
        'seller_correction_snapshot' => 0, 'manual_adjustment_snapshot' => 0, 'net_payable' => 500,
        'paid_amount' => 0, 'remaining_amount' => 500, 'status' => 'unpaid', 'source_fingerprint' => hash('sha256', 'print'), 'settled_at' => now()]);
    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);
    $this->actingAs($sellerA)->get(route('commercial.commissions.settlements.print', $settlement))->assertOk()
        ->assertSee('SET-COM-000500')->assertSee('تسویه پورسانت فروشنده')->assertSee('مانده');
    $this->actingAs($sellerB)->get(route('commercial.commissions.settlements.show', $settlement))->assertForbidden();
});
