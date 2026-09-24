<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use App\Services\Finance\SellerCommissionBasePreviewCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateDraftCommissions extends Command
{
    protected $signature = 'commissions:recalculate-drafts {--dry-run : فقط نمایش تغییرات بدون اعمال}';

    protected $description = 'بازمحاسبه پورسانت آیتم‌های اسناد پیش‌نویس فروشنده';

    public function handle(SellerCommissionBasePreviewCalculator $calculator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $documents = SellerSalesDocument::query()
            ->where('status', SellerSalesDocument::STATUS_DRAFT)
            ->with(['items' => fn ($query) => $query->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)])
            ->get();

        if ($documents->isEmpty()) {
            $this->info('هیچ سند پیش‌نویسی یافت نشد.');

            return self::SUCCESS;
        }

        $this->info("تعداد اسناد پیش‌نویس: {$documents->count()}");

        foreach ($documents as $document) {
            $this->line("--- سند {$document->document_number} (ID: {$document->id}) ---");

            $invoices = Invoice::query()
                ->with(['items.product.category.parent', 'items.variant'])
                ->whereIn('id', $document->items->pluck('invoice_id')->unique()->all())
                ->get()
                ->keyBy('id');

            $dates = $invoices->map(fn (Invoice $invoice) => $invoice->display_document_date)->filter();
            if ($dates->isNotEmpty()) {
                $calculator->warm($dates->min(), $dates->max()->copy()->addDay());
            }

            $totalCommission = 0;
            $missingCount = 0;
            $updatedItems = 0;
            $updates = [];

            foreach ($document->items as $item) {
                $invoice = $invoices->get($item->invoice_id);
                if (! $invoice) {
                    $this->warn("  فاکتور {$item->invoice_id} یافت نشد — skip");

                    continue;
                }

                $preview = $calculator->calculate($invoice);
                $rows = collect($preview['items']);
                $itemCommission = (int) $preview['calculated_commission_total'];
                $isMissing = $preview['missing_rate_item_count'] > 0;
                $invoiceTotal = (int) $preview['invoice_total'];
                $rateSnapshot = $invoiceTotal > 0
                    ? number_format($itemCommission * 100 / $invoiceTotal, 4, '.', '')
                    : '0.0000';

                $oldCommission = (int) $item->commission_amount;
                $oldMissing = (bool) $item->missing_rate;

                if ($oldCommission !== $itemCommission || $oldMissing !== $isMissing) {
                    $updatedItems++;
                    $this->line("  فاکتور #{$item->invoice_number_snapshot}: پورسانت {$oldCommission} → {$itemCommission}".($isMissing ? ' [فاقد نرخ]' : ''));

                    $single = $rows->count() === 1 ? $rows->first() : null;
                    $updates[] = [$item, [
                        'rate_snapshot' => $rateSnapshot,
                        'rate_source_type' => $single['rule_source'] ?? null,
                        'rate_source_id' => $single ? ($single['variant_id'] ?? $single['product_id']) : null,
                        'rate_rule_id' => $single['rate_revision_id'] ?? null,
                        'commission_amount' => $itemCommission,
                        'missing_rate' => $isMissing,
                        'calculation_version' => 2,
                    ]];
                }

                $totalCommission += $itemCommission;
                if ($isMissing) {
                    $missingCount++;
                }
            }

            if (! $dryRun && $updates !== []) {
                DB::transaction(function () use ($document, $updates) {
                    foreach ($updates as [$item, $payload]) {
                        $item->update($payload);
                    }

                    $items = $document->items()
                        ->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)
                        ->get();
                    $totalComm = (int) $items->sum('commission_amount');
                    $totalAdj = (int) $document->adjustments()->sum('amount');
                    $bonus = (int) $document->bonus_amount;

                    $document->update([
                        'invoice_count' => $items->count(),
                        'total_sales_amount' => (int) $items->sum('item_net_amount'),
                        'total_commission_amount' => $totalComm,
                        'total_adjustment_amount' => $totalAdj,
                        'bonus_amount' => $bonus,
                        'net_commission_amount' => $totalComm + $totalAdj + $bonus,
                        'missing_rate_count' => $items->where('missing_rate', true)->count(),
                    ]);
                });
            }

            $this->info("  آیتم‌های تغییریافته: {$updatedItems} | پورسانت کل: {$totalCommission} | فاقد نرخ: {$missingCount}");
        }

        if ($dryRun) {
            $this->warn('حالت dry-run — هیچ تغییری اعمال نشد.');
        } else {
            $this->info('بازمحاسبه با موفقیت انجام شد.');
        }

        return self::SUCCESS;
    }
}
