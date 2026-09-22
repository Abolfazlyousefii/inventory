<?php

namespace App\Observers;

use App\Events\InvoiceChangeAuditRequested;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceFinancialChangeAuditObserver
{
    public function updated(Invoice $invoice): void
    {
        $tracked = [
            'total', 'subtotal', 'discount_amount', 'invoice_discount_amount',
            'product_discount_amount', 'shipping_price', 'status',
            'collection_note', 'cancellation_reason', 'cancellation_note',
        ];
        $changed = array_values(array_intersect(array_keys($invoice->getChanges()), $tracked));
        if ($changed === []) {
            return;
        }

        $beforeTotal = (int) $invoice->getOriginal('total');
        $afterTotal = (int) $invoice->total;
        $paidAmount = (int) $invoice->payments()->sum('amount');

        $before = [];
        $after = [];
        foreach ($changed as $field) {
            $before[$field] = $invoice->getOriginal($field);
            $after[$field] = $invoice->getAttribute($field);
        }

        $payload = [
            'invoice_id' => (int) $invoice->id,
            'invoice_uuid' => (string) $invoice->uuid,
            'external_order_id' => $invoice->external_order_id !== null ? (int) $invoice->external_order_id : null,
            'customer_id' => $invoice->customer_id ? (int) $invoice->customer_id : null,
            'changed_fields' => $changed,
            'notes_target' => 'invoice / future notes audit payload',
            'notes_candidate' => [
                'schema_version' => 1,
                'invoice_adjustment' => [
                    'old_total' => $beforeTotal,
                    'new_total' => $afterTotal,
                    'total_difference' => $afterTotal - $beforeTotal,
                    'amount_reduced' => max($beforeTotal - $afterTotal, 0),
                    'amount_increased' => max($afterTotal - $beforeTotal, 0),
                    'paid_amount' => $paidAmount,
                    'excess_payment_after_change' => max($paidAmount - $afterTotal, 0),
                    'old_status' => (string) $invoice->getOriginal('status'),
                    'new_status' => (string) $invoice->status,
                    'collection_note' => $invoice->collection_note,
                    'cancellation_reason' => $invoice->cancellation_reason,
                    'cancellation_note' => $invoice->cancellation_note,
                ],
            ],
            'before' => $before,
            'after' => $after,
        ];

        $dispatch = static fn () => InvoiceChangeAuditRequested::dispatch('invoice_financial_or_status_updated', $payload);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
            return;
        }

        $dispatch();
    }
}
