<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Support\SalesDocumentTotals;
use Illuminate\Console\Command;

class AuditInvoiceFinancials extends Command
{
    protected $signature = 'invoice:audit-financials {uuid} {--json : Output JSON}';
    protected $description = 'Read-only audit of invoice financial snapshots against calculated totals.';

    public function handle(): int
    {
        $uuid = (string) $this->argument('uuid');
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant', 'preinvoiceOrder.items'])
            ->where('uuid', $uuid)
            ->first();

        if (! $invoice) {
            $payload = ['error' => "Invoice {$uuid} not found."];
            $this->emit($payload);
            return self::FAILURE;
        }

        $totals = SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]);
        $items = $invoice->items->map(function ($item) {
            $gross = SalesDocumentTotals::lineSubtotal($item);
            $net = SalesDocumentTotals::lineTotal($item);
            return [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
                'stored_line_total' => (int) $item->line_total,
                'calculated_gross' => $gross,
                'calculated_net' => $net,
                'difference' => (int) $item->line_total - $net,
            ];
        })->values();

        $storedVsCalculated = [
            'subtotal' => (int) $invoice->subtotal - (int) $totals['subtotal_before_discount'],
            'invoice_discount_amount' => (int) $invoice->invoice_discount_amount - (int) $totals['invoice_discount'],
            'product_discount_amount' => (int) $invoice->product_discount_amount - (int) $totals['items_discount'],
            'shipping_price' => (int) $invoice->shipping_price - (int) $totals['shipping'],
            'total' => (int) $invoice->total - (int) $totals['grand_total'],
        ];

        $warnings = [];
        foreach ($items as $row) {
            if ((int) $row['difference'] !== 0) {
                $warnings[] = 'line_total mismatch on invoice_item #' . $row['id'];
            }
        }
        foreach ($storedVsCalculated as $field => $diff) {
            if ((int) $diff !== 0) {
                $warnings[] = "stored {$field} differs by {$diff}";
            }
        }

        $preinvoice = $invoice->preinvoiceOrder;
        $comparison = $invoice->items->map(function ($item) use ($preinvoice) {
            $match = $preinvoice?->items->first(fn ($pre) => (int) $pre->product_id === (int) $item->product_id && (int) $pre->variant_id === (int) $item->variant_id && (int) ($pre->sort_order ?: 0) === (int) ($item->sort_order ?: 0));
            return [
                'invoice_item_id' => (int) $item->id,
                'invoice_price' => (int) $item->price,
                'preinvoice_item_id' => $match?->id,
                'preinvoice_price' => $match ? (int) $match->price : null,
                'price_difference' => $match ? (int) $item->price - (int) $match->price : null,
                'invoice_line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
                'preinvoice_line_discount_amount' => $match ? (int) ($match->line_discount_amount ?? 0) : null,
            ];
        })->values();

        if ($comparison->contains(fn ($row) => ($row['price_difference'] ?? 0) !== 0)) {
            $warnings[] = 'stale preinvoice item';
        }

        $payload = [
            'invoice' => $invoice->only(['id','uuid','status','preinvoice_order_id','subtotal','discount_amount','invoice_discount_amount','product_discount_amount','discount_allocation_mode','shipping_price','total','created_at','updated_at']),
            'invoice_items' => $items->all(),
            'linked_preinvoice' => $preinvoice ? $preinvoice->only(['id','uuid','status','subtotal','discount_amount','invoice_discount_amount','product_discount_amount','discount_allocation_mode','shipping_price','total_price','updated_at']) : null,
            'preinvoice_items_comparison' => $comparison->all(),
            'calculated_totals' => $totals,
            'stored_vs_calculated' => $storedVsCalculated,
            'warnings' => array_values(array_unique($warnings)),
        ];

        $this->emit($payload);
        return empty($warnings) ? self::SUCCESS : self::FAILURE;
    }

    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        foreach ($payload as $section => $value) {
            $this->line('## ' . str_replace('_', ' ', ucwords($section, '_')));
            $this->line(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->newLine();
        }
    }
}
