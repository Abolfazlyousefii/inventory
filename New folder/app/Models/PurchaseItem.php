<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $casts = [
        'line_subtotal' => 'integer',
        'discount_value' => 'integer',
        'discount_amount' => 'integer',
        'line_total' => 'integer',
    ];

    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'product_code',
        'variant_name',
        'quantity',
        'buy_price',
        'sell_price',
        'line_subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'line_total',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
