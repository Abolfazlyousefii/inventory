<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnCorrection extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'before_snapshot' => 'array', 'proposed_snapshot' => 'array', 'after_snapshot' => 'array', 'warehouse_approved_at' => 'datetime', 'finance_approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(SalesReturnDocument::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function warehouseApprover()
    {
        return $this->belongsTo(User::class, 'warehouse_approved_by');
    }

    public function financeApprover()
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public static function statusLabels(): array
    {
        return ['pending_warehouse' => 'در انتظار بررسی انبار', 'pending_finance' => 'در انتظار تأیید مالی', 'applied' => 'اصلاح اعمال شد', 'rejected' => 'رد شده'];
    }
}
