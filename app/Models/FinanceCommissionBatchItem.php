<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceCommissionBatchItem extends Model
{
    protected $fillable = [
        'batch_id',
        'invoice_id',
        'invoice_uuid',
        'invoice_date',
        'customer_name',
        'customer_mobile',
        'invoice_total',
        'invoice_status',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'invoice_total' => 'integer',
    ];

    public function batch()
    {
        return $this->belongsTo(FinanceCommissionBatch::class, 'batch_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
