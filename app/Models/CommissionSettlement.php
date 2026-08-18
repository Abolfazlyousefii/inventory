<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionSettlement extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CREDIT_CARRIED = 'credit_carried';

    public const STATUS_ZERO = 'zero';

    protected $guarded = [];

    protected $casts = [
        'net_sales_snapshot' => 'integer', 'base_commission_snapshot' => 'integer', 'campaign_commission_snapshot' => 'integer',
        'return_reversal_snapshot' => 'integer', 'seller_correction_snapshot' => 'integer', 'manual_adjustment_snapshot' => 'integer',
        'net_payable' => 'integer', 'paid_amount' => 'integer', 'remaining_amount' => 'integer', 'carry_forward_created' => 'boolean',
        'settled_at' => 'datetime', 'fully_paid_at' => 'datetime',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function document()
    {
        return $this->belongsTo(CommissionDocument::class, 'commission_document_id');
    }

    public function payments()
    {
        return $this->hasMany(CommissionPayment::class);
    }
}
