<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QuarantineZeroPriceVariants extends Command
{
    protected $signature = 'inventory:quarantine-zero-price-variants {--dry-run : Preview only} {--apply : Disable sales for matching variants} {--output=reports/zero-price-quarantine}';
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
        if (! $apply) $this->installWriteQueryGuard();
        try {
            $report = DB::transaction(function () use ($apply): array {
                $rows = $this->candidateRows();
                if ($apply && $rows !== []) {
                    DB::table('product_variants')->whereIn('id', array_column($rows, 'variant_id'))->lockForUpdate()->update(['sales_enabled' => false, 'updated_at' => now()]);
                    $rows = array_map(fn ($r) => array_replace($r, ['sales_enabled_after' => false]), $rows);
                }
                return ['rows' => $rows, 'summary' => $this->summary($rows)];
            }, 1);
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
            ->where('v.is_active', true)->where('v.sales_enabled', true)->where('v.sell_price', '<=', 0)
            ->groupBy('p.id','p.name','v.id','v.variant_name','v.variant_code','v.sell_price','v.sales_enabled')
            ->havingRaw('SUM(CASE WHEN ws.quantity > 0 THEN ws.quantity ELSE 0 END) > 0')
            ->selectRaw("p.id as product_id, p.name as product_name, v.id as variant_id, v.variant_name, v.variant_code, SUM(CASE WHEN w.type = 'central' AND ws.quantity > 0 THEN ws.quantity ELSE 0 END) as central_stock, SUM(CASE WHEN (w.type IS NULL OR w.type <> 'central') AND ws.quantity > 0 THEN ws.quantity ELSE 0 END) as non_central_stock, v.sell_price, v.sales_enabled as sales_enabled_before")
            ->get()->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'product_name' => (string) $r->product_name,
                'variant_id' => (int) $r->variant_id,
                'variant_name' => (string) ($r->variant_name ?? ''),
                'variant_code' => (string) ($r->variant_code ?? ''),
                'central_stock' => (int) $r->central_stock,
                'non_central_stock' => (int) $r->non_central_stock,
                'sell_price' => (int) $r->sell_price,
                'sales_enabled_before' => (bool) $r->sales_enabled_before,
                'sales_enabled_after' => false,
            ])->all();
    }

    private function summary(array $rows): array
    {
        return [
            'central_zero_price_disabled' => count(array_filter($rows, fn ($r) => (int) $r['central_stock'] > 0)),
            'non_central_zero_price_disabled' => count(array_filter($rows, fn ($r) => (int) $r['non_central_stock'] > 0)),
            'total_disabled' => count($rows),
            'prices_changed' => false,
            'stock_changed' => false,
            'reserved_changed' => false,
        ];
    }

    private function writeReports(array $report): array
    {
        $base = trim((string) $this->option('output'), '/');
        Storage::disk('local')->put("$base/zero-price-quarantine.csv", $this->csv($report['rows'], ['product_id','product_name','variant_id','variant_name','variant_code','central_stock','non_central_stock','sell_price','sales_enabled_before','sales_enabled_after']));
        Storage::disk('local')->put("$base/summary.json", json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return ["$base/zero-price-quarantine.csv", "$base/summary.json"];
    }

    private function csv(array $rows, array $head): string { $h=fopen('php://temp','r+'); fputcsv($h,$head); foreach($rows as $r) fputcsv($h, array_map(fn($k)=>$r[$k] ?? '', $head)); rewind($h); return stream_get_contents($h); }
    private function installWriteQueryGuard(): void { self::$writeGuardEnabled=true; $c=DB::connection(); self::$guardedConnections ??= new \WeakMap(); if(isset(self::$guardedConnections[$c])) return; $c->beforeExecuting(function(string $q,array $b,Connection $c): void { if(self::$writeGuardEnabled && preg_match('/^('.self::WRITE_VERBS.')\b/i', ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s',' ',$q) ?? $q))) throw new \RuntimeException('Unsafe write query blocked before execution during zero price quarantine dry run.'); }); self::$guardedConnections[$c]=true; }
    private function disableWriteQueryGuard(): void { self::$writeGuardEnabled=false; }
}
