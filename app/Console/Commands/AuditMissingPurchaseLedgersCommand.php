<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\StockMovement;
use App\Services\SupplierLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMissingPurchaseLedgersCommand extends Command
{
    protected $signature = 'purchases:audit-missing-ledger {--dry-run : فقط گزارش بگیر و هیچ تغییری اعمال نکن} {--fix : برای خریدهای معتبر فاقد گردش حساب، بستانکاری تأمین‌کننده بساز}';

    protected $description = 'Audit and optionally backfill missing supplier ledger credits for purchase documents.';

    public function handle(SupplierLedgerService $ledgerService): int
    {
        $fix = (bool) $this->option('fix');
        $dryRun = (bool) $this->option('dry-run') || ! $fix;

        if ($fix && (bool) $this->option('dry-run')) {
            $this->error('از --dry-run و --fix همزمان استفاده نکنید.');
            return self::FAILURE;
        }

        $rows = $this->missingLedgerPurchases()->get();

        $this->info(($dryRun ? 'DRY RUN' : 'FIX') . ': خریدهای معتبر فاقد گردش حساب تأمین‌کننده (' . $rows->count() . ')');
        if ($rows->isEmpty()) {
            $this->line('موردی یافت نشد.');
            return self::SUCCESS;
        }

        $this->table(
            ['purchase_id', 'supplier_id', 'items_count', 'items_total', 'purchase_total', 'stock_applied', 'purchased_at'],
            $rows->map(fn ($row) => [
                (int) $row->id,
                (int) $row->supplier_id,
                (int) $row->items_count,
                (int) $row->items_total,
                (int) $row->total_amount,
                ((int) $row->stock_movements_count > 0) ? 'yes' : 'unknown',
                (string) $row->purchased_at,
            ])->all()
        );

        if ($dryRun) {
            $this->warn('بدون --fix هیچ داده‌ای تغییر نکرد.');
            return self::SUCCESS;
        }

        $created = 0;
        DB::transaction(function () use ($rows, $ledgerService, &$created): void {
            foreach ($rows as $row) {
                $purchase = Purchase::query()
                    ->withCount('items')
                    ->whereKey((int) $row->id)
                    ->lockForUpdate()
                    ->first();

                if (! $purchase || ! $this->isValidForBackfill($purchase)) {
                    continue;
                }

                $alreadyExists = DB::table('supplier_ledgers')
                    ->where('supplier_id', (int) $purchase->supplier_id)
                    ->where('reference_type', Purchase::class)
                    ->where('reference_id', (int) $purchase->id)
                    ->where('type', 'credit')
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $ledgerService->syncPurchaseCredit($purchase);
                $created++;
            }
        });

        $this->info("{$created} رکورد گردش حساب تأمین‌کننده ساخته شد.");

        return self::SUCCESS;
    }

    private function missingLedgerPurchases()
    {
        $itemTotals = DB::table('purchase_items')
            ->select('purchase_id', DB::raw('COUNT(*) AS items_count'), DB::raw('SUM(line_total) AS items_total'))
            ->groupBy('purchase_id');

        return DB::table('purchases as p')
            ->joinSub($itemTotals, 'pi', 'pi.purchase_id', '=', 'p.id')
            ->leftJoin('supplier_ledgers as sl', function ($join): void {
                $join->on('sl.supplier_id', '=', 'p.supplier_id')
                    ->where('sl.reference_type', Purchase::class)
                    ->whereColumn('sl.reference_id', 'p.id')
                    ->where('sl.type', 'credit');
            })
            ->whereNotNull('p.supplier_id')
            ->where('p.total_amount', '>', 0)
            ->where('pi.items_count', '>', 0)
            ->whereNull('sl.id')
            ->orderBy('p.id')
            ->select([
                'p.id',
                'p.supplier_id',
                'p.total_amount',
                'p.purchased_at',
                'pi.items_count',
                'pi.items_total',
                DB::raw("(SELECT COUNT(*) FROM stock_movements sm WHERE (sm.reference_type = '" . addslashes(Purchase::class) . "' AND sm.reference_id = p.id) OR (sm.reason = '" . StockMovement::REASON_PURCHASE . "' AND sm.reference = CONCAT('PUR-', p.id))) AS stock_movements_count"),
            ]);
    }

    private function isValidForBackfill(Purchase $purchase): bool
    {
        return (int) $purchase->supplier_id > 0
            && (int) $purchase->total_amount > 0
            && (int) $purchase->items_count > 0;
    }
}
