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

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function ledgers()
    {
        return $this->hasMany(SupplierLedger::class);
    }
}
