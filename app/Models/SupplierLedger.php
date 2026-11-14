<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierLedger extends Model
{
    protected $fillable = [
        'supplier_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'note',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    /**
     * Soft-deleted purchase returns remain in the ledger for audit, but their
     * debit must no longer affect the supplier's current balance.
     */
    public function scopeEffectiveForBalance($query)
    {
        return $query->where(function ($q) {
            $q->where('reference_type', '!=', \App\Models\PurchaseReturn::class)
                ->orWhereNull('reference_type')
                ->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('purchase_returns')
                        ->whereColumn('purchase_returns.id', 'supplier_ledgers.reference_id')
                        ->whereNull('purchase_returns.deleted_at');
                })
                ->orWhereExists(function ($sub) {
                    $sub->selectRaw('1')->from('purchase_return_revisions as reversal')
                        ->join('supplier_ledgers as posted', function ($join) {
                            $join->on('posted.reference_id', '=', 'reversal.id')->where('posted.reference_type', \App\Models\PurchaseReturnRevision::class);
                        })
                        ->whereColumn('reversal.purchase_return_id', 'supplier_ledgers.reference_id')->where('reversal.action', 'deleted');
                });
        });
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
