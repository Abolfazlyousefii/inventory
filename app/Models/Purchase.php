<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'user_id',
        'total_amount',
        'subtotal_amount',
        'discount_type',
        'discount_value',
        'total_discount',
        'purchased_at',
        'note',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'total_amount' => 'integer',
        'subtotal_amount' => 'integer',
        'discount_value' => 'integer',
        'total_discount' => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
