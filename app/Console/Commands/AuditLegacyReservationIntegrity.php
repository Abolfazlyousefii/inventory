<?php

namespace App\Console\Commands;

use App\Services\LegacyReservationCleanupService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditLegacyReservationIntegrity extends Command
{
    protected $signature = 'inventory:audit-legacy-reservation-integrity {--format=csv : csv or json} {--output=} {--order=} {--variant=} {--summary} {--stale-hours=72 : Minimum age since reservation activity}';
    protected $description = 'Read-only audit for legacy reservation rows and reserved cache integrity.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private const SCOPES = ['temporary_online', 'temporary_in_person', 'official'];
    private const PROTECTED_STATUSES = ['reserved_waiting_warehouse', 'warehouse_reviewing', 'warehouse_approved_waiting_finance', 'finance_reviewing', 'pending_finance', 'returned_to_warehouse'];
    private const CANCELLED_STATUSES = ['cancelled', 'reservation_expired', 'returned_to_sales', 'cancelled_by_warehouse', 'cancelled_by_finance', 'expired'];
    private const LEGACY_HEAD = ['reservation_id','token_prefix','user_id','preinvoice_order_id','order_uuid','order_status','invoice_id','stock_released_at','product_id','product_name','variant_id','variant_name','variant_code','quantity','reservation_scope','converted_at','released_at','release_reason','expires_at','last_seen_at','created_at','classification_code','severity','recommended_action'];
    private const VARIANT_HEAD = ['product_id','product_name','variant_id','variant_name','variant_code','cached_available_stock','central_available_stock','cached_reserved','protected_document_demand','active_temporary_quantity','recognized_official_quantity','legacy_quantity','expected_reserved','reservation_cache_difference','protected_order_ids','official_reservation_ids','legacy_reservation_ids','classification_code','severity','recommended_action'];
    private const MISSING_HEAD = ['preinvoice_order_id','order_uuid','order_status','product_id','product_name','variant_id','variant_name','required_quantity','official_quantity','legacy_quantity','covered_quantity','missing_quantity','cached_reserved','central_available_stock','classification_code','severity'];
    private const CLEANUP_HEAD = ['reservation_id','product_id','product_name','variant_id','variant_name','quantity','token','age_hours','preinvoice_order_id','preinvoice_status','legacy_reason'];

    private static bool $writeGuardEnabled = false;
    private static ?\WeakMap $guardedConnections = null;

    private LegacyReservationCleanupService $cleanup;

    public function handle(LegacyReservationCleanupService $cleanup): int
    {
        $this->cleanup = $cleanup;
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('--format must be csv or json.');
            return self::FAILURE;
        }

        $this->installWriteQueryGuard();
        $started = now()->toISOString();
        try {
            $report = DB::transaction(fn () => $this->buildReport($started), 1);
        } finally {
            $this->disableWriteQueryGuard();
        }

        $paths = $this->writeReports($report, $format);
        $this->line(json_encode(['summary' => $report['summary'], 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (! $this->option('summary') && $report['cleanup-candidates'] !== []) {
            $this->table(self::CLEANUP_HEAD, array_map(
                fn (array $row): array => array_map(fn (string $column) => $row[$column] ?? '', self::CLEANUP_HEAD),
                $report['cleanup-candidates'],
            ));
        }

        return self::SUCCESS;
    }

    private function buildReport(string $started): array
    {
        $orderFilter = $this->intOption('order');
        $variantFilter = $this->intOption('variant');
        $now = now();
        $recentCutoff = $now->copy()->subMinutes(15);

        $products = DB::table('products')->pluck('name', 'id')->all();
        $variants = DB::table('product_variants')->get(['id','product_id','variant_name','variant_code','stock','reserved'])->keyBy('id');
        $central = DB::table('warehouse_stocks as ws')->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')->where('w.type', 'central')->groupBy('ws.product_variant_id')->selectRaw('ws.product_variant_id as variant_id, SUM(ws.quantity) as qty')->pluck('qty', 'variant_id')->all();
        $invoiceByOrder = DB::table('invoices')->select('id','preinvoice_order_id')->get()->keyBy('preinvoice_order_id');
        $orders = DB::table('preinvoice_orders')->get(['id','uuid','status','stock_released_at'])->keyBy('id');

        $items = DB::table('preinvoice_order_items as i')
            ->join('preinvoice_orders as o', 'o.id', '=', 'i.preinvoice_order_id')
            ->where('i.quantity', '>', 0)
            ->when($orderFilter, fn ($q) => $q->where('i.preinvoice_order_id', $orderFilter))
            ->when($variantFilter, fn ($q) => $q->where('i.variant_id', $variantFilter))
            ->whereIn('o.status', self::PROTECTED_STATUSES)
            ->whereNull('o.stock_released_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as inv')->whereColumn('inv.preinvoice_order_id', 'o.id'))
            ->groupBy('i.preinvoice_order_id','o.uuid','o.status','i.product_id','i.variant_id')
            ->selectRaw('i.preinvoice_order_id, o.uuid as order_uuid, o.status as order_status, i.product_id, i.variant_id, SUM(i.quantity) as required_quantity')
            ->get();

        $itemDemand = [];
        foreach ($items as $item) {
            $key = $this->lineKey($item->preinvoice_order_id, $item->product_id, $item->variant_id);
            $itemDemand[$key] = $item;
        }

        $reservations = DB::table('preinvoice_draft_reservations')
            ->when($orderFilter, fn ($q) => $q->where('preinvoice_order_id', $orderFilter))
            ->when($variantFilter, fn ($q) => $q->where('variant_id', $variantFilter))
            ->get(['id','token','user_id','preinvoice_order_id','product_id','variant_id','quantity','reservation_scope','converted_at','released_at','release_reason','expires_at','last_seen_at','created_at']);

        $active = $reservations->filter(fn ($r) => (int) $r->quantity > 0 && $r->released_at === null && ($r->release_reason === null || $r->release_reason === '') && in_array((string) $r->reservation_scope, self::SCOPES, true));
        $legacy = $reservations->filter(fn ($r) => ! in_array((string) $r->reservation_scope, self::SCOPES, true));
        $byLine = [];
        foreach ($reservations as $r) {
            $k = $this->lineKey($r->preinvoice_order_id, $r->product_id, $r->variant_id);
            $byLine[$k]['legacy_qty'] = ($byLine[$k]['legacy_qty'] ?? 0) + (! in_array((string) $r->reservation_scope, self::SCOPES, true) && $r->released_at === null && ($r->release_reason === null || $r->release_reason === '') ? (int) $r->quantity : 0);
            $byLine[$k]['official_qty'] = ($byLine[$k]['official_qty'] ?? 0) + ((string) $r->reservation_scope === 'official' && $r->released_at === null && ($r->release_reason === null || $r->release_reason === '') ? (int) $r->quantity : 0);
            $byLine[$k]['legacy_ids'][] = ! in_array((string) $r->reservation_scope, self::SCOPES, true) ? (int) $r->id : null;
            $byLine[$k]['official_ids'][] = (string) $r->reservation_scope === 'official' ? (int) $r->id : null;
        }

        $legacyRows = $actions = [];
        foreach ($legacy as $r) {
            $order = $r->preinvoice_order_id ? ($orders[$r->preinvoice_order_id] ?? null) : null;
            $invoice = $r->preinvoice_order_id ? ($invoiceByOrder[$r->preinvoice_order_id] ?? null) : null;
            $variant = $variants[$r->variant_id] ?? null;
            $invalid = ! isset($products[$r->product_id]) || ! $variant || (int) $variant->product_id !== (int) $r->product_id;
            $key = $this->lineKey($r->preinvoice_order_id, $r->product_id, $r->variant_id);
            $demand = $itemDemand[$key]->required_quantity ?? null;
            $covered = ($byLine[$key]['legacy_qty'] ?? 0) + ($byLine[$key]['official_qty'] ?? 0);
            [$code, $severity, $action] = $this->classifyLegacy($r, $order, $invoice, $invalid, $demand, $covered, $byLine[$key]['official_qty'] ?? 0, $recentCutoff, $now);
            $row = $this->legacyRow($r, $order, $invoice, $products, $variant, $code, $severity, $action);
            $legacyRows[] = $row;
            $actions[] = $this->actionRow($code, $action, $row);
        }

        $missingRows = [];
        foreach ($items as $item) {
            $key = $this->lineKey($item->preinvoice_order_id, $item->product_id, $item->variant_id);
            $official = (int) ($byLine[$key]['official_qty'] ?? 0); $leg = (int) ($byLine[$key]['legacy_qty'] ?? 0); $req = (int) $item->required_quantity;
            if ($official + $leg < $req) $missingRows[] = $this->missingRow($item, $products, $variants[$item->variant_id] ?? null, $official, $leg, $central);
        }

        $variantRows = $this->variantRows($variants, $products, $central, $items, $active, $legacy, $byLine, $variantFilter);
        foreach ($variantRows as $vr) if (in_array($vr['classification_code'], ['L12_CACHE_OVER_RESERVED','L13_CACHE_UNDER_RESERVED'], true)) $actions[] = $this->actionRow($vr['classification_code'], $vr['recommended_action'], $vr);

        $cleanupRows = $this->cleanup->reportRows(
            max(1, (int) $this->option('stale-hours')),
            $now,
            $orderFilter,
            $variantFilter,
        )->all();
        $summary = $this->summary($legacyRows, $missingRows, $variantRows, $started) + [
            'cleanup_legacy_rows_total' => count($cleanupRows),
            'cleanup_legacy_quantity_total' => array_sum(array_column($cleanupRows, 'quantity')),
            'cleanup_products_total' => count(array_unique(array_column($cleanupRows, 'product_id'))),
            'cleanup_variants_total' => count(array_unique(array_column($cleanupRows, 'variant_id'))),
            'cleanup_stale_hours' => max(1, (int) $this->option('stale-hours')),
        ];
        return $this->bucket($legacyRows, $missingRows, $variantRows, $actions, $summary, $cleanupRows);
    }

    private function classifyLegacy(object $r, ?object $order, ?object $invoice, bool $invalid, ?int $demand, int $covered, int $official, $recentCutoff, $now): array
    {
        if ($invalid) return ['L09_INVALID_PRODUCT_OR_VARIANT','Critical','MANUAL_INVESTIGATION'];
        if ($r->preinvoice_order_id && ! $order) return ['L10_MISSING_ORDER','Critical','MANUAL_INVESTIGATION'];
        if ($invoice || ($order && (string) $order->status === 'converted_to_invoice')) return ['L05_INVOICED_OR_CONVERTED','High','REVIEW_ONLY'];
        if ($order && (in_array((string) $order->status, self::CANCELLED_STATUSES, true) || $order->stock_released_at !== null)) return ['L06_CANCELLED_EXPIRED_OR_RELEASED','High','REVIEW_ONLY'];
        if (! $r->preinvoice_order_id && ! $r->converted_at && ! $r->released_at && (($r->last_seen_at && $recentCutoff->lte($r->last_seen_at)) || ($r->expires_at && $now->lt($r->expires_at)))) return ['L07_UNLINKED_RECENT','Low','PROTECT_ACTIVE'];
        if (! $r->preinvoice_order_id && ! $r->converted_at) return ['L08_UNLINKED_STALE','Medium','CANDIDATE_MARK_RELEASED'];
        if ($official > 0) return ['L04_DUPLICATE_LEGACY_AND_OFFICIAL','High','MANUAL_INVESTIGATION'];
        if ($demand !== null && in_array((string) $order->status, self::PROTECTED_STATUSES, true) && $order->stock_released_at === null) {
            if ($covered === (int) $demand) return ['L01_ACTIVE_DOCUMENT_EXACT','Low','CANDIDATE_SCOPE_TO_OFFICIAL'];
            return [$covered < (int) $demand ? 'L02_ACTIVE_DOCUMENT_SHORT' : 'L03_ACTIVE_DOCUMENT_EXCESS', 'High', 'MANUAL_INVESTIGATION'];
        }
        return ['L08_UNLINKED_STALE','Medium','REVIEW_ONLY'];
    }

    private function variantRows($variants, $products, $central, $items, $active, $legacy, array $byLine, ?int $variantFilter): array
    {
        $protected = []; $orders = [];
        foreach ($items as $i) { $protected[$i->variant_id] = ($protected[$i->variant_id] ?? 0) + (int) $i->required_quantity; $orders[$i->variant_id][] = (int) $i->preinvoice_order_id; }
        $temp = $active->filter(fn ($r) => in_array((string) $r->reservation_scope, ['temporary_online','temporary_in_person'], true) && $r->preinvoice_order_id === null && $r->converted_at === null)->groupBy('variant_id')->map->sum('quantity')->all();
        $official = $active->filter(fn ($r) => (string) $r->reservation_scope === 'official')->groupBy('variant_id')->map->sum('quantity')->all();
        $leg = $legacy->groupBy('variant_id')->map->sum('quantity')->all();
        $officialIds = $active->filter(fn ($r) => (string) $r->reservation_scope === 'official')->groupBy('variant_id')->map(fn ($g) => $g->pluck('id')->implode('|'))->all();
        $legacyIds = $legacy->groupBy('variant_id')->map(fn ($g) => $g->pluck('id')->implode('|'))->all();
        $ids = collect(array_keys($variants->all()))->merge(array_keys($protected))->merge(array_keys($temp))->merge(array_keys($leg))->unique();
        if ($variantFilter) $ids = collect([$variantFilter]);
        return $ids->map(function ($id) use ($variants,$products,$central,$protected,$temp,$official,$leg,$orders,$officialIds,$legacyIds) { $v = $variants[$id] ?? null; $expected = (int)($protected[$id] ?? 0) + (int)($temp[$id] ?? 0); $cached = (int)($v->reserved ?? 0); $diff = $cached - $expected; $code = $diff > 0 ? 'L12_CACHE_OVER_RESERVED' : ($diff < 0 ? 'L13_CACHE_UNDER_RESERVED' : 'L14_CACHE_MATCHED'); return ['product_id'=>(int)($v->product_id ?? 0),'product_name'=>(string)($products[$v->product_id ?? 0] ?? ''),'variant_id'=>(int)$id,'variant_name'=>(string)($v->variant_name ?? ''),'variant_code'=>(string)($v->variant_code ?? ''),'cached_available_stock'=>(int)($v->stock ?? 0),'central_available_stock'=>(int)($central[$id] ?? 0),'cached_reserved'=>$cached,'protected_document_demand'=>(int)($protected[$id] ?? 0),'active_temporary_quantity'=>(int)($temp[$id] ?? 0),'recognized_official_quantity'=>(int)($official[$id] ?? 0),'legacy_quantity'=>(int)($leg[$id] ?? 0),'expected_reserved'=>$expected,'reservation_cache_difference'=>$diff,'protected_order_ids'=>implode('|', array_unique($orders[$id] ?? [])),'official_reservation_ids'=>(string)($officialIds[$id] ?? ''),'legacy_reservation_ids'=>(string)($legacyIds[$id] ?? ''),'classification_code'=>$code,'severity'=>$diff === 0 ? 'Info' : 'High','recommended_action'=>$diff > 0 ? 'CANDIDATE_CACHE_DECREASE' : ($diff < 0 ? 'CANDIDATE_CACHE_INCREASE' : 'REVIEW_ONLY')]; })->values()->all();
    }

    private function legacyRow($r, $order, $invoice, $products, $variant, $code, $severity, $action): array
    { return ['reservation_id'=>(int)$r->id,'token_prefix'=>substr((string)($r->token ?? ''),0,8),'user_id'=>$r->user_id,'preinvoice_order_id'=>$r->preinvoice_order_id,'order_uuid'=>$order->uuid ?? null,'order_status'=>$order->status ?? null,'invoice_id'=>$invoice->id ?? null,'stock_released_at'=>$order->stock_released_at ?? null,'product_id'=>(int)$r->product_id,'product_name'=>(string)($products[$r->product_id] ?? ''),'variant_id'=>$r->variant_id,'variant_name'=>$variant->variant_name ?? null,'variant_code'=>$variant->variant_code ?? null,'quantity'=>(int)$r->quantity,'reservation_scope'=>$r->reservation_scope,'converted_at'=>$r->converted_at,'released_at'=>$r->released_at,'release_reason'=>$r->release_reason,'expires_at'=>$r->expires_at,'last_seen_at'=>$r->last_seen_at,'created_at'=>$r->created_at,'classification_code'=>$code,'severity'=>$severity,'recommended_action'=>$action]; }
    private function missingRow($item, $products, $variant, int $official, int $legacy, $central): array
    { $req=(int)$item->required_quantity; $covered=$official+$legacy; return ['preinvoice_order_id'=>(int)$item->preinvoice_order_id,'order_uuid'=>$item->order_uuid,'order_status'=>$item->order_status,'product_id'=>(int)$item->product_id,'product_name'=>(string)($products[$item->product_id] ?? ''),'variant_id'=>(int)$item->variant_id,'variant_name'=>$variant->variant_name ?? null,'required_quantity'=>$req,'official_quantity'=>$official,'legacy_quantity'=>$legacy,'covered_quantity'=>$covered,'missing_quantity'=>$req-$covered,'cached_reserved'=>(int)($variant->reserved ?? 0),'central_available_stock'=>(int)($central[$item->variant_id] ?? 0),'classification_code'=>'L11_PROTECTED_DEMAND_WITHOUT_RESERVATION_ROW','severity'=>'High']; }
    private function summary(array $legacyRows, array $missingRows, array $variantRows, string $started): array
    { $c=fn($code)=>count(array_filter($legacyRows, fn($r)=>$r['classification_code']===$code)); return ['audit_started_at'=>$started,'audit_finished_at'=>now()->toISOString(),'legacy_rows_total'=>count($legacyRows),'legacy_quantity_total'=>array_sum(array_column($legacyRows,'quantity')),'active_document_exact'=>$c('L01_ACTIVE_DOCUMENT_EXACT'),'active_document_short'=>$c('L02_ACTIVE_DOCUMENT_SHORT'),'active_document_excess'=>$c('L03_ACTIVE_DOCUMENT_EXCESS'),'duplicate_legacy_and_official'=>$c('L04_DUPLICATE_LEGACY_AND_OFFICIAL'),'invoiced_or_converted'=>$c('L05_INVOICED_OR_CONVERTED'),'cancelled_expired_or_released'=>$c('L06_CANCELLED_EXPIRED_OR_RELEASED'),'unlinked_recent'=>$c('L07_UNLINKED_RECENT'),'unlinked_stale'=>$c('L08_UNLINKED_STALE'),'invalid_product_or_variant'=>$c('L09_INVALID_PRODUCT_OR_VARIANT'),'missing_order'=>$c('L10_MISSING_ORDER'),'protected_demand_without_reservation'=>count($missingRows),'cache_over_reserved_variants'=>count(array_filter($variantRows, fn($r)=>$r['classification_code']==='L12_CACHE_OVER_RESERVED')),'cache_under_reserved_variants'=>count(array_filter($variantRows, fn($r)=>$r['classification_code']==='L13_CACHE_UNDER_RESERVED')),'cache_matched_variants'=>count(array_filter($variantRows, fn($r)=>$r['classification_code']==='L14_CACHE_MATCHED')),'central_stock_cache_desync'=>0,'data_changed'=>false,'stock_changed'=>false,'reserved_cache_changed'=>false,'preinvoice_changed'=>false]; }
    private function bucket($legacyRows,$missingRows,$variantRows,$actions,$summary,$cleanupRows): array
    { return ['summary'=>$summary,'cleanup-candidates'=>$cleanupRows,'legacy-reservation-rows'=>$legacyRows,'active-document-exact'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L01_ACTIVE_DOCUMENT_EXACT')),'active-document-mismatch'=>array_values(array_filter($legacyRows,fn($r)=>in_array($r['classification_code'],['L02_ACTIVE_DOCUMENT_SHORT','L03_ACTIVE_DOCUMENT_EXCESS'],true))),'duplicate-legacy-and-official'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L04_DUPLICATE_LEGACY_AND_OFFICIAL')),'invoiced-or-converted'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L05_INVOICED_OR_CONVERTED')),'cancelled-expired-or-released'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L06_CANCELLED_EXPIRED_OR_RELEASED')),'unlinked-recent'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L07_UNLINKED_RECENT')),'unlinked-stale'=>array_values(array_filter($legacyRows,fn($r)=>$r['classification_code']==='L08_UNLINKED_STALE')),'invalid-reference'=>array_values(array_filter($legacyRows,fn($r)=>in_array($r['classification_code'],['L09_INVALID_PRODUCT_OR_VARIANT','L10_MISSING_ORDER'],true))),'protected-demand-missing-reservation'=>$missingRows,'variant-reconciliation'=>$variantRows,'proposed-actions'=>$actions]; }
    private function actionRow(string $code, string $action, array $row): array { return ['classification_code'=>$code,'action_type'=>$action,'severity'=>$row['severity'] ?? 'Info','product_id'=>$row['product_id'] ?? null,'variant_id'=>$row['variant_id'] ?? null,'preinvoice_order_id'=>$row['preinvoice_order_id'] ?? null,'reservation_id'=>$row['reservation_id'] ?? null,'note'=>'Proposed only; no automatic action was executed.']; }
    private function writeReports(array $report, string $format): array
    { $base = trim((string)($this->option('output') ?: 'reports/legacy-reservation-integrity'), '/'); $paths=[]; foreach ($report as $name=>$rows) { if ($name === 'summary') { $path="$base/summary.json"; Storage::disk('local')->put($path, json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); $paths[]=$path; continue; } $path="$base/$name.$format"; Storage::disk('local')->put($path, $format === 'json' ? json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) : $this->csv($rows, $this->head($name))); $paths[]=$path; } return $paths; }
    private function csv(array $rows, array $head): string { $h=fopen('php://temp','r+'); fputcsv($h,$head); foreach($rows as $r) fputcsv($h, array_map(fn($k)=>$r[$k] ?? '', $head)); rewind($h); return stream_get_contents($h); }
    private function head(string $name): array { return $name==='cleanup-candidates'?self::CLEANUP_HEAD:($name==='variant-reconciliation'?self::VARIANT_HEAD:($name==='protected-demand-missing-reservation'?self::MISSING_HEAD:($name==='proposed-actions'?['classification_code','action_type','severity','product_id','variant_id','preinvoice_order_id','reservation_id','note']:self::LEGACY_HEAD))); }
    private function lineKey($order,$product,$variant): string { return ((string)$order).'|'.((string)$product).'|'.((string)$variant); }
    private function intOption(string $name): ?int { $v=$this->option($name); return filled($v) && ctype_digit((string)$v) ? (int)$v : null; }
    private function installWriteQueryGuard(): void { self::$writeGuardEnabled=true; $c=DB::connection(); self::$guardedConnections ??= new \WeakMap(); if(isset(self::$guardedConnections[$c])) return; $c->beforeExecuting(function(string $q,array $b,Connection $c): void { if(self::$writeGuardEnabled && preg_match('/^('.self::WRITE_VERBS.')\b/i', ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s',' ',$q) ?? $q))) throw new \RuntimeException('Unsafe write query blocked before execution during legacy reservation integrity audit.'); }); self::$guardedConnections[$c]=true; }
    private function disableWriteQueryGuard(): void { self::$writeGuardEnabled=false; }
}
