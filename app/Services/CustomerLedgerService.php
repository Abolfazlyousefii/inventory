<?php

namespace App\Services;

use App\Models\CustomerLedger;
use App\Models\Invoice;

class CustomerLedgerService
{
    public function syncInvoiceDebit(Invoice $invoice): void
    {
        if (empty($invoice->customer_id)) {
            return;
        }

        if (in_array((string) $invoice->status, Invoice::cancelledStatuses(), true)) {
            $this->voidInvoiceDebit($invoice, 'حذف اثر بدهکاری فاکتور کنسل‌شده');
            return;
        }

        CustomerLedger::query()->updateOrCreate(
            [
                'customer_id' => (int) $invoice->customer_id,
                'reference_type' => Invoice::class,
                'reference_id' => (int) $invoice->id,
                'type' => 'debit',
            ],
            [
                'amount' => (int) $invoice->total,
                'note' => 'ثبت/بروزرسانی بدهکاری بابت حواله فروش ' . $invoice->uuid,
            ]
        );
    }

    public function voidInvoiceDebit(Invoice $invoice, ?string $note = null): void
    {
        CustomerLedger::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', (int) $invoice->id)
            ->where('type', 'debit')
            ->delete();
    }
}
