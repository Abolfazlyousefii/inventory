<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreinvoiceOrderItem extends Model
{
    protected $fillable = [
        'preinvoice_order_id',
        'product_id',
        'variant_id',
        'quantity',
        'price',
        'line_total',
        'sort_order',
        'line_discount_amount',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'integer',
        'line_total' => 'integer',
        'sort_order' => 'integer',
        'line_discount_amount' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }


    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
