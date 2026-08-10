<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseReviewSnapshot extends Model
{
    public const TYPE_BEFORE = 'before_review';
    public const TYPE_AFTER = 'after_review';

    protected $fillable = [
        'preinvoice_order_id',
        'type',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_by' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
