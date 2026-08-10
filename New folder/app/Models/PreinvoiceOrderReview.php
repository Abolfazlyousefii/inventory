<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreinvoiceOrderReview extends Model
{
    protected $fillable = [
        'preinvoice_order_id',
        'user_id',
        'action',
        'reason',
        'before_items',
        'after_items',
    ];

    protected $casts = [
        'before_items' => 'array',
        'after_items' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
