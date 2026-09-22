<?php

namespace App\Services;

use App\Events\InvoiceChangeAuditRequested;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

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
        $rows = CustomerLedger::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', (int) $invoice->id)
            ->where('type', 'debit')
            ->get(['id', 'customer_id', 'type', 'amount', 'reference_type', 'reference_id', 'note']);

        CustomerLedger::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', (int) $invoice->id)
            ->where('type', 'debit')
            ->delete();

        if ($rows->isEmpty()) {
            return;
        }

        $removedAmount = (int) $rows->sum('amount');
        $payload = [
            'invoice_id' => (int) $invoice->id,
            'invoice_uuid' => (string) $invoice->uuid,
            'external_order_id' => $invoice->external_order_id !== null ? (int) $invoice->external_order_id : null,
            'customer_id' => $invoice->customer_id ? (int) $invoice->customer_id : null,
            'removed_ledger_ids' => $rows->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'notes_target' => 'customer_ledgers.note / invoice cancellation audit',
            'notes_candidate' => [
                'schema_version' => 1,
                'financial_ledger' => [
                    'operation' => 'invoice_debit_voided',
                    'removed_debit_amount' => $removedAmount,
                    'customer_became_more_creditor_by' => $removedAmount,
                    'reason' => $note,
                    'invoice_id' => (int) $invoice->id,
                    'invoice_uuid' => (string) $invoice->uuid,
                ],
            ],
            'before' => $rows->map(fn (CustomerLedger $row) => [
                'id' => (int) $row->id,
                'customer_id' => (int) $row->customer_id,
                'type' => (string) $row->type,
                'amount' => (int) $row->amount,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id !== null ? (int) $row->reference_id : null,
                'note' => $row->note,
            ])->values()->all(),
            'after' => [],
            'customer_balance_effect' => [
                'debt_delta' => -$removedAmount,
                'debit_effect_amount' => 0,
                'credit_effect_amount' => $removedAmount,
                'meaning' => 'بدهی فاکتور از گردش مشتری حذف شد؛ در صورت باقی‌ماندن پرداخت‌ها، مشتری به همین میزان بستانکارتر می‌شود.',
            ],
        ];

        $dispatch = static fn () => InvoiceChangeAuditRequested::dispatch('customer_invoice_debit_voided', $payload);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
            return;
        }

        $dispatch();
    }
}
