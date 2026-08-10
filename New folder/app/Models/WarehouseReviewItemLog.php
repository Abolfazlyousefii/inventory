<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseReviewItemLog extends Model
{
    protected $fillable = [
        'preinvoice_order_id',
        'preinvoice_order_item_id',
        'product_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'product_code_snapshot',
        'old_quantity',
        'new_quantity',
        'approved_quantity',
        'old_price',
        'new_price',
        'stock_at_review',
        'available_stock_at_review',
        'action',
        'reason',
        'note',
        'user_id',
    ];

    protected $casts = [
        'preinvoice_order_item_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'old_quantity' => 'integer',
        'new_quantity' => 'integer',
        'approved_quantity' => 'integer',
        'old_price' => 'integer',
        'new_price' => 'integer',
        'stock_at_review' => 'integer',
        'available_stock_at_review' => 'integer',
        'user_id' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(PreinvoiceOrder::class, 'preinvoice_order_id');
    }

    public function item()
    {
        return $this->belongsTo(PreinvoiceOrderItem::class, 'preinvoice_order_item_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
