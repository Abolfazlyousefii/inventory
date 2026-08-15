<?php

namespace App\Services\Commissions;

use App\Models\CommissionAdjustment;
use App\Models\CommissionDocument;
use App\Models\CommissionDocumentAdjustment;
use App\Models\CommissionPeriod;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionAdjustmentService
{
    public function create(array $data, User $actor): CommissionAdjustment
    {
        return DB::transaction(function () use ($data, $actor) {
            $period = CommissionPeriod::query()->lockForUpdate()->findOrFail($data['commission_period_id']);
            $this->assertMutable($period);
            $amount = (int) $data['amount'];
            if ($amount === 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ تعدیل نمی‌تواند صفر باشد.']);
            }
            if (trim((string) $data['reason']) === '') {
                throw ValidationException::withMessages(['reason' => 'دلیل تعدیل الزامی است.']);
            }
            $adjustment = CommissionAdjustment::query()->create([
                'seller_id' => $data['seller_id'], 'commission_period_id' => $period->id,
                'source_period_id' => $data['source_period_id'] ?? null, 'source_type' => CommissionAdjustment::SOURCE_MANUAL,
                'type' => 'manual', 'amount' => $amount, 'reason' => trim($data['reason']),
                'source_reference' => $data['source_reference'] ?? null, 'status' => CommissionAdjustment::STATUS_PENDING,
                'created_by' => $actor->id,
            ]);
            $this->attachToDocument($adjustment, $actor);
            ActivityLogger::log('manual_adjustment_created', $adjustment, 'تعدیل دستی پورسانت ایجاد شد.', ['amount' => $amount]);

            return $adjustment->fresh();
        }, 3);
    }

    public function review(CommissionDocumentAdjustment $row, User $actor, bool $approve, ?string $reason = null): CommissionDocumentAdjustment
    {
        return DB::transaction(function () use ($row, $actor, $approve, $reason) {
            $row = CommissionDocumentAdjustment::query()->with(['document.period', 'adjustment'])->lockForUpdate()->findOrFail($row->id);
            $this->assertMutable($row->document->period);
            if ($row->document->status !== CommissionDocument::STATUS_DRAFT) {
                throw ValidationException::withMessages(['document' => 'سند نهایی‌شده قابل تغییر نیست.']);
            }
            if (! $approve && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'دلیل رد تعدیل الزامی است.']);
            }
            $attributes = $approve
                ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null]
                : ['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => trim((string) $reason)];
            $row->update($attributes);
            $row->adjustment->update($attributes);
            ActivityLogger::log($approve ? 'manual_adjustment_approved' : 'manual_adjustment_rejected', $row->adjustment, $approve ? 'تعدیل پورسانت تأیید شد.' : 'تعدیل پورسانت رد شد.', ['reason' => $reason]);

            return $row->fresh();
        }, 3);
    }

    public function attachToDocument(CommissionAdjustment $adjustment, ?User $actor = null): ?CommissionDocumentAdjustment
    {
        $document = CommissionDocument::query()->where('seller_id', $adjustment->seller_id)
            ->where('commission_period_id', $adjustment->commission_period_id)->first();
        if (! $document || $document->status !== CommissionDocument::STATUS_DRAFT) {
            return null;
        }
        $fingerprint = $this->fingerprint($adjustment);

        return CommissionDocumentAdjustment::query()->firstOrCreate(
            ['commission_document_id' => $document->id, 'commission_adjustment_id' => $adjustment->id],
            ['amount_snapshot' => $adjustment->amount, 'type_snapshot' => $adjustment->type, 'reason_snapshot' => $adjustment->reason,
                'source_fingerprint' => $fingerprint, 'status' => $adjustment->status, 'is_stale' => false,
                'added_by' => $actor?->id ?? $adjustment->created_by, 'added_at' => now()],
        );
    }

    public function refreshDocument(CommissionDocument $document, User $actor): int
    {
        $changed = 0;
        foreach (CommissionAdjustment::query()->where('seller_id', $document->seller_id)->where('commission_period_id', $document->commission_period_id)->get() as $adjustment) {
            $row = $document->adjustments()->where('commission_adjustment_id', $adjustment->id)->first();
            if (! $row) {
                $this->attachToDocument($adjustment, $actor);
                $changed++;
            } elseif ($row->source_fingerprint !== $this->fingerprint($adjustment)) {
                $row->update(['amount_snapshot' => $adjustment->amount, 'reason_snapshot' => $adjustment->reason,
                    'source_fingerprint' => $this->fingerprint($adjustment), 'status' => 'pending', 'is_stale' => false,
                    'approved_by' => null, 'approved_at' => null]);
                $changed++;
            }
        }

        return $changed;
    }

    public function createCarryForward(int $sellerId, CommissionPeriod $source, CommissionPeriod $target, int $amount, int $settlementId, ?int $actorId): CommissionAdjustment
    {
        if ($amount >= 0) {
            throw ValidationException::withMessages(['amount' => 'فقط مانده منفی قابل انتقال است.']);
        }
        $identity = "carry-forward:settlement:{$settlementId}";
        $adjustment = CommissionAdjustment::query()->firstOrCreate(['identity_key' => $identity], [
            'seller_id' => $sellerId, 'commission_period_id' => $target->id, 'source_period_id' => $source->id,
            'source_type' => CommissionAdjustment::SOURCE_SYSTEM, 'type' => CommissionAdjustment::TYPE_CARRY_FORWARD,
            'amount' => $amount, 'reason' => "انتقال مانده منفی دوره {$source->label}", 'source_reference' => (string) $settlementId,
            'status' => CommissionAdjustment::STATUS_APPROVED, 'created_by' => $actorId, 'approved_by' => $actorId, 'approved_at' => now(),
        ]);
        $this->attachToDocument($adjustment);

        return $adjustment;
    }

    private function assertMutable(CommissionPeriod $period): void
    {
        if (! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
            throw ValidationException::withMessages(['period' => 'ثبت یا بررسی تعدیل در دوره بسته/پرداخت‌شده مجاز نیست.']);
        }
    }

    private function fingerprint(CommissionAdjustment $adjustment): string
    {
        return hash('sha256', json_encode([$adjustment->id, $adjustment->amount, $adjustment->reason, $adjustment->updated_at?->format('U.u')]));
    }
}
