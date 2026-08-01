<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditSellerOwnershipCommand extends Command
{
    protected $signature = 'sales:audit-seller-ownership {--json=} {--text=}';
    protected $description = 'Read-only audit of invoice and preinvoice seller ownership';

    public function handle(): int
    {
        $invalidInvoiceIds = Invoice::query()->whereNotNull('seller_id')->whereDoesntHave('seller')->pluck('id')->all();
        $invalidPreinvoiceIds = PreinvoiceOrder::query()->whereNotNull('seller_id')->whereDoesntHave('seller')->pluck('id')->all();
        $mismatches = Invoice::query()->whereNotNull('preinvoice_order_id')->whereHas('preinvoiceOrder', fn ($q) => $q->whereColumn('preinvoice_orders.seller_id', '<>', 'invoices.seller_id'))->pluck('id')->all();
        $report = [
            'generated_at' => now()->toIso8601String(),
            'invoice_without_seller' => Invoice::query()->whereNull('seller_id')->pluck('id')->all(),
            'preinvoice_without_seller' => PreinvoiceOrder::query()->whereNull('seller_id')->pluck('id')->all(),
            'seller_mismatch_invoice_ids' => $mismatches,
            'invalid_invoice_seller_ids' => $invalidInvoiceIds,
            'invalid_preinvoice_seller_ids' => $invalidPreinvoiceIds,
            'inactive_invoice_seller_ids' => Invoice::query()->whereHas('seller', fn ($q) => $q->where(fn ($x) => $x->where('is_active', false)->orWhere('can_access_erp', false)->orWhere('is_seller', false)))->pluck('id')->all(),
            'crm_id_confusion_invoice_ids' => Invoice::query()->whereNotNull('seller_id')->whereDoesntHave('seller')->whereExists(fn ($q) => $q->selectRaw('1')->from('users')->whereColumn('users.crm_user_id', 'invoices.seller_id'))->pluck('id')->all(),
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $text = collect($report)->map(fn ($value, $key) => $key.': '.(is_array($value) ? count($value).' ['.implode(',', $value).']' : $value))->implode(PHP_EOL);
        File::ensureDirectoryExists(storage_path('logs'));
        File::put($this->option('json') ?: storage_path('logs/seller-ownership-audit.json'), $json);
        File::put($this->option('text') ?: storage_path('logs/seller-ownership-audit.txt'), $text);
        $this->line($text);
        return self::SUCCESS;
    }
}
