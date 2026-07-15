<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InventoryReconcileCommand extends Command
{
    protected $signature = 'inventory:reconcile {--product= : Reconcile one product id} {--dry-run : Report only} {--apply : Apply safe rows} {--backup : Create backup tables before apply} {--exclude-suspicious-reservations : Ignore suspicious active reservations in expected stock/reserved calculations} {--cleanup-no-purchase-no-price : Zero fake stock for variants with no purchases and no sell price}';
    protected $description = 'Audit and safely reconcile product variant stock, warehouse stock, reservations, and sell prices.';

    public function handle(InventoryReconciliationService $service): int
    {
        $productId = $this->option('product') !== null ? (int) $this->option('product') : null;
        $apply = (bool) $this->option('apply');
        $excludeSuspiciousReservations = (bool) $this->option('exclude-suspicious-reservations');
        $cleanupNoPurchaseNoPrice = (bool) $this->option('cleanup-no-purchase-no-price');
        if ($apply && ! $this->option('backup')) { $this->error('Refusing apply without --backup.'); return SymfonyCommand::FAILURE; }

        $invalid = $service->invalidInvoiceStatuses($productId);
        if ($invalid->isNotEmpty()) { $this->warn('Invoice statuses excluded from stock calculation:'); $this->table(['status','invoices_count','qty'], $invalid->map(fn($r)=>(array)$r)->all()); }
        if ($apply && $invalid->isNotEmpty()) { $this->error('Resolve/report excluded invoice statuses before apply.'); return SymfonyCommand::FAILURE; }

        if ($service->hasReservationCalculationMismatchForProduct(24, $excludeSuspiciousReservations)) {
            $this->error('Reservation calculation mismatch for product_id=24');
            return SymfonyCommand::FAILURE;
        }

        $product24Rows = $service->rows(24, $excludeSuspiciousReservations);
        if ($apply && ! $excludeSuspiciousReservations && $product24Rows->contains(fn ($row) => in_array('suspicious_reservation', $row['flags'], true))) {
            $this->error('Product_id=24 has suspicious reservations. Re-run dry-run with --exclude-suspicious-reservations and review before apply.');
            return SymfonyCommand::FAILURE;
        }

        $rows = $productId === 24 ? $product24Rows : $service->rows($productId, $excludeSuspiciousReservations);
        $cleanupRows = $cleanupNoPurchaseNoPrice ? $service->cleanupNoPurchaseNoPriceRows($rows) : collect();
        $reportRows = $rows->filter(fn($r)=>$r['blocked'] || $r['flags'] || $r['difference'] !== 0 || $r['current_reserved'] !== $r['expected_reserved'])->values();
        $this->info($apply ? 'APPLY mode: only safe rows will be updated.' : 'DRY-RUN mode: no data changed.');
        $summary = $service->summary($rows, $cleanupRows);
        $this->table(array_keys($summary), [$summary]);
        if ($reportRows->isNotEmpty()) $this->table(['variant_id','product_id','product_name','variant_name','current_stock','current_reserved','warehouse_stock','purchase_qty','invoice_qty','active_reserved_qty','expected_stock','expected_reserved','difference','flags','suspicious_preinvoices','ignored_suspicious_reservation'], $reportRows->map(fn($r)=>[...array_intersect_key($r, array_flip(['variant_id','product_id','product_name','variant_name','current_stock','current_reserved','warehouse_stock','purchase_qty','invoice_qty','active_reserved_qty','expected_stock','expected_reserved','difference'])), 'flags'=>$r['flags_text'], 'suspicious_preinvoices'=>$r['suspicious_preinvoices'], 'ignored_suspicious_reservation'=>$r['ignored_suspicious_reservation']])->all());
        if ($cleanupRows->isNotEmpty()) {
            $this->warn('Cleanup no-purchase/no-price rows planned for zeroing:');
            $this->table(['variant_id','product_id','product_name','variant_name','current_stock','warehouse_stock','purchase_qty','invoice_qty','sell_price','expected_stock_after_cleanup','flags'], $cleanupRows->map(fn($r)=>[
                'variant_id'=>$r['variant_id'], 'product_id'=>$r['product_id'], 'product_name'=>$r['product_name'], 'variant_name'=>$r['variant_name'],
                'current_stock'=>$r['current_stock'], 'warehouse_stock'=>$r['warehouse_stock'], 'purchase_qty'=>$r['purchase_qty'], 'invoice_qty'=>$r['invoice_qty'],
                'sell_price'=>$r['current_sell_price'], 'expected_stock_after_cleanup'=>0, 'flags'=>$r['cleanup_flags_text'],
            ])->all());
        }
        if (! $apply) return SymfonyCommand::SUCCESS;

        $central = $service->centralWarehouseId();
        if (! $central) { $this->error('Central warehouse not found.'); return SymfonyCommand::FAILURE; }
        $backups = $this->createBackups(); $this->info('Backup tables created: '.implode(', ', $backups));
        $safe = $rows->where('blocked', false);
        $cleanupVariantIds = $cleanupRows->pluck('variant_id')->map(fn ($id) => (int) $id)->all();
        $safe = $safe->reject(fn ($row) => in_array((int) $row['variant_id'], $cleanupVariantIds, true));
        DB::transaction(function() use ($safe, $cleanupRows, $central) {
            $touched=[];
            foreach ($cleanupRows as $r) {
                DB::table('product_variants')->where('id',$r['variant_id'])->lockForUpdate()->update(['stock'=>0, 'reserved'=>0, 'updated_at'=>now()]);
                $this->setWarehouse($central, $r['product_id'], $r['variant_id'], 0);
                $touched[$r['product_id']] = true;
            }
            foreach ($safe as $r) {
                $update = ['stock'=>$r['expected_stock'], 'reserved'=>$r['expected_reserved'], 'updated_at'=>now()];
                if ($r['expected_sell_price'] && $r['current_stock'] >= 0) { if ($r['expected_buy_price']) $update['buy_price']=$r['expected_buy_price']; $update['sell_price']=$r['expected_sell_price']; }
                DB::table('product_variants')->where('id',$r['variant_id'])->lockForUpdate()->update($update);
                $this->setWarehouse($central, $r['product_id'], $r['variant_id'], $r['expected_stock']);
                $touched[$r['product_id']] = true;
            }
            foreach (array_keys($touched) as $pid) {
                $sum=(int)DB::table('product_variants')->where('product_id',$pid)->sum('stock');
                $min=DB::table('product_variants')->where('product_id',$pid)->where('sell_price','>',0)->min('sell_price');
                DB::table('products')->where('id',$pid)->lockForUpdate()->update(array_filter(['stock'=>$sum,'price'=>$min,'updated_at'=>now()], fn($v)=>$v!==null));
                $this->setWarehouse($central, $pid, null, $sum);
            }
        });
        $after = $service->rows($productId, $excludeSuspiciousReservations); $this->info('Post-apply audit:');
        $this->table(['fake_30000_remaining','mismatch_remaining','reservation_or_sales_conflicts_remaining'], [[
            $after->filter(fn($r)=>in_array('fake_30000',$r['flags'],true))->count(),
            $after->filter(fn($r)=>in_array('warehouse_mismatch',$r['flags'],true) || $r['difference'] !== 0)->count(),
            $after->filter(fn($r)=>array_intersect($r['flags'], ['reservation_without_purchase','sold_more_than_purchased','suspicious_reservation']))->count(),
        ]]);
        return SymfonyCommand::SUCCESS;
    }

    private function createBackups(): array
    { $ts=now()->format('Ymd_His'); $names=[]; foreach(['product_variants','warehouse_stocks','products'] as $t){ $name="backup_{$t}_reconcile_{$ts}"; DB::statement("CREATE TABLE `{$name}` AS SELECT * FROM `{$t}`"); $names[]=$name; } return $names; }

    private function setWarehouse(int $warehouseId, int $productId, ?int $variantId, int $qty): void
    { $q=DB::table('warehouse_stocks')->where('warehouse_id',$warehouseId)->where('product_id',$productId); $variantId===null?$q->whereNull('product_variant_id'):$q->where('product_variant_id',$variantId); $existing=$q->lockForUpdate()->first('id'); if($existing){ DB::table('warehouse_stocks')->where('id',$existing->id)->update(['quantity'=>$qty,'updated_at'=>now()]); } else { DB::table('warehouse_stocks')->insert(['warehouse_id'=>$warehouseId,'product_id'=>$productId,'product_variant_id'=>$variantId,'quantity'=>$qty,'created_at'=>now(),'updated_at'=>now()]); } }
}
