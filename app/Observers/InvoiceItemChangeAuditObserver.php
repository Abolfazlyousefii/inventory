<?php

namespace App\Observers;

use App\Events\InvoiceChangeAuditRequested;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class InvoiceItemChangeAuditObserver
{
    public function created(InvoiceItem $item): void
    {
        $after = $this->snapshot($item);
        $invoiceContext = $this->invoiceContext($item);

        $this->afterCommit('invoice_item_added', [
            'invoice_id' => (int) $item->invoice_id,
            'invoice_uuid' => $invoiceContext['invoice_uuid'],
            'external_order_id' => $invoiceContext['external_order_id'],
            'invoice_item_id' => (int) $item->id,
            'notes_target' => 'invoice_items.notes',
            'notes_candidate' => $this->notesCandidate('added', null, $after),
            'before' => null,
            'after' => $after,
        ]);
    }

    public function updated(InvoiceItem $item): void
    {
        $tracked = ['product_id', 'variant_id', 'quantity', 'price', 'line_discount_amount', 'line_total'];
        $changed = array_values(array_intersect(array_keys($item->getChanges()), $tracked));
        if ($changed === []) {
            return;
        }

        $before = $this->snapshotFromArray(array_merge($item->getAttributes(), $item->getOriginal()), (int) $item->id, (int) $item->invoice_id);
        foreach ($tracked as $field) {
            if (array_key_exists($field, $item->getOriginal())) {
                $before[$field] = $this->normalizeValue($field, $item->getOriginal($field));
            }
        }
        $after = $this->snapshot($item);
        $invoiceContext = $this->invoiceContext($item);

        $status = 'adjusted';
        if (($after['quantity'] ?? 0) < ($before['quantity'] ?? 0)) {
            $status = 'quantity_decreased';
        } elseif (($after['quantity'] ?? 0) > ($before['quantity'] ?? 0)) {
            $status = 'quantity_increased';
        } elseif (($after['variant_id'] ?? null) !== ($before['variant_id'] ?? null)) {
            $status = 'variant_changed';
        } elseif (($after['price'] ?? 0) !== ($before['price'] ?? 0) || ($after['line_discount_amount'] ?? 0) !== ($before['line_discount_amount'] ?? 0)) {
            $status = 'price_adjusted';
        }

        $this->afterCommit('invoice_item_updated', [
            'invoice_id' => (int) $item->invoice_id,
            'invoice_uuid' => $invoiceContext['invoice_uuid'],
            'external_order_id' => $invoiceContext['external_order_id'],
            'invoice_item_id' => (int) $item->id,
            'changed_fields' => $changed,
            'notes_target' => 'invoice_items.notes',
            'notes_candidate' => $this->notesCandidate($status, $before, $after),
            'before' => $before,
            'after' => $after,
        ]);
    }

    public function deleted(InvoiceItem $item): void
    {
        $before = $this->snapshot($item);
        $invoiceContext = $this->invoiceContext($item);

        $this->afterCommit('invoice_item_removed', [
            'invoice_id' => (int) $item->invoice_id,
            'invoice_uuid' => $invoiceContext['invoice_uuid'],
            'external_order_id' => $invoiceContext['external_order_id'],
            'invoice_item_id' => (int) $item->id,
            'notes_target' => 'invoice_items.notes',
            'notes_candidate' => $this->notesCandidate('removed', $before, null),
            'before' => $before,
            'after' => null,
        ]);
    }

    private function notesCandidate(string $status, ?array $before, ?array $after): array
    {
        $oldQty = (int) ($before['quantity'] ?? 0);
        $newQty = (int) ($after['quantity'] ?? 0);
        $oldLineTotal = (int) ($before['line_total'] ?? 0);
        $newLineTotal = (int) ($after['line_total'] ?? 0);

        return [
            'schema_version' => 1,
            'fulfillment' => [
                'status' => $status,
                'old_quantity' => $oldQty,
                'new_quantity' => $newQty,
                'added_quantity' => max($newQty - $oldQty, 0),
                'unsent_quantity' => max($oldQty - $newQty, 0),
                'old_price' => $before['price'] ?? null,
                'new_price' => $after['price'] ?? null,
                'old_discount_amount' => $before['line_discount_amount'] ?? null,
                'new_discount_amount' => $after['line_discount_amount'] ?? null,
                'old_line_total' => $oldLineTotal,
                'new_line_total' => $newLineTotal,
                'line_total_difference' => $newLineTotal - $oldLineTotal,
                'old_product_id' => $before['product_id'] ?? null,
                'new_product_id' => $after['product_id'] ?? null,
                'old_variant_id' => $before['variant_id'] ?? null,
                'new_variant_id' => $after['variant_id'] ?? null,
            ],
        ];
    }

    private function snapshot(InvoiceItem $item): array
    {
        return $this->snapshotFromArray($item->getAttributes(), (int) $item->id, (int) $item->invoice_id);
    }

    private function snapshotFromArray(array $attributes, int $id, int $invoiceId): array
    {
        return [
            'id' => $id,
            'invoice_id' => $invoiceId,
            'product_id' => isset($attributes['product_id']) ? (int) $attributes['product_id'] : null,
            'variant_id' => isset($attributes['variant_id']) ? (int) $attributes['variant_id'] : null,
            'quantity' => (int) ($attributes['quantity'] ?? 0),
            'price' => (int) ($attributes['price'] ?? 0),
            'line_discount_amount' => (int) ($attributes['line_discount_amount'] ?? 0),
            'line_total' => (int) ($attributes['line_total'] ?? 0),
        ];
    }

    private function invoiceContext(InvoiceItem $item): array
    {
        $invoice = $item->relationLoaded('invoice')
            ? $item->invoice
            : $item->invoice()->first(['id', 'uuid', 'external_order_id']);

        return [
            'invoice_uuid' => $invoice?->uuid !== null ? (string) $invoice->uuid : null,
            'external_order_id' => $invoice?->external_order_id !== null ? (int) $invoice->external_order_id : null,
        ];
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        return in_array($field, ['product_id', 'variant_id', 'quantity', 'price', 'line_discount_amount', 'line_total'], true)
            ? ($value === null ? null : (int) $value)
            : $value;
    }

    private function afterCommit(string $action, array $payload): void
    {
        $dispatch = static fn () => InvoiceChangeAuditRequested::dispatch($action, $payload);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
            return;
        }

        $dispatch();
    }
}
