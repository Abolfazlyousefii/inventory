<?php

namespace App\Console\Commands;

use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Services\CustomerLedgerService;
use App\Support\SalesDocumentTotals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditSalesDiscountIntegrity extends Command
{
    protected $signature = 'sales:audit-discount-integrity
        {--invoice-number= : Audit one invoice number/UUID}
        {--dry-run : Explicitly run without writes (the default)}
        {--repair : Repair only deterministic inconsistencies}';

    protected $description = 'Audit stored sales snapshots, discounts, totals, breakdowns and invoice ledger debits.';

    public function handle(CustomerLedgerService $ledgerService): int
    {
        $repair = (bool) $this->option('repair');
        $number = trim((string) $this->option('invoice-number'));
        $counts = array_fill_keys(['audited', 'healthy', 'repairable', 'ambiguous', 'repaired', 'failed'], 0);

        $invoiceQuery = Invoice::query()->with(['items', 'payments']);
        if ($number !== '') $invoiceQuery->where('uuid', $number);
        $invoiceQuery->orderBy('id')->chunkById(100, function ($invoices) use (&$counts, $repair, $ledgerService) {
            foreach ($invoices as $invoice) $this->auditInvoice($invoice, $repair, $ledgerService, $counts);
        });

        if ($number === '') {
            PreinvoiceOrder::query()->with('items')->orderBy('id')->chunkById(100, function ($orders) use (&$counts, $repair) {
                foreach ($orders as $order) $this->auditPreinvoice($order, $repair, $counts);
            });
        }

        $this->table(['audited', 'healthy', 'repairable', 'ambiguous', 'repaired', 'failed'], [[
            $counts['audited'], $counts['healthy'], $counts['repairable'], $counts['ambiguous'], $counts['repaired'], $counts['failed'],
        ]]);
        $this->info('Mode: '.($repair ? 'repair' : 'dry-run'));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function auditInvoice(Invoice $invoice, bool $repair, CustomerLedgerService $ledgerService, array &$counts): void
    {
        $counts['audited']++;
        try {
            $proposal = $this->invoiceProposal($invoice);
            if ($proposal['issues'] === []) {
                $counts['healthy']++;
                $this->line($invoice->uuid.' unchanged');
                return;
            }
            if (! $proposal['deterministic']) {
                $counts['ambiguous']++;
                $this->warn($invoice->uuid.' ambiguous: '.implode(',', $proposal['issues']));
                return;
            }
            $counts['repairable']++;
            if (! $repair) {
                $this->warn($invoice->uuid.' repairable: '.implode(',', $proposal['issues']));
                return;
            }

            DB::transaction(function () use ($invoice, $ledgerService) {
                $locked = Invoice::query()->with(['items', 'payments'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $proposal = $this->invoiceProposal($locked);
                if (! $proposal['deterministic']) throw new \DomainException('Invoice became ambiguous while waiting for its lock.');
                foreach ($proposal['line_discounts'] as $itemId => $discount) {
                    InvoiceItem::query()->whereKey($itemId)->where('invoice_id', $locked->id)->lockForUpdate()->firstOrFail()
                        ->update(['line_discount_amount' => $discount]);
                }
                $locked->refresh()->load('items');
                $totals = SalesDocumentTotals::fromDocument($locked);
                if ((int) $locked->payments->sum('amount') > (int) $totals['grand_total']) throw new \DomainException('Payments exceed the repaired invoice total.');
                $old = $this->safeTotals($locked);
                $locked->forceFill([
                    'subtotal' => $totals['subtotal_before_discount'],
                    'product_discount_amount' => $totals['items_discount'],
                    'invoice_discount_amount' => $totals['invoice_discount'],
                    'discount_amount' => $totals['total_discount'],
                    'discount_breakdown' => SalesDocumentTotals::canonicalBreakdown($locked, $totals),
                    'total' => $totals['grand_total'],
                ])->save();
                $debits = CustomerLedger::query()->where('reference_type', Invoice::class)->where('reference_id', $locked->id)->where('type', 'debit')->lockForUpdate()->get();
                if ($debits->count() > 1) throw new \DomainException('Duplicate invoice debit rows require manual review.');
                $ledgerService->syncInvoiceDebit($locked);
                Log::notice('Sales pricing snapshot repaired.', [
                    'document_type' => 'invoice', 'document_id' => $locked->id, 'document_number' => $locked->uuid,
                    'user_id' => null, 'reason' => 'discount_integrity_repair', 'old_totals' => $old,
                    'new_totals' => $this->safeTotals($locked->fresh()), 'changed_item_ids' => array_keys($proposal['line_discounts']),
                    'calculation_version' => SalesDocumentTotals::CALCULATION_VERSION, 'timestamp' => now()->toIso8601String(),
                ]);
            });
            $counts['repaired']++;
            $this->info($invoice->uuid.' repaired');
        } catch (Throwable $exception) {
            $counts['failed']++;
            $this->error($invoice->uuid.' failed: '.$exception->getMessage());
            report($exception);
        }
    }

    private function invoiceProposal(Invoice $invoice): array
    {
        $lineDiscounts = [];
        $issues = SalesDocumentTotals::integrityIssues($invoice);
        if (DB::getSchemaBuilder()->hasTable('invoice_collection_revision_items')) {
            foreach ($invoice->items as $item) {
                $revision = DB::table('invoice_collection_revision_items')->where('invoice_item_id', $item->id)
                    ->whereNotNull('old_quantity')->whereNotNull('new_quantity')->latest('id')->first();
                if (! $revision || (int) $revision->old_quantity <= 0 || (int) $revision->new_quantity !== (int) $item->quantity) continue;
                if ((int) $revision->old_quantity === (int) $item->quantity || (int) $revision->old_discount !== (int) $item->line_discount_amount) continue;
                $expected = SalesDocumentTotals::proportionalLineDiscount((int) $revision->old_quantity, (int) $revision->old_discount, (int) $item->quantity, (int) $item->price);
                if ($expected !== (int) $item->line_discount_amount) {
                    $lineDiscounts[(int) $item->id] = $expected;
                    $issues[] = 'stale_absolute_line_discount';
                }
            }
        }
        $ledger = CustomerLedger::query()->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)->where('type', 'debit')->get();
        if ($ledger->count() > 1) $issues[] = 'duplicate_ledger_debit';
        if ($ledger->count() === 1 && (int) $ledger->first()->amount !== (int) $invoice->total) $issues[] = 'ledger_total_mismatch';
        $deterministic = ! in_array('duplicate_ledger_debit', $issues, true) && ! in_array('invalid_line_discount', $issues, true);

        return ['issues' => array_values(array_unique($issues)), 'line_discounts' => $lineDiscounts, 'deterministic' => $deterministic];
    }

    private function auditPreinvoice(PreinvoiceOrder $order, bool $repair, array &$counts): void
    {
        $counts['audited']++;
        $issues = SalesDocumentTotals::integrityIssues($order);
        if ($issues === []) { $counts['healthy']++; return; }
        $counts['repairable']++;
        if (! $repair) return;
        try {
            DB::transaction(function () use ($order) {
                $locked = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $totals = SalesDocumentTotals::fromDocument($locked);
                $locked->forceFill([
                    'product_discount_amount' => $totals['items_discount'], 'invoice_discount_amount' => $totals['invoice_discount'],
                    'discount_amount' => $totals['total_discount'], 'discount_breakdown' => SalesDocumentTotals::canonicalBreakdown($locked, $totals),
                    'total_price' => $totals['grand_total'],
                ])->save();
            });
            $counts['repaired']++;
        } catch (Throwable $exception) { $counts['failed']++; report($exception); }
    }

    private function safeTotals(object $document): array
    {
        return ['subtotal' => (int) ($document->subtotal ?? 0), 'product_discount' => (int) ($document->product_discount_amount ?? 0),
            'invoice_discount' => (int) ($document->invoice_discount_amount ?? 0), 'total_discount' => (int) ($document->discount_amount ?? 0),
            'grand_total' => (int) ($document->total ?? $document->total_price ?? 0)];
    }
}
