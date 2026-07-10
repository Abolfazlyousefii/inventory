<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLedger extends Model
{
    /**
     * Ledgers that are effective in customer balance calculations.
     * Debit rows generated from cancelled/not-shipped invoices are kept for audit,
     * but ignored everywhere a balance is calculated.
     */
    public function scopeEffectiveForBalance($query)
    {
        return $query->where(function ($q) {
            $q->where('reference_type', '!=', \App\Models\Invoice::class)
                ->orWhereNull('reference_type')
                ->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.id', 'customer_ledgers.reference_id')
                        ->where('invoices.status', '!=', \App\Models\Invoice::STATUS_NOT_SHIPPED);
                });
        });
    }

    protected $fillable = [
        'customer_id','type','amount','reference_type','reference_id','note'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
