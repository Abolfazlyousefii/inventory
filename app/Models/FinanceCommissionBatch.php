<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCommissionBatch extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'visitor_id',
        'from_date',
        'to_date',
        'invoice_count',
        'total_amount',
        'approved_by',
        'approved_at',
        'status',
        'note',
        'voided_at',
        'voided_by',
        'void_note',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'invoice_count' => 'integer',
        'total_amount' => 'integer',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(FinanceCommissionBatchItem::class, 'batch_id');
    }

    public function visitor()
    {
        return $this->belongsTo(User::class, 'visitor_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
