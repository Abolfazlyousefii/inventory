<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class InvoiceNumberAuditCommand extends Command
{
    protected $signature = 'invoices:number-audit {--apply : Record audit backup/activity logs for detected conflicts} {--dry-run : Only report conflicts} {--preinvoice= : Limit audit to one preinvoice uuid}';

    protected $description = 'Audit legacy invoice/preinvoice number conflicts without changing items, totals, customers, or stock.';

    public function handle(): int
    {
        $preinvoiceUuid = trim((string) $this->option('preinvoice'));
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('dry-run')) {
            $this->warn('--apply was provided; changes are limited to backup/activity log records.');
        }

        $query = PreinvoiceOrder::query()->select(['id', 'uuid', 'customer_name', 'total_price']);
        if ($preinvoiceUuid !== '') {
            $query->where('uuid', $preinvoiceUuid);
        }

        $rows = [];
        foreach ($query->orderBy('id')->cursor() as $preinvoice) {
            $conflicts = Invoice::query()
                ->where('uuid', (string) $preinvoice->uuid)
                ->where('preinvoice_order_id', '!=', (int) $preinvoice->id)
                ->get(['id', 'uuid', 'preinvoice_order_id', 'customer_name', 'total']);

            foreach ($conflicts as $invoice) {
                $rows[] = [
                    'preinvoice_id' => (int) $preinvoice->id,
                    'preinvoice_uuid' => (string) $preinvoice->uuid,
                    'preinvoice_customer' => (string) $preinvoice->customer_name,
                    'invoice_id' => (int) $invoice->id,
                    'invoice_uuid' => (string) $invoice->uuid,
                    'invoice_preinvoice_order_id' => (int) $invoice->preinvoice_order_id,
                    'invoice_customer' => (string) $invoice->customer_name,
                    'suggested_safe_uuid' => $this->safeUuid($preinvoice),
                ];
            }
        }

        $duplicates = Invoice::query()
            ->selectRaw('uuid, COUNT(*) as duplicate_count')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('uuid')
            ->get()
            ->map(fn ($row) => ['uuid' => (string) $row->uuid, 'duplicate_count' => (int) $row->duplicate_count])
            ->values()
            ->all();

        $this->info('Legacy invoice number conflicts: ' . count($rows));
        if ($rows) {
            $this->table(['preinvoice_id', 'preinvoice_uuid', 'preinvoice_customer', 'invoice_id', 'invoice_uuid', 'invoice_preinvoice_order_id', 'invoice_customer', 'suggested_safe_uuid'], $rows);
        }

        $this->info('Duplicate invoice uuid groups: ' . count($duplicates));
        if ($duplicates) {
            $this->table(['uuid', 'duplicate_count'], $duplicates);
        }

        if ($apply) {
            $payload = ['created_at' => now()->toISOString(), 'conflicts' => $rows, 'duplicates' => $duplicates];
            $path = 'invoice-number-audit-backups/invoice-number-audit-' . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info('Backup written: storage/app/' . $path);

            foreach ($rows as $row) {
                $invoice = Invoice::query()->find($row['invoice_id']);
                if ($invoice) {
                    ActivityLogger::log('invoice_number_audit_conflict_detected', $invoice, 'تداخل شماره فاکتور قدیمی شناسایی شد؛ تبدیل پیش‌فاکتور مربوطه از شماره امن پیشنهادی استفاده می‌کند.', $row);
                }
            }
        }

        return self::SUCCESS;
    }

    private function safeUuid(PreinvoiceOrder $preinvoice): string
    {
        $base = (string) $preinvoice->uuid . '-P' . (int) $preinvoice->id;
        $candidate = $base;
        $suffix = 2;

        while (Invoice::query()->where('uuid', $candidate)->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
