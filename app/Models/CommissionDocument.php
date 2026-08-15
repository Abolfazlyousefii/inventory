<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionDocument extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $guarded = [];

    protected $casts = [
        'needs_recalculation' => 'boolean', 'finalized_at' => 'datetime', 'final_net_sales' => 'integer',
        'final_base_commission' => 'integer', 'final_campaign_commission' => 'integer', 'final_correction_amount' => 'integer',
        'final_adjustment_amount' => 'integer', 'final_commission_total' => 'integer',
    ];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function items()
    {
        return $this->hasMany(CommissionDocumentItem::class);
    }

    public function events()
    {
        return $this->hasMany(CommissionDocumentEvent::class)->latest('created_at');
    }

    public function corrections()
    {
        return $this->hasMany(CommissionDocumentCorrection::class);
    }

    public function adjustments()
    {
        return $this->hasMany(CommissionDocumentAdjustment::class);
    }

    public function settlement()
    {
        return $this->hasOne(CommissionSettlement::class);
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
