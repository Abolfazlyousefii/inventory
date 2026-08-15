<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class CommissionPeriod extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEW = 'review';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_PAID = 'paid';

    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime', 'end_at' => 'datetime', 'cycle_day_snapshot' => 'integer', 'needs_recalculation' => 'boolean',
        'review_started_at' => 'datetime', 'closed_at' => 'datetime', 'paid_at' => 'datetime',
        'total_net_sales_snapshot' => 'integer', 'base_commission_snapshot' => 'integer', 'campaign_commission_snapshot' => 'integer',
        'return_reversal_snapshot' => 'integer', 'seller_correction_snapshot' => 'integer', 'manual_adjustment_snapshot' => 'integer',
        'approved_commission_snapshot' => 'integer', 'seller_count_snapshot' => 'integer', 'document_count_snapshot' => 'integer',
    ];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'باز',
            self::STATUS_REVIEW => 'در حال بررسی',
            self::STATUS_CLOSED => 'بسته',
            self::STATUS_PAID => 'پرداخت‌شده',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statusLabels()[$this->display_status] ?? $this->display_status;
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === self::STATUS_PAID && $this->contains(now()) && ! $this->hasValidPaidState()) {
            return self::STATUS_OPEN;
        }

        return $this->status;
    }

    public function hasValidPaidState(): bool
    {
        if ($this->status !== self::STATUS_PAID || ! $this->closed_at || ! $this->paid_at) {
            return false;
        }

        if (! $this->relationLoaded('settlements')) {
            $this->setRelation('settlements', $this->settlements()->get());
        }

        return $this->settlements->isNotEmpty()
            && $this->settlements->every(fn (CommissionSettlement $settlement): bool => match (true) {
                $settlement->net_payable > 0 => $settlement->status === CommissionSettlement::STATUS_PAID && $settlement->remaining_amount === 0,
                $settlement->net_payable < 0 => $settlement->carry_forward_created,
                default => $settlement->status === CommissionSettlement::STATUS_ZERO,
            });
    }

    public function contains(CarbonInterface $moment): bool
    {
        return $moment->gte($this->start_at) && $moment->lt($this->end_at);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(CommissionLedgerEntry::class);
    }

    public function calculationWarnings()
    {
        return $this->hasMany(CommissionCalculationWarning::class);
    }

    public function commissionDocuments()
    {
        return $this->hasMany(CommissionDocument::class);
    }

    public function correctionEntries()
    {
        return $this->hasMany(CommissionCorrectionEntry::class);
    }

    public function adjustments()
    {
        return $this->hasMany(CommissionAdjustment::class);
    }

    public function settlements()
    {
        return $this->hasMany(CommissionSettlement::class);
    }

    public function events()
    {
        return $this->hasMany(CommissionPeriodEvent::class)->latest('created_at');
    }

    public function targets()
    {
        return $this->hasMany(CommissionTarget::class);
    }
}
