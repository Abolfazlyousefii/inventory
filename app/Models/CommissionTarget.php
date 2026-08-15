<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionTarget extends Model
{
    protected $guarded = [];

    protected $casts = ['target_amount' => 'integer'];

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function period()
    {
        return $this->belongsTo(CommissionPeriod::class, 'commission_period_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
