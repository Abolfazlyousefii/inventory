<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductSalesStatusService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QuarantineZeroPriceVariants extends Command
{
    protected $signature = 'inventory:quarantine-zero-price-variants {--dry-run : Preview only} {--apply : Create audited sales-deactivation documents for matching variants} {--actor= : Existing responsible user ID, required with --apply} {--reason= : Reason for the temporary sales block, required with --apply} {--output=reports/zero-price-quarantine}';
    protected $description = 'Disable sales for active sellable zero-price variants that still have positive warehouse stock.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private static bool $writeGuardEnabled = false;
    private static ?\WeakMap $guardedConnections = null;

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');
            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $actor = null;
        $reason = trim((string) $this->option('reason'));
        if ($apply) {
            $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
            if (! $actorId || $actorId <= 0 || $reason === '' || mb_strlen($reason) > 1900) {
                $this->error('--apply requires --actor=<user id> and --reason=<nonempty reason> (up to 1900 characters).');
                return self::FAILURE;
            }
            $actor = User::query()->find($actorId);
            if (! $actor) {
                $this->error('The responsible user was not found. No variants were changed.');
                return self::FAILURE;
            }
        }
        if (! $apply) $this->installWriteQueryGuard();
        try {
            $report = DB::transaction(function () use ($apply, $actor, $reason): array {
                $rows = $this->candidateRows();
                if ($apply && $rows !== []) {
                    $productIds = array_values(array_unique(array_column($rows, 'product_id')));
                    sort($productIds);
                    // Price documents lock products before variants. Keep the same lock order
                    // and recheck eligibility after locking to avoid stale selection.
                    Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
                    ProductVariant::query()->whereIn('product_id', $productIds)
                        ->orderBy('product_id')->orderBy('id')->lockForUpdate()->get(['id']);
                    $rows = $this->candidateRows();
                    foreach (collect($rows)->groupBy('product_id') as $productId => $group) {
                        app(ProductSalesStatusService::class)->change(
                            (int) $productId,
                            ProductDeactivationDocument::ACTION_DEACTIVATE,
                            ProductDeactivationDocument::SCOPE_VARIANTS,
                            $group->pluck('variant_id')->all(),
                            'custom',
                            'INV-05: Zero/missing sale price; '. $reason,
                            $actor
                        );
                    }
                }
                if (! $apply) {
                    $rows = array_map(fn ($row) => array_replace($row, ['sales_enabled_after' => true]), $rows);
                }
                $varianceRows = $this->priceVarianceRows();
                return ['rows' => $rows, 'variance_rows' => $varianceRows,
                    'variance_variants' => $this->priceVarianceVariants($varianceRows),
                    'summary' => $this->summary($rows, $apply)];
            }, 3);
        } finally {
            $this->disableWriteQueryGuard();
        }
        $paths = $this->writeReports($report);
        $this->line(json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'summary' => $report['summary'], 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    private function candidateRows(): array
    {
        return DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('warehouse_stocks as ws', 'ws.product_variant_id', '=', 'v.id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('p.is_sellable', true)->where('v.is_active', true)->where('v.sales_enabled', true)
            ->whereRaw('COALESCE(v.sell_price, 0) <= 0')
            ->groupBy('p.id','p.name','v.id','v.variant_name','v.variant_code','v.sell_price','v.sales_enabled','p.uniform_sale_price')
            ->havingRaw('SUM(CASE WHEN ws.quantity > 0 THEN ws.quantity ELSE 0 END) > 0')
            ->selectRaw("p.id as product_id, p.name as product_name, v.id as variant_id, v.variant_name, v.variant_code, SUM(CASE WHEN w.type = 'central' AND ws.quantity > 0 THEN ws.quantity ELSE 0 END) as central_stock, SUM(CASE WHEN (w.type IS NULL OR w.type <> 'central') AND ws.quantity > 0 THEN ws.quantity ELSE 0 END) as non_central_stock, v.sell_price, p.uniform_sale_price as reference_price, v.sales_enabled as sales_enabled_before")
            ->get()->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'variant_id' => (int) $r->variant_id,
                'variant_name' => (string) ($r->variant_name ?? ''),
                'variant_code' => (string) ($r->variant_code ?? ''),
                'central_stock' => (int) $r->central_stock,
                'non_central_stock' => (int) $r->non_central_stock,
                'sell_price' => $r->sell_price === null ? null : (int) $r->sell_price,
                'reference_price' => $r->reference_price === null ? null : (int) $r->reference_price,
                'sales_enabled_before' => (bool) $r->sales_enabled_before,
                'sales_enabled_after' => false,
            ])->all();
    }

    /** Price variance is a review item, not a reason to force a uniform price. */
    private function priceVarianceRows(): array
    {
        return DB::table('products as p')->join('product_variants as v', 'v.product_id', '=', 'p.id')
            ->where('p.is_sellable', true)->where('v.is_active', true)
            ->where('v.sales_enabled', true)->where('v.sell_price', '>', 0)
            ->groupBy('p.id', 'p.name', 'p.uniform_sale_price')
            ->havingRaw('COUNT(DISTINCT v.sell_price) > 1')
            ->selectRaw('p.id as product_id, p.name as product_name, p.uniform_sale_price as reference_price, COUNT(DISTINCT v.sell_price) as distinct_prices, MIN(v.sell_price) as min_price, MAX(v.sell_price) as max_price')
            ->orderBy('p.id')->get()->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'reference_price' => $r->reference_price === null ? null : (int) $r->reference_price,
                'distinct_prices' => (int) $r->distinct_prices,
                'min_price' => (int) $r->min_price,
                'max_price' => (int) $r->max_price,
                'policy_approved' => 'PENDING_REVIEW',
            ])->all();
    }

    private function priceVarianceVariants(array $varianceRows): array
    {
        $productIds = array_column($varianceRows, 'product_id');
        if ($productIds === []) return [];

        return DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.product_id', $productIds)->where('v.is_active', true)
            ->where('v.sales_enabled', true)->where('v.sell_price', '>', 0)
            ->orderBy('v.product_id')->orderBy('v.id')
            ->get(['v.product_id', 'p.name as product_name', 'v.id as variant_id', 'v.variant_name', 'v.sell_price', 'v.stock', 'p.uniform_sale_price as reference_price'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function summary(array $rows, bool $apply): array
    {
        return [
            'central_zero_price_disabled' => $apply ? count(array_filter($rows, fn ($r) => (int) $r['central_stock'] > 0)) : 0,
            'non_central_zero_price_disabled' => $apply ? count(array_filter($rows, fn ($r) => (int) $r['non_central_stock'] > 0)) : 0,
            'total_disabled' => $apply ? count($rows) : 0,
            'would_disable' => count($rows),
            'prices_changed' => false,
            'stock_changed' => false,
            'reserved_changed' => false,
        ];
    }

    private function writeReports(array $report): array
    {
        $base = trim((string) $this->option('output'), '/');
        Storage::disk('local')->put("$base/zero-price-quarantine.csv", $this->csv($report['rows'], ['product_id','product_name','variant_id','variant_name','variant_code','central_stock','non_central_stock','sell_price','reference_price','sales_enabled_before','sales_enabled_after']));
        Storage::disk('local')->put("$base/positive-price-variance-variants.csv", $this->csv($report['variance_variants'], ['product_id','product_name','variant_id','variant_name','sell_price','stock','reference_price']));
        Storage::disk('local')->put("$base/positive-price-variance-review.csv", $this->csv($report['variance_rows'], ['product_id','product_name','reference_price','distinct_prices','min_price','max_price','policy_approved']));
        Storage::disk('local')->put("$base/summary.json", json_encode($report['summary'] + ['products_requiring_price_policy_review' => count($report['variance_rows'])], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ["$base/zero-price-quarantine.csv", "$base/positive-price-variance-review.csv", "$base/positive-price-variance-variants.csv", "$base/summary.json"];
    }

    private function csv(array $rows, array $head): string { $h=fopen('php://temp','r+'); fputcsv($h,$head); foreach($rows as $r) fputcsv($h, array_map(fn($k)=>$r[$k] ?? '', $head)); rewind($h); return stream_get_contents($h); }
    private function installWriteQueryGuard(): void { self::$writeGuardEnabled=true; $c=DB::connection(); self::$guardedConnections ??= new \WeakMap(); if(isset(self::$guardedConnections[$c])) return; $c->beforeExecuting(function(string $q,array $b,Connection $c): void { if(self::$writeGuardEnabled && preg_match('/^('.self::WRITE_VERBS.')\b/i', ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s',' ',$q) ?? $q))) throw new \RuntimeException('Unsafe write query blocked before execution during zero price quarantine dry run.'); }); self::$guardedConnections[$c]=true; }
    private function disableWriteQueryGuard(): void { self::$writeGuardEnabled=false; }
}
