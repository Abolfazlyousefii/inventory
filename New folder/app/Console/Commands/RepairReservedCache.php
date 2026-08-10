<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RepairReservedCache extends Command
{
    protected $signature = 'inventory:repair-reserved-cache {--dry-run : Preview only} {--apply : Persist reserved cache repair} {--output=reports/reserved-cache-repair} {--exclude-order=* : Additional preinvoice order IDs to exclude}';
    protected $description = 'Safely rebuild product and variant reserved caches from protected preinvoice demand plus active temporary reservations.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private const PROTECTED_STATUSES = ['reserved_waiting_warehouse','warehouse_reviewing','warehouse_approved_waiting_finance','finance_reviewing','pending_finance','returned_to_warehouse'];
    private const TEMPORARY_SCOPES = ['temporary_online','temporary_in_person'];

    private static bool $writeGuardEnabled = false;
    private static ?\WeakMap $guardedConnections = null;

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $started = now()->toISOString();

        if (! $apply) {
            $this->installWriteQueryGuard();
        }

        try {
            $report = DB::transaction(function () use ($apply, $started): array {
                $this->lockInScopeRows($apply);
                $report = $this->buildReport($started, false);
                if ($apply) {
                    $this->applyReport($report);
                    $report = $this->buildReport($started, true, $report);
                }

                return $report;
            }, 1);
        } finally {
            $this->disableWriteQueryGuard();
        }

        $paths = $this->writeReports($report);
        $this->line(json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'summary' => $report['summary'], 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function buildReport(string $started, bool $afterApply, ?array $beforeReport = null): array
    {
        $products = DB::table('products')->pluck('name', 'id')->all();
        $variants = DB::table('product_variants')->get(['id','product_id','variant_name','variant_code','reserved'])->keyBy('id');
        $protected = $this->protectedDemand();
        $temporary = $this->activeTemporary();
        $excluded = $this->excludedVariantIds();
        $excludedActive = $this->excludedActiveReservations($excluded);
        $unresolved = $this->unresolvedCancelledFinanceRows();
        $candidateIds = $variants->keys()->merge(array_keys($protected))->merge(array_keys($temporary))->unique()->reject(fn ($id) => isset($excluded[(int) $id]))->values();

        $changes = [];
        foreach ($candidateIds as $variantId) {
            $variant = $variants[(int) $variantId] ?? null;
            if (! $variant) {
                continue;
            }
            $beforeReserved = $beforeReport['changes_by_variant'][(int) $variantId]['reserved_before'] ?? (int) $variant->reserved;
            $expected = (int) ($protected[(int) $variantId] ?? 0) + (int) ($temporary[(int) $variantId] ?? 0);
            $current = (int) $variant->reserved;
            if ($beforeReserved !== $expected || (! $afterApply && $current !== $expected)) {
                $changes[] = [
                    'product_id' => (int) $variant->product_id,
                    'product_name' => (string) ($products[$variant->product_id] ?? ''),
                    'variant_id' => (int) $variantId,
                    'variant_name' => (string) ($variant->variant_name ?? ''),
                    'variant_code' => (string) ($variant->variant_code ?? ''),
                    'reserved_before' => $beforeReserved,
                    'protected_document_demand' => (int) ($protected[(int) $variantId] ?? 0),
                    'active_temporary_quantity' => (int) ($temporary[(int) $variantId] ?? 0),
                    'expected_reserved' => $expected,
                    'reserved_after' => $afterApply ? $current : $expected,
                ];
            }
        }

        $summary = [
            'started_at' => $started,
            'finished_at' => now()->toISOString(),
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'variants_scanned' => $candidateIds->count(),
            'variants_changed' => count(array_filter($changes, fn ($r) => (int) $r['reserved_before'] !== (int) $r['reserved_after'])),
            'reserved_before' => array_sum(array_column($changes, 'reserved_before')),
            'reserved_after' => array_sum(array_column($changes, 'reserved_after')),
            'reserved_reduced' => array_sum(array_map(fn ($r) => max(0, (int) $r['reserved_before'] - (int) $r['reserved_after']), $changes)),
            'reserved_increased' => array_sum(array_map(fn ($r) => max(0, (int) $r['reserved_after'] - (int) $r['reserved_before']), $changes)),
            'excluded_variants' => count($excluded),
            'unresolved_orders' => count(array_unique(array_column($unresolved, 'preinvoice_order_id'))),
            'warehouse_stock_changed' => false,
            'stock_cache_changed' => false,
            'temporary_reservations_changed' => false,
            'preinvoices_changed' => false,
        ];

        return ['summary' => $summary, 'changes' => $changes, 'changes_by_variant' => collect($changes)->keyBy('variant_id')->all(), 'excluded-active-reservations' => $excludedActive, 'unresolved-cancelled-finance' => $unresolved];
    }

    private function protectedDemand(): array
    {
        return DB::table('preinvoice_order_items as i')
            ->join('preinvoice_orders as o', 'o.id', '=', 'i.preinvoice_order_id')
            ->where('i.quantity', '>', 0)->whereNotNull('i.variant_id')->whereIn('o.status', self::PROTECTED_STATUSES)->whereNull('o.stock_released_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as inv')->whereColumn('inv.preinvoice_order_id', 'o.id'))
            ->groupBy('i.variant_id')->selectRaw('i.variant_id, SUM(i.quantity) qty')->pluck('qty', 'variant_id')->map(fn ($v) => (int) $v)->all();
    }

    private function activeTemporary(): array
    {
        return DB::table('preinvoice_draft_reservations')->whereIn('reservation_scope', self::TEMPORARY_SCOPES)->whereNull('preinvoice_order_id')->whereNull('converted_at')->whereNull('released_at')->where(fn ($q) => $q->whereNull('release_reason')->orWhere('release_reason', ''))->where('quantity', '>', 0)->groupBy('variant_id')->selectRaw('variant_id, SUM(quantity) qty')->pluck('qty', 'variant_id')->map(fn ($v) => (int) $v)->all();
    }

    private function excludedVariantIds(): array
    {
        $ids = DB::table('preinvoice_order_items as i')->join('preinvoice_orders as o', 'o.id', '=', 'i.preinvoice_order_id')->where('o.status', 'cancelled_by_finance')->whereNull('o.stock_released_at')->whereNotNull('i.variant_id')->pluck('i.variant_id')->map(fn ($id) => (int) $id)->all();
        foreach ((array) $this->option('exclude-order') as $orderId) {
            if (ctype_digit((string) $orderId)) {
                $ids = array_merge($ids, DB::table('preinvoice_order_items')->where('preinvoice_order_id', (int) $orderId)->whereNotNull('variant_id')->pluck('variant_id')->map(fn ($id) => (int) $id)->all());
            }
        }
        return array_fill_keys(array_unique($ids), true);
    }

    private function excludedActiveReservations(array $excluded): array
    {
        if ($excluded === []) return [];
        return DB::table('preinvoice_draft_reservations')->whereIn('variant_id', array_keys($excluded))->where('quantity', '>', 0)->whereNull('released_at')->get(['id','preinvoice_order_id','product_id','variant_id','quantity','reservation_scope','converted_at','released_at','release_reason'])->map(fn ($r) => (array) $r)->all();
    }

    private function unresolvedCancelledFinanceRows(): array
    {
        return DB::table('preinvoice_order_items as i')->join('preinvoice_orders as o', 'o.id', '=', 'i.preinvoice_order_id')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')->where('o.status', 'cancelled_by_finance')->whereNull('o.stock_released_at')->selectRaw('o.id as preinvoice_order_id, o.status, o.stock_released_at, i.product_id, i.variant_id, v.variant_name, v.variant_code, i.quantity')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function applyReport(array $report): void
    {
        foreach ($report['changes'] as $row) {
            DB::table('product_variants')->where('id', $row['variant_id'])->lockForUpdate()->update(['reserved' => $row['expected_reserved'], 'updated_at' => now()]);
        }
        foreach (array_unique(array_column($report['changes'], 'product_id')) as $productId) {
            $sum = (int) DB::table('product_variants')->where('product_id', $productId)->sum('reserved');
            DB::table('products')->where('id', $productId)->lockForUpdate()->update(['reserved' => $sum, 'updated_at' => now()]);
        }
    }

    private function lockInScopeRows(bool $apply): void
    {
        if (! $apply) return;
        DB::table('product_variants')->lockForUpdate()->get(['id']);
        DB::table('products')->lockForUpdate()->get(['id']);
    }

    private function writeReports(array $report): array
    {
        $base = trim((string) $this->option('output'), '/');
        $paths = [];
        Storage::disk('local')->put("$base/summary.json", json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); $paths[] = "$base/summary.json";
        Storage::disk('local')->put("$base/reserved-cache-changes.csv", $this->csv($report['changes'], ['product_id','product_name','variant_id','variant_name','variant_code','reserved_before','protected_document_demand','active_temporary_quantity','expected_reserved','reserved_after'])); $paths[] = "$base/reserved-cache-changes.csv";
        Storage::disk('local')->put("$base/excluded-active-reservations.csv", $this->csv($report['excluded-active-reservations'], ['id','preinvoice_order_id','product_id','variant_id','quantity','reservation_scope','converted_at','released_at','release_reason'])); $paths[] = "$base/excluded-active-reservations.csv";
        Storage::disk('local')->put("$base/unresolved-cancelled-finance.csv", $this->csv($report['unresolved-cancelled-finance'], ['preinvoice_order_id','status','stock_released_at','product_id','variant_id','variant_name','variant_code','quantity'])); $paths[] = "$base/unresolved-cancelled-finance.csv";
        return $paths;
    }

    private function csv(array $rows, array $head): string { $h=fopen('php://temp','r+'); fputcsv($h,$head); foreach($rows as $r) fputcsv($h, array_map(fn($k)=>$r[$k] ?? '', $head)); rewind($h); return stream_get_contents($h); }
    private function installWriteQueryGuard(): void { self::$writeGuardEnabled=true; $c=DB::connection(); self::$guardedConnections ??= new \WeakMap(); if(isset(self::$guardedConnections[$c])) return; $c->beforeExecuting(function(string $q,array $b,Connection $c): void { if(self::$writeGuardEnabled && preg_match('/^('.self::WRITE_VERBS.')\b/i', ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s',' ',$q) ?? $q))) throw new \RuntimeException('Unsafe write query blocked before execution during reserved cache dry run.'); }); self::$guardedConnections[$c]=true; }
    private function disableWriteQueryGuard(): void { self::$writeGuardEnabled=false; }
}
