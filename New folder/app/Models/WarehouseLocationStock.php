<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocationStock extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'warehouse_location_id',
        'quantity',
        'reserved_quantity',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_variant_id' => 'integer',
        'warehouse_location_id' => 'integer',
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
