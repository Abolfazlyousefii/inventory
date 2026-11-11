<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'province',
        'city',
        'address',
        'postal_code',
        'additional_notes',
    ];

    /** Historical inventory adjustment placeholders are not commercial suppliers. */
    public function isInventoryAdjustmentSource(): bool
    {
        return (bool) $this->getAttribute('is_inventory_adjustment_source');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function ledgers()
    {
        return $this->hasMany(SupplierLedger::class);
    }

    public function accountTransactions()
    {
        return $this->hasMany(SupplierAccountTransaction::class);
    }
}
