<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountDocumentItem extends Model
{
    protected $fillable = [
        'document_id',
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'warehouse_stock_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'sku_snapshot',
        'system_available_at_start',
        'reserved_at_start',
        'expected_physical_at_start',
        'system_quantity',
        'actual_quantity',
        'new_available',
        'difference_quantity',
        'warehouse_stock_updated_at_start',
        'stock_updated_at_start',
        'description',
    ];

    protected $casts = [
        'warehouse_stock_updated_at_start' => 'datetime',
        'stock_updated_at_start' => 'datetime',
        'actual_quantity' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(StockCountDocument::class, 'document_id');
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
