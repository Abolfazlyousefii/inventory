<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocationMovement extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_variant_id',
        'from_location_id',
        'to_location_id',
        'quantity',
        'type',
        'reference_type',
        'reference_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_variant_id' => 'integer',
        'from_location_id' => 'integer',
        'to_location_id' => 'integer',
        'quantity' => 'integer',
        'user_id' => 'integer',
    ];

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function fromLocation() { return $this->belongsTo(WarehouseLocation::class, 'from_location_id'); }
    public function toLocation() { return $this->belongsTo(WarehouseLocation::class, 'to_location_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
