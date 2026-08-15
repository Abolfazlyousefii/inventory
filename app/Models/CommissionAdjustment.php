<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionAdjustment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SYSTEM = 'system';

    public const TYPE_CARRY_FORWARD = 'carry_forward';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function sourcePeriod()
    {
        return $this->belongsTo(CommissionPeriod::class, 'source_period_id');
    }

    public function documentRows()
    {
        return $this->hasMany(CommissionDocumentAdjustment::class);
    }
}
