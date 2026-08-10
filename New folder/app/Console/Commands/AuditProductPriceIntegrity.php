<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AuditProductPriceIntegrity extends Command
{
    protected $signature = 'inventory:audit-price-integrity {--output=} {--severity=} {--product=} {--variant=} {--format=csv} {--summary}';
    protected $description = 'Read-only audit for zero and inconsistent product/variant prices.';

    private const SEVERITIES = ['Critical' => 4, 'High' => 3, 'Medium' => 2, 'Low' => 1];
    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private const FINAL_INVOICE_STATUSES = ['shipped', 'ready_to_ship', 'pending_collection', 'collecting', 'checking_discrepancy', 'final_check', 'packing', 'warehouse_received', 'pending_finance_reapproval', 'returned_to_sales_after_collection', 'processing'];

    private static ?\WeakMap $guardedConnections = null;
    private static bool $writeGuardEnabled = false;

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('--format must be csv or json.');
            return self::FAILURE;
        }

        $this->installWriteQueryGuard();

        try {
            $anomalies = $this->applyFilters($this->buildAnomalies());
            $suggestions = $this->buildSuggestions($anomalies);
            $summary = $this->buildSummary($anomalies, $suggestions);
            $paths = $this->writeReports($anomalies, $suggestions, $summary, $format);

            $this->line(json_encode(['summary' => $summary, 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } finally {
            $this->disableWriteQueryGuard();
        }
    }

    private function installWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = true;

        $connection = DB::connection();
        self::$guardedConnections ??= new \WeakMap();

        if (isset(self::$guardedConnections[$connection])) {
            return;
        }

        $connection->beforeExecuting(
            function (string $query, array $bindings, Connection $connection): void {
                if (! self::$writeGuardEnabled) {
                    return;
                }

                if ($this->isWriteStatement($query)) {
                    throw new \RuntimeException('Unsafe write query blocked before execution during price audit.');
                }
            }
        );

        self::$guardedConnections[$connection] = true;
    }

    private function disableWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = false;
    }

    private function isWriteStatement(string $query): bool
    {
        $sql = $this->stripLeadingSqlComments($query);

        if (preg_match('/^('.self::WRITE_VERBS.')\b/i', $sql)) {
            return true;
        }

        if (! preg_match('/^with\b/i', $sql)) {
            return false;
        }

        $outerVerb = $this->firstTopLevelVerbAfterCte($sql);

        return $outerVerb !== null && preg_match('/^('.self::WRITE_VERBS.')$/i', $outerVerb) === 1;
    }

    private function stripLeadingSqlComments(string $query): string
    {
        return ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s', ' ', $query) ?? $query);
    }

    private function firstTopLevelVerbAfterCte(string $sql): ?string
    {
        $length = strlen($sql);
        $depth = 0;
        $quote = null;

        for ($i = 4; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $next = $sql[$i + 1] ?? '';
                    if ($next === $quote) {
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            $commentEnd = $this->sqlCommentEnd($sql, $i);
            if ($commentEnd !== null) {
                $i = $commentEnd;
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth === 0 && preg_match('/\G\s*('.self::WRITE_VERBS.'|select)\b/i', $sql, $match, 0, $i)) {
                return strtolower($match[1]);
            }
        }

        return null;
    }

    private function sqlCommentEnd(string $sql, int $offset): ?int
    {
        $next = $sql[$offset + 1] ?? '';

        if ($sql[$offset] === '/' && $next === '*') {
            $end = strpos($sql, '*/', $offset + 2);

            return $end === false ? strlen($sql) - 1 : $end + 1;
        }

        if (($sql[$offset] === '-' && $next === '-') || $sql[$offset] === '#') {
            $end = strcspn($sql, "\r\n", $offset);

            return min(strlen($sql) - 1, $offset + $end);
        }

        return null;
    }

    private function buildAnomalies(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        $rows = [];
        $variantCounts = $this->groupCount('product_variants', 'product_id');
        $warehouseProduct = $this->warehouseStock(false);
        $warehouseVariant = $this->warehouseStock(true);
        $purchase = $this->purchaseStats();
        $sales = $this->salesStats();
        $preinvoice = $this->preinvoiceStats();
        $priceChanges = $this->priceChangeStats();
        $activeVariantMinimums = $this->activeVariantMinimums();

        DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->when($this->option('product'), fn ($q, $id) => $q->where('p.id', $id))
            ->select(['p.id', 'p.name', 'p.code', 'p.sku', 'p.price', 'p.stock', 'p.reserved', 'p.is_sellable', 'p.updated_at', 'p.synced_at', 'c.name as category_name'])
            ->orderBy('p.id')
            ->chunkById(500, function ($products) use (&$rows, $variantCounts, $warehouseProduct, $purchase, $sales, $preinvoice, $priceChanges, $activeVariantMinimums): void {
                foreach ($products as $p) {
                    $stock = (int) ($warehouseProduct[$p->id] ?? 0);
                    $price = $this->num($p->price ?? null);
                    $sellable = (bool) ($p->is_sellable ?? true);
                    $hasVariants = (int) ($variantCounts[$p->id] ?? 0) > 0;
                    $ctx = $this->baseRow($p, null, $stock, (int) ($p->reserved ?? 0), $purchase['product'][$p->id] ?? [], $sales['product'][$p->id] ?? [], $preinvoice['product'][$p->id] ?? [], $priceChanges['product'][$p->id] ?? []);

                    if (! $hasVariants && $sellable && $stock > 0 && $price <= 0) $rows[] = $ctx + ['anomaly_code' => 'A01', 'severity' => 'Critical', 'probable_root_cause' => 'Sellable non-variant product has positive warehouse stock and zero selling price.'];
                    if (! $sellable && $stock <= 0 && $price <= 0) $rows[] = $ctx + ['anomaly_code' => 'A07', 'severity' => 'Low', 'probable_root_cause' => 'Inactive or non-sellable product has zero stock and zero price.'];
                    if ($price <= 0 && isset($activeVariantMinimums[$p->id])) $rows[] = $ctx + ['anomaly_code' => 'A08', 'severity' => 'Medium', 'probable_root_cause' => 'Product summary price is zero while at least one active variant has positive sell_price.'];
                    if ($price > 0 && isset($activeVariantMinimums[$p->id]) && $price !== (int) $activeVariantMinimums[$p->id]) $rows[] = $ctx + ['anomaly_code' => 'A09', 'severity' => 'Medium', 'probable_root_cause' => 'Product summary price differs from minimum positive active variant sell_price.'];
                }
            }, 'p.id', 'id');

        if (Schema::hasTable('product_variants')) {
            DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->when($this->option('product'), fn ($q, $id) => $q->where('p.id', $id))->when($this->option('variant'), fn ($q, $id) => $q->where('v.id', $id))
                ->select(['v.id', 'v.product_id', 'v.variant_name', 'v.variant_code', 'v.sell_price', 'v.buy_price', 'v.stock', 'v.reserved', 'v.is_active', 'v.sales_enabled', 'v.updated_at', 'v.synced_at', 'p.name as product_name', 'p.code as product_code', 'p.sku as product_sku', 'p.price as product_price', 'p.stock as product_stock', 'p.reserved as product_reserved', 'p.is_sellable', 'c.name as category_name'])
                ->orderBy('v.id')->chunkById(500, function ($variants) use (&$rows, $warehouseVariant, $purchase, $sales, $preinvoice, $priceChanges): void {
                    foreach ($variants as $v) {
                        $stock = (int) ($warehouseVariant[$v->id] ?? 0);
                        $sell = $this->num($v->sell_price); $active = (bool) ($v->is_active ?? true); $salesEnabled = (bool) ($v->sales_enabled ?? true);
                        $ctx = $this->baseRow($v, $v, $stock, (int) ($v->reserved ?? 0), $purchase['variant'][$v->id] ?? [], $sales['variant'][$v->id] ?? [], $preinvoice['variant'][$v->id] ?? [], $priceChanges['variant'][$v->id] ?? []);
                        if ($active && $salesEnabled && $stock > 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A02', 'severity' => 'Critical', 'probable_root_cause' => 'Active sellable variant has positive warehouse stock and zero sell_price.'];
                        if ($active && $sell <= 0 && (int) ($ctx['purchase_quantity'] ?? 0) > 0) $rows[] = $ctx + ['anomaly_code' => 'A03', 'severity' => 'High', 'probable_root_cause' => 'Active variant has zero sell_price and positive purchase history.'];
                        if ($active && $sell <= 0 && (int) ($ctx['sales_quantity'] ?? 0) > 0) $rows[] = $ctx + ['anomaly_code' => 'A04', 'severity' => 'Critical', 'probable_root_cause' => 'Active variant has zero sell_price and positive sales history.'];
                        if (! $active && $stock <= 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A07', 'severity' => 'Low', 'probable_root_cause' => 'Inactive variant has zero stock and zero sell_price.'];
                        if ($stock !== (int) ($v->stock ?? 0)) $rows[] = $ctx + ['anomaly_code' => 'A10', 'severity' => 'Medium', 'probable_root_cause' => 'Variant cached stock differs from summed warehouse stock.'];
                    }
                }, 'v.id', 'id');
        }

        return array_merge($rows, $this->documentAnomalies('preinvoice'), $this->documentAnomalies('invoice'));
    }

    private function documentAnomalies(string $type): array
    {
        $isInvoice = $type === 'invoice'; $table = $isInvoice ? 'invoice_items' : 'preinvoice_order_items'; $doc = $isInvoice ? 'invoices' : 'preinvoice_orders';
        if (! Schema::hasTable($table)) return [];
        return DB::table($table.' as i')->leftJoin($doc.' as d', 'd.id', '=', 'i.'.($isInvoice ? 'invoice_id' : 'preinvoice_order_id'))
            ->when($this->option('product'), fn ($q, $id) => $q->where('i.product_id', $id))->when($this->option('variant'), fn ($q, $id) => $q->where('i.variant_id', $id))
            ->where('i.price', '<=', 0)->where('i.quantity', '>', 0)->select(['i.id', 'i.product_id', 'i.variant_id', 'i.quantity', 'i.price', 'i.updated_at', 'd.status'])->orderBy('i.id')->limit(10000)->get()
            ->map(fn ($i) => array_replace($this->emptyRow((int) $i->product_id, $i->variant_id ? (int) $i->variant_id : null), ['updated_at' => $i->updated_at ?? null, 'anomaly_code' => $isInvoice ? 'A06' : 'A05', 'severity' => 'Critical', 'probable_root_cause' => ($isInvoice ? 'Invoice' : 'Preinvoice').' item has positive quantity and zero price.']))->all();
    }

    private function buildSuggestions(array $anomalies): array
    {
        return array_map(function (array $r): array {
            $price = $r['last_positive_sale_price'] ?: $r['last_price_change'] ?: $r['median_positive_sale_price'] ?: $r['last_positive_preinvoice_price'] ?: null;
            $source = $r['last_positive_sale_price'] ? 'invoice' : ($r['last_price_change'] ? 'price_change' : ($r['median_positive_sale_price'] ? 'invoice_median' : ($r['last_positive_preinvoice_price'] ? 'preinvoice' : 'none')));
            return $r + ['suggested_price' => $price, 'suggestion_source' => $source, 'source_record_id' => null, 'source_date' => $r['last_positive_sale_at'] ?: $r['last_price_change_at'], 'confidence' => in_array($source, ['invoice', 'price_change'], true) ? 'High' : ($source === 'invoice_median' || $source === 'preinvoice' ? 'Medium' : 'None'), 'manual_pricing_required' => $price ? false : true];
        }, $anomalies);
    }

    private function baseRow(object $p, ?object $v, int $warehouseStock, int $reservedStock, array $purchase, array $sales, array $pre, array $change): array
    {
        return array_replace($this->emptyRow((int) ($p->product_id ?? $p->id), $v ? (int) $v->id : null), ['product_name' => (string) ($p->product_name ?? $p->name ?? ''), 'product_code' => (string) ($p->product_code ?? $p->code ?? $p->sku ?? ''), 'category' => (string) ($p->category_name ?? ''), 'variant_name' => $v->variant_name ?? null, 'variant_code' => $v->variant_code ?? null, 'is_sellable' => (bool) ($p->is_sellable ?? true), 'is_active' => $v ? (bool) ($v->is_active ?? true) : null, 'sales_enabled' => $v ? (bool) ($v->sales_enabled ?? true) : null, 'product_price' => $this->num($p->product_price ?? $p->price ?? null), 'variant_sell_price' => $v ? $this->num($v->sell_price ?? null) : null, 'variant_buy_price' => $v ? $this->num($v->buy_price ?? null) : null, 'cached_product_stock' => (int) ($p->product_stock ?? $p->stock ?? 0), 'cached_variant_stock' => $v ? (int) ($v->stock ?? 0) : null, 'warehouse_stock' => $warehouseStock, 'reserved_stock' => $reservedStock, 'purchase_quantity' => (int) ($purchase['qty'] ?? 0), 'sales_quantity' => (int) ($sales['qty'] ?? 0), 'last_purchase_price' => $purchase['last_price'] ?? null, 'last_positive_sale_price' => $sales['last_price'] ?? null, 'median_positive_sale_price' => $sales['median_price'] ?? null, 'last_positive_sale_at' => $sales['last_at'] ?? null, 'last_positive_preinvoice_price' => $pre['last_price'] ?? null, 'last_price_change' => $change['last_price'] ?? null, 'last_price_change_at' => $change['last_at'] ?? null, 'last_sync_at' => $p->synced_at ?? $v->synced_at ?? null, 'updated_at' => $p->product_updated_at ?? $p->updated_at ?? null]);
    }

    private function emptyRow(int $productId, ?int $variantId): array
    { return ['product_id' => $productId, 'product_name' => '', 'product_code' => '', 'category' => '', 'variant_id' => $variantId, 'variant_name' => null, 'variant_code' => null, 'is_sellable' => null, 'is_active' => null, 'sales_enabled' => null, 'product_price' => null, 'variant_sell_price' => null, 'variant_buy_price' => null, 'cached_product_stock' => null, 'cached_variant_stock' => null, 'warehouse_stock' => 0, 'reserved_stock' => 0, 'purchase_quantity' => 0, 'sales_quantity' => 0, 'last_purchase_price' => null, 'last_positive_sale_price' => null, 'median_positive_sale_price' => null, 'last_positive_sale_at' => null, 'last_positive_preinvoice_price' => null, 'last_price_change' => null, 'last_price_change_at' => null, 'last_sync_at' => null, 'updated_at' => null]; }

    private function stats(string $table, string $vcol, string $pcol, ?string $qcol, string $price, string $date, bool $positive = false, bool $finalInvoices = false): array
    {
        $out = ['product' => [], 'variant' => []]; if (! Schema::hasTable($table)) return $out;
        $q = DB::table($table.' as i')->when($positive, fn ($q) => $q->where('i.'.$price, '>', 0));
        if ($finalInvoices && Schema::hasTable('invoices')) $q->join('invoices as d', 'd.id', '=', 'i.invoice_id')->whereIn('d.status', self::FINAL_INVOICE_STATUSES);
        $rows = $q->orderBy('i.'.$date, 'desc')->limit(50000)->get(['i.'.$pcol, 'i.'.$vcol, $qcol ? 'i.'.$qcol : DB::raw('0 as quantity'), 'i.'.$price, 'i.'.$date]);
        foreach ($rows as $r) foreach ([['product', $r->$pcol], ['variant', $r->$vcol]] as [$k, $id]) if ($id) { $out[$k][$id]['qty'] = ($out[$k][$id]['qty'] ?? 0) + (int) ($r->quantity ?? 0); $out[$k][$id]['prices'][] = $this->num($r->$price); $out[$k][$id]['last_price'] = $out[$k][$id]['last_price'] ?? $this->num($r->$price); $out[$k][$id]['last_at'] = $out[$k][$id]['last_at'] ?? ($r->$date ?? null); }
        foreach (['product', 'variant'] as $k) foreach ($out[$k] as $id => $r) { $p = $r['prices'] ?? []; sort($p); $out[$k][$id]['median_price'] = $p ? $p[(int) floor((count($p) - 1) / 2)] : null; unset($out[$k][$id]['prices']); }
        return $out;
    }

    private function purchaseStats(): array { return $this->stats('purchase_items', 'product_variant_id', 'product_id', 'quantity', 'buy_price', 'created_at'); }
    private function salesStats(): array { return $this->stats('invoice_items', 'variant_id', 'product_id', 'quantity', 'price', 'created_at', true, true); }
    private function preinvoiceStats(): array { return $this->stats('preinvoice_order_items', 'variant_id', 'product_id', 'quantity', 'price', 'created_at', true); }
    private function priceChangeStats(): array { return Schema::hasTable('price_change_document_items') ? $this->stats('price_change_document_items', 'product_variant_id', 'product_id', null, 'new_price', 'applied_at', true) : ['product' => [], 'variant' => []]; }
    private function warehouseStock(bool $variant): array { if (! Schema::hasTable('warehouse_stocks')) return []; $key = $variant ? 'product_variant_id' : 'product_id'; return DB::table('warehouse_stocks')->when($variant, fn ($q) => $q->whereNotNull('product_variant_id'), fn ($q) => $q->whereNull('product_variant_id'))->select($key, DB::raw('sum(quantity) q'))->groupBy($key)->pluck('q', $key)->map(fn ($v) => (int) $v)->all(); }
    private function activeVariantMinimums(): array { return Schema::hasTable('product_variants') ? DB::table('product_variants')->where('is_active', true)->where('sales_enabled', true)->where('sell_price', '>', 0)->select('product_id', DB::raw('min(sell_price) min_price'))->groupBy('product_id')->pluck('min_price', 'product_id')->map(fn ($v) => (int) $v)->all() : []; }
    private function groupCount(string $t, string $col): array { return Schema::hasTable($t) ? DB::table($t)->select($col, DB::raw('count(*) c'))->groupBy($col)->pluck('c', $col)->map(fn ($v) => (int) $v)->all() : []; }
    private function applyFilters(array $rows): array { $sev = $this->option('severity'); return array_values(array_filter($rows, fn ($r) => ! $sev || (self::SEVERITIES[$r['severity']] ?? 0) >= (self::SEVERITIES[ucfirst(strtolower($sev))] ?? 0))); }
    private function buildSummary(array $a, array $s): array { return ['product_zero_prices' => Schema::hasTable('products') ? DB::table('products')->where(fn ($q) => $q->whereNull('price')->orWhere('price', '<=', 0))->count() : 0, 'variant_zero_sell_prices' => Schema::hasTable('product_variants') ? DB::table('product_variants')->where(fn ($q) => $q->whereNull('sell_price')->orWhere('sell_price', '<=', 0))->count() : 0, 'positive_warehouse_stock' => count(array_filter($a, fn ($r) => ($r['warehouse_stock'] ?? 0) > 0)), 'sellable_zero_price' => count(array_filter($a, fn ($r) => ($r['is_sellable'] ?? false) && in_array($r['anomaly_code'], ['A01', 'A02', 'A03', 'A04'], true))), 'purchased_positive_current_zero' => count(array_filter($a, fn ($r) => $r['anomaly_code'] === 'A03')), 'sold_positive_current_zero' => count(array_filter($a, fn ($r) => $r['anomaly_code'] === 'A04')), 'invoice_zero_line_items' => count(array_filter($a, fn ($r) => $r['anomaly_code'] === 'A06')), 'preinvoice_zero_line_items' => count(array_filter($a, fn ($r) => $r['anomaly_code'] === 'A05')), 'product_summary_desync' => count(array_filter($a, fn ($r) => in_array($r['anomaly_code'], ['A08', 'A09'], true))), 'zero_buy_prices' => Schema::hasTable('product_variants') ? DB::table('product_variants')->where(fn ($q) => $q->whereNull('buy_price')->orWhere('buy_price', '<=', 0))->count() : 0, 'high_confidence_suggestions' => count(array_filter($s, fn ($r) => $r['confidence'] === 'High')), 'manual_pricing_required' => count(array_filter($s, fn ($r) => $r['manual_pricing_required'])), 'total_anomalies' => count($a), 'by_severity' => array_count_values(array_column($a, 'severity')), 'by_code' => array_count_values(array_column($a, 'anomaly_code')), 'data_changed' => false]; }
    private function writeReports(array $a, array $s, array $summary, string $format): array { $dir = $this->option('output') ?: 'reports/price-integrity'; Storage::disk('local')->makeDirectory($dir); $paths = ['summary' => "$dir/summary.json"]; Storage::disk('local')->put($paths['summary'], json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); foreach ([['anomalies', $a], ['suggestions', $s]] as [$name, $rows]) { $paths[$name] = "$dir/$name.$format"; $format === 'json' ? Storage::disk('local')->put($paths[$name], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) : $this->putCsv($paths[$name], $rows); } return array_map(fn ($p) => Storage::disk('local')->path($p), $paths); }
    private function putCsv(string $path, array $rows): void { $head = ['anomaly_code', 'severity', 'product_id', 'product_name', 'variant_id', 'variant_name', 'warehouse_stock', 'purchase_quantity', 'sales_quantity', 'product_price', 'variant_sell_price', 'variant_buy_price', 'last_positive_sale_price', 'last_positive_sale_at', 'suggested_price', 'suggestion_source', 'confidence', 'probable_root_cause', 'manual_pricing_required']; $fh = fopen('php://temp', 'r+'); fputcsv($fh, $head); foreach ($rows as $r) fputcsv($fh, array_map(fn ($h) => $r[$h] ?? '', $head)); rewind($fh); Storage::disk('local')->put($path, stream_get_contents($fh)); }
    private function num(mixed $v): int { return is_null($v) ? 0 : (int) $v; }
}
