<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BackfillInvoiceSellersCommand extends Command
{
    protected $signature = 'sales:backfill-invoice-sellers
        {--dry-run : Preview eligible invoices without writing}
        {--apply : Apply the reviewed backfill}
        {--invoice= : Restrict the run to one invoices.id}
        {--seller= : Restrict the source to one preinvoice_orders.seller_id}
        {--chunk=100 : Number of invoices processed per transaction}';

    protected $description = 'Backfill missing invoice sellers from linked preinvoices (dry-run by default)';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->reject('--apply و --dry-run هم‌زمان مجاز نیستند.');
        }

        $invoiceId = $this->positiveIntegerOption('invoice');
        $sellerId = $this->positiveIntegerOption('seller');
        $chunk = $this->positiveIntegerOption('chunk') ?? 100;

        if ($this->option('invoice') !== null && $invoiceId === null) {
            return $this->reject('--invoice باید یک شناسه مثبت باشد.');
        }
        if ($this->option('seller') !== null && $sellerId === null) {
            return $this->reject('--seller باید users.id مثبت باشد.');
        }
        if ($chunk > 1000) {
            return $this->reject('--chunk باید بین ۱ تا ۱۰۰۰ باشد.');
        }

        $apply = (bool) $this->option('apply');
        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'generated_at' => now()->toIso8601String(),
            'database_status' => 'ready',
            'inspected' => 0,
            'eligible' => 0,
            'backfilled' => 0,
            'without_valid_source' => 0,
            'invalid_sellers' => 0,
            'seller_mismatches' => 0,
        ];

        if (! Schema::hasTable('invoices') || ! Schema::hasTable('preinvoice_orders') || ! Schema::hasTable('users')) {
            $report['database_status'] = 'unavailable';
            $report['error'] = 'Required invoices, preinvoice_orders, or users table is missing.';
            $this->writeReport($report);

            return $this->reject('ساختار دیتابیس در اتصال فعلی کامل نیست؛ هیچ داده‌ای خوانده یا تغییر داده نشد.');
        }

        $query = Invoice::query()
            ->select(['id', 'seller_id', 'preinvoice_order_id'])
            ->with(['preinvoiceOrder:id,seller_id', 'preinvoiceOrder.seller:id,is_active,can_access_erp,is_seller'])
            ->when($invoiceId, fn ($query) => $query->whereKey($invoiceId))
            ->when($sellerId, fn ($query) => $query->whereHas(
                'preinvoiceOrder',
                fn ($preinvoiceQuery) => $preinvoiceQuery->where('seller_id', $sellerId)
            ));

        $query->chunkById($chunk, function (Collection $invoices) use (&$report, $apply): void {
            DB::transaction(function () use ($invoices, &$report, $apply): void {
                foreach ($invoices as $invoice) {
                    $report['inspected']++;
                    $sourceSellerId = $invoice->preinvoiceOrder?->seller_id;

                    if ($invoice->seller_id && $sourceSellerId && (int) $invoice->seller_id !== (int) $sourceSellerId) {
                        $report['seller_mismatches']++;
                    }

                    if ($invoice->seller_id) {
                        continue;
                    }

                    if (! $sourceSellerId) {
                        $report['without_valid_source']++;
                        continue;
                    }

                    $seller = $invoice->preinvoiceOrder->seller;
                    if (! $seller || ! $seller->is_active || ! $seller->can_access_erp || ! $seller->is_seller) {
                        $report['invalid_sellers']++;
                        continue;
                    }

                    $report['eligible']++;
                    if ($apply) {
                        $report['backfilled'] += Invoice::query()
                            ->whereKey($invoice->id)
                            ->whereNull('seller_id')
                            ->update(['seller_id' => $seller->id]);
                    }
                }
            });
        });

        $this->writeReport($report);
        $this->table(
            ['Mode', 'Inspected', 'Eligible', 'Backfilled', 'No source', 'Invalid seller', 'Mismatches'],
            [[
                $report['mode'],
                $report['inspected'],
                $report['eligible'],
                $report['backfilled'],
                $report['without_valid_source'],
                $report['invalid_sellers'],
                $report['seller_mismatches'],
            ]]
        );
        $this->info($apply ? 'Backfill اعمال شد.' : 'DRY RUN: هیچ داده‌ای تغییر نکرد.');

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    private function writeReport(array $report): void
    {
        File::ensureDirectoryExists(storage_path('logs'));
        File::put(
            storage_path('logs/invoice-seller-backfill.json'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        File::put(
            storage_path('logs/invoice-seller-backfill.txt'),
            collect($report)->map(fn ($value, $key) => $key . ': ' . $value)->implode(PHP_EOL) . PHP_EOL
        );
    }

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
