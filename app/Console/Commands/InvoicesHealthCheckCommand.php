<?php

namespace App\Console\Commands;

use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InvoicesHealthCheckCommand extends Command
{
    protected $signature = 'invoices:health-check';
    protected $description = 'Read-only invoice data health report.';

    public function handle(): int
    {
        $this->info('Invoice health check (read-only)');

        $missingNumbers = Invoice::query()->whereNull('uuid')->orWhere('uuid', '')->count();
        $this->line("1) فاکتورهای بدون شماره: {$missingNumbers}");

        $duplicateNumbers = Invoice::query()
            ->select('uuid', DB::raw('count(*) as aggregate'))
            ->whereNotNull('uuid')
            ->where('uuid', '!=', '')
            ->groupBy('uuid')
            ->havingRaw('count(*) > 1')
            ->get();
        $this->line('2) شماره‌های تکراری: ' . $duplicateNumbers->count());
        $duplicateNumbers->take(20)->each(fn ($row) => $this->warn("   {$row->uuid}: {$row->aggregate}"));

        $zeroPriceItems = InvoiceItem::query()->where('quantity', '>', 0)->where('price', '<=', 0)->count();
        $this->line("3) آیتم‌های quantity > 0 و price <= 0: {$zeroPriceItems}");

        $mismatched = Invoice::query()
            ->with('items:id,invoice_id,quantity,price,line_discount_amount')
            ->get(['id', 'uuid', 'subtotal', 'discount_amount', 'total'])
            ->filter(fn (Invoice $invoice) => $invoice->hasTotalMismatch());
        $this->line('4) فاکتورهای دارای مغایرت مبلغ: ' . $mismatched->count());
        $mismatched->take(20)->each(fn (Invoice $invoice) => $this->warn("   {$invoice->uuid}"));

        $validStatuses = array_keys(Invoice::statusLabels());
        $invalidStatuses = Invoice::query()->whereNotNull('status')->whereNotIn('status', $validStatuses)->count();
        $this->line("5) فاکتورهای status نامعتبر: {$invalidStatuses}");

        $missingPreinvoices = Invoice::query()
            ->whereNotNull('preinvoice_order_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from((new PreinvoiceOrder())->getTable())
                    ->whereColumn('preinvoice_orders.id', 'invoices.preinvoice_order_id');
            })
            ->count();
        $this->line("6) فاکتورهای دارای preinvoice_order_id بدون پیش‌فاکتور: {$missingPreinvoices}");

        $duplicateLedgers = CustomerLedger::query()
            ->select('reference_id', DB::raw('count(*) as aggregate'))
            ->where('reference_type', Invoice::class)
            ->where('type', 'debit')
            ->whereNotNull('reference_id')
            ->groupBy('reference_id')
            ->havingRaw('count(*) > 1')
            ->get();
        $this->line('7) ledger بدهکاری تکراری احتمالی برای یک invoice: ' . $duplicateLedgers->count());
        $duplicateLedgers->take(20)->each(fn ($row) => $this->warn("   invoice_id {$row->reference_id}: {$row->aggregate}"));

        return self::SUCCESS;
    }
}
