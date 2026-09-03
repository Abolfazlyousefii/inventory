<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionLedgerEntry extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $guarded = [];

    protected $casts = [
        'invoice_date_snapshot' => 'datetime',
        'base_rate_snapshot' => 'decimal:4',
        'campaign_rate_snapshot' => 'decimal:4',
        'effective_rate_snapshot' => 'decimal:4',
        'gross_amount_snapshot' => 'integer',
        'discount_amount_snapshot' => 'integer',
        'net_amount_snapshot' => 'integer',
        'base_commission_amount' => 'integer',
        'campaign_commission_amount' => 'integer',
        'total_commission_amount' => 'integer',
        'missing_rate' => 'boolean',
        'metadata' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('active_marker', 1);
    }

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
