<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Support\ActivityLogger;
use App\Support\DocumentCodeGenerator;
use App\Support\SalesDocumentTotals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class PreinvoiceLifecycleAuditCommand extends Command
{
    protected $signature = 'preinvoice:lifecycle-audit {--order= : Preinvoice order id} {--dry-run : Report only} {--apply : Create missing invoice/havaleh lifecycle records without changing preinvoice items}';

    protected $description = 'Audit and safely complete lifecycle records for one active preinvoice without changing its items.';

    public function handle(): int
    {
        $orderId = (int) $this->option('order');
        $apply = (bool) $this->option('apply');

        if ($orderId <= 0) {
            $this->error('Use --order=<id>.');
            return SymfonyCommand::FAILURE;
        }

        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');
            return SymfonyCommand::FAILURE;
        }

        $order = PreinvoiceOrder::query()->with(['items', 'invoice.items'])->findOrFail($orderId);
        $invoice = Invoice::query()->with('items')->where('preinvoice_order_id', $order->id)->first();
        $totals = SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price, ['discount_allocation_mode' => $order->discount_allocation_mode]);
        $itemsQty = (int) $order->items->sum('quantity');
        $itemsTotal = (int) $order->items->sum(fn ($item) => max(((int) $item->price * (int) $item->quantity) - (int) ($item->line_discount_amount ?? 0), 0));

        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode: no data changed.');
        $this->table(['field', 'value'], [
            ['preinvoice_id', $order->id],
            ['uuid', $order->uuid],
            ['status', $order->status],
            ['warehouse_reviewed_at', (string) $order->warehouse_reviewed_at],
            ['stock_released_at', (string) $order->stock_released_at],
            ['stock_frozen_until', (string) $order->stock_frozen_until],
            ['items_count', $order->items->count()],
            ['items_quantity', $itemsQty],
            ['items_total', $itemsTotal],
            ['shipping_price', (int) $order->shipping_price],
            ['calculated_total', (int) $totals['grand_total']],
            ['stored_total_price', (int) $order->total_price],
            ['invoice_exists', $invoice ? 'yes' : 'no'],
            ['invoice_id', $invoice?->id ?: '—'],
            ['invoice_uuid', $invoice?->uuid ?: '—'],
            ['invoice_status', $invoice?->status ?: '—'],
            ['invoice_items_count', $invoice?->items?->count() ?? 0],
        ]);

        if ($invoice) {
            $this->info('Invoice/havaleh already exists for this preinvoice. No lifecycle creation is needed.');
            return SymfonyCommand::SUCCESS;
        }

        $this->warn('No invoice/havaleh exists for this preinvoice. Apply will create invoice and invoice_items from existing preinvoice_order_items without changing preinvoice items.');
        if (! $apply) {
            return SymfonyCommand::SUCCESS;
        }

        DB::transaction(function () use ($order) {
            $lockedOrder = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existingInvoice = Invoice::query()->where('preinvoice_order_id', $lockedOrder->id)->lockForUpdate()->first();
            if ($existingInvoice) {
                $this->warn('Invoice was created by another process during apply; skipping.');
                return;
            }

            $backups = $this->createBackups($lockedOrder->id);
            $this->info('Backup tables created: ' . implode(', ', $backups));

            $totals = SalesDocumentTotals::calculate($lockedOrder->items, (int) $lockedOrder->discount_amount, (int) $lockedOrder->shipping_price, ['discount_allocation_mode' => $lockedOrder->discount_allocation_mode]);
            $invoice = Invoice::query()->create([
                'uuid' => $this->officialCodeForPreinvoiceConversion($lockedOrder),
                'preinvoice_order_id' => $lockedOrder->id,
                'document_date' => $lockedOrder->display_document_date,
                'customer_id' => $lockedOrder->customer_id,
                'customer_name' => $lockedOrder->customer_name,
                'customer_mobile' => $lockedOrder->customer_mobile,
                'customer_address' => $lockedOrder->customer_address,
                'province_id' => $lockedOrder->province_id,
                'city_id' => $lockedOrder->city_id,
                'shipping_id' => $lockedOrder->shipping_id,
                'shipping_price' => (int) $lockedOrder->shipping_price,
                'discount_amount' => (int) $lockedOrder->discount_amount,
                'subtotal' => (int) $totals['subtotal_before_discount'],
                'total' => (int) $totals['grand_total'],
                'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
                'status_changed_at' => now(),
            ]);

            foreach ($lockedOrder->items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => (int) $item->product_id,
                    'variant_id' => (int) $item->variant_id,
                    'quantity' => (int) $item->quantity,
                    'price' => (int) $item->price,
                    'line_total' => max(((int) $item->price * (int) $item->quantity) - (int) ($item->line_discount_amount ?? 0), 0),
                    'sort_order' => (int) ($item->sort_order ?: 0),
                    'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
                ]);
            }

            $lockedOrder->update([
                'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                'total_price' => (int) $totals['grand_total'],
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ]);

            ActivityLogger::log('preinvoice_lifecycle_completed', $lockedOrder->fresh(), 'چرخه پیش‌فاکتور بدون تغییر اقلام تکمیل و فاکتور/حواله ساخته شد.', [
                'command' => 'preinvoice:lifecycle-audit',
                'invoice_id' => $invoice->id,
                'invoice_uuid' => $invoice->uuid,
            ]);
        });

        $this->info('Lifecycle completion applied.');
        return SymfonyCommand::SUCCESS;
    }

    private function officialCodeForPreinvoiceConversion(PreinvoiceOrder $order): string
    {
        if (is_string($order->uuid) && preg_match('/^\d{5}$/', $order->uuid) === 1) {
            $usedByAnotherInvoice = Invoice::query()
                ->where('uuid', $order->uuid)
                ->where('preinvoice_order_id', '!=', $order->id)
                ->exists();

            if (! $usedByAnotherInvoice) {
                return $order->uuid;
            }
        }

        return DocumentCodeGenerator::generateUnique5DigitCode(Invoice::class, 'uuid');
    }

    private function createBackups(int $orderId): array
    {
        $suffix = now()->format('Ymd_His') . '_order_' . $orderId;
        $tables = ['preinvoice_orders', 'preinvoice_order_items', 'invoices', 'invoice_items', 'activity_logs'];
        $names = [];
        foreach ($tables as $table) {
            $name = 'backup_' . $table . '_lifecycle_audit_' . $suffix;
            DB::statement("CREATE TABLE `{$name}` AS SELECT * FROM `{$table}`");
            $names[] = $name;
        }

        return $names;
    }
}
