<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\PreinvoiceDiscountService;
use App\Support\SalesDocumentTotals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSalesDiscountContracts extends Command
{
    protected $signature = 'sales:discount-contract-audit {--repair : Repair unambiguous product_lines preinvoices with structured groups}';

    protected $description = 'Audit sales discount contracts; dry-run by default.';

    public function handle(PreinvoiceDiscountService $discountService): int
    {
        $repair = (bool) $this->option('repair');
        $issues = 0;
        $fixed = 0;

        foreach ([PreinvoiceOrder::class, Invoice::class] as $model) {
            $model::query()->with('items')->chunkById(200, function ($docs) use (&$issues, &$fixed, $repair, $discountService, $model) {
                foreach ($docs as $doc) {
                    $totals = SalesDocumentTotals::fromDocument($doc);
                    $productDiscount = (int) $doc->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
                    $expectedDiscount = (int) $totals['total_discount'];
                    $expectedTotal = (int) $totals['grand_total'];
                    $storedTotal = (int) ($doc instanceof PreinvoiceOrder ? $doc->total_price : $doc->total);
                    $bad = $productDiscount !== (int) ($doc->product_discount_amount ?? $productDiscount)
                        || (int) ($doc->discount_amount ?? 0) !== $expectedDiscount
                        || $storedTotal !== $expectedTotal;

                    if (! $bad) {
                        continue;
                    }

                    $issues++;
                    $this->warn(class_basename($model).' #'.$doc->id.' uuid='.$doc->uuid.' mode='.($doc->discount_allocation_mode ?? 'NULL'));

                    $hasGroups = ! empty($doc->discount_breakdown['groups'] ?? []);
                    if ($repair && $doc instanceof PreinvoiceOrder && $doc->discount_allocation_mode === 'product_lines' && $hasGroups && $productDiscount === 0) {
                        DB::transaction(fn () => $discountService->applyToOrder($doc->refresh()->load('items'), [
                            'discount_breakdown' => $doc->discount_breakdown,
                            'invoice_discount_type' => $doc->invoice_discount_type,
                            'invoice_discount_value' => (int) ($doc->invoice_discount_value ?? 0),
                        ]));
                        $fixed++;
                    }
                }
            });
        }

        $this->info("Issues: {$issues}; repaired: {$fixed}; mode: ".($repair ? 'repair' : 'dry-run'));

        return self::SUCCESS;
    }
}
