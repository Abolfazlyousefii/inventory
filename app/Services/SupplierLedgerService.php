<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\SupplierLedger;

class SupplierLedgerService
{
    public function syncPurchaseCredit(Purchase $purchase): void
    {
        if (empty($purchase->supplier_id)) {
            return;
        }

        SupplierLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->where('supplier_id', '!=', (int) $purchase->supplier_id)
            ->delete();

        SupplierLedger::query()->updateOrCreate(
            [
                'supplier_id' => (int) $purchase->supplier_id,
                'reference_type' => Purchase::class,
                'reference_id' => (int) $purchase->id,
                'type' => 'credit',
            ],
            [
                'amount' => max((int) $purchase->total_amount, 0),
                'note' => 'بستانکاری تامین‌کننده بابت خرید کالا شماره PUR-' . $purchase->id,
            ]
        );
    }

    public function voidPurchaseCredit(Purchase $purchase): void
    {
        SupplierLedger::query()
            ->where('reference_type', Purchase::class)
            ->where('reference_id', (int) $purchase->id)
            ->where('type', 'credit')
            ->delete();
    }
}
