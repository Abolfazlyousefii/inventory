<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'type',
        'personnel_name',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(WarehouseTransfer::class, 'from_warehouse_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(WarehouseTransfer::class, 'to_warehouse_id');
    }

    public function isPersonnelWarehouse(): bool
    {
        return $this->type === 'personnel';
    }

    public function isPersonnelRoot(): bool
    {
        return $this->type === 'personnel' && is_null($this->parent_id);
    }

    public function isPersonnelLeaf(): bool
    {
        return $this->type === 'personnel' && !is_null($this->parent_id);
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function locationStocks()
    {
        return $this->hasMany(WarehouseLocationStock::class);
    }

    public function locationMovements()
    {
        return $this->hasMany(WarehouseLocationMovement::class);
    }
}
