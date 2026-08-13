<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocumentItem;
use App\Services\Finance\SellerSalesDocumentReassignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditCommissionSellerMismatchesCommand extends Command
{
    protected $signature = 'sales:audit-commission-seller-mismatches {--dry-run} {--apply}';

    protected $description = 'Report or repair active seller sales document items whose invoice seller has changed';

    public function handle(SellerSalesDocumentReassignmentService $reassignmentService): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('--dry-run و --apply هم‌زمان مجاز نیستند.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $rows = $this->mismatches();
        $report = $rows->map(fn (SellerSalesDocumentItem $item): array => $this->reportRow($item));

        $this->info($apply ? 'APPLY' : 'DRY RUN: هیچ تغییری در پایگاه داده نوشته نشد.');
        $this->table([
            'Invoice ID', 'Invoice Number', 'Document ID', 'Document Number',
            'Document Seller', 'Current Seller', 'Snapshot Total', 'Audit',
        ], $report->map(fn (array $row): array => [
            $row['invoice_id'],
            $row['invoice_number'],
            $row['document_id'],
            $row['document_number'],
            $row['document_seller_name'].' (#'.$row['document_seller_id'].')',
            $row['current_seller_name'].' (#'.$row['current_seller_id'].')',
            number_format($row['invoice_total_snapshot']),
            $row['audit_id'] ?: 'missing',
        ])->all());

        if ($apply) {
            foreach ($report as $row) {
                DB::transaction(function () use ($row, $reassignmentService): void {
                    $item = SellerSalesDocumentItem::query()
                        ->with(['document', 'invoice.seller', 'invoice.preinvoiceOrder.seller', 'invoice.preinvoiceOrder.creator'])
                        ->active()
                        ->find($row['item_id']);

                    if (! $item) {
                        return;
                    }

                    $currentSeller = $item->invoice?->effectiveSeller();
                    if (! $currentSeller || (int) $item->document->seller_id === (int) $currentSeller->id) {
                        return;
                    }

                    $audit = $this->matchingAudit($item, $currentSeller->id);
                    $reassignmentService->reconcile($item->invoice, $currentSeller, $audit);
                });
            }
        }

        $this->newLine();
        $this->line('total mismatches: '.$report->count());
        $this->line('documents affected: '.$report->pluck('document_id')->unique()->count());
        $this->line('total amount to be removed: '.number_format((int) $report->sum('invoice_total_snapshot')));
        $this->line('audit missing: '.$report->whereNull('audit_id')->count());

        return self::SUCCESS;
    }

    /** @return Collection<int, SellerSalesDocumentItem> */
    private function mismatches(): Collection
    {
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'commission_preinvoices');

        return SellerSalesDocumentItem::query()
            ->select('seller_sales_document_items.*')
            ->with([
                'document.seller:id,name',
                'invoice.seller:id,name',
                'invoice.preinvoiceOrder.seller:id,name',
                'invoice.preinvoiceOrder.creator:id,name',
            ])
            ->active()
            ->join('seller_sales_documents', 'seller_sales_documents.id', '=', 'seller_sales_document_items.seller_sales_document_id')
            ->join('invoices', 'invoices.id', '=', 'seller_sales_document_items.active_invoice_id')
            ->leftJoin('preinvoice_orders as commission_preinvoices', 'commission_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->whereNotNull(DB::raw($effectiveSeller))
            ->whereColumn('seller_sales_documents.seller_id', '<>', DB::raw($effectiveSeller))
            ->orderBy('seller_sales_document_items.id')
            ->get();
    }

    private function reportRow(SellerSalesDocumentItem $item): array
    {
        $currentSeller = $item->invoice->effectiveSeller();
        $audit = $currentSeller ? $this->matchingAudit($item, $currentSeller->id) : null;

        return [
            'item_id' => $item->id,
            'invoice_id' => $item->invoice_id,
            'invoice_number' => $item->invoice_number_snapshot,
            'document_id' => $item->seller_sales_document_id,
            'document_number' => $item->document->document_number,
            'document_seller_id' => $item->document->seller_id,
            'document_seller_name' => $item->document->seller?->name ?: 'نامشخص',
            'current_seller_id' => $currentSeller?->id,
            'current_seller_name' => $currentSeller?->name ?: 'نامشخص',
            'invoice_total_snapshot' => $item->invoice_total_snapshot,
            'audit_id' => $audit?->id,
            'audit_missing' => ! $audit,
        ];
    }

    private function matchingAudit(SellerSalesDocumentItem $item, int $currentSellerId): ?SellerReassignmentAudit
    {
        return SellerReassignmentAudit::query()
            ->where('invoice_id', $item->invoice_id)
            ->where('old_seller_id', $item->document->seller_id)
            ->where('new_seller_id', $currentSellerId)
            ->where('changed_at', '>=', $item->created_at)
            ->latest('changed_at')
            ->latest('id')
            ->first();
    }
}
