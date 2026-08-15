<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPayment extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_VOID = 'void';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];

    public function settlement()
    {
        return $this->belongsTo(CommissionSettlement::class, 'commission_settlement_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
