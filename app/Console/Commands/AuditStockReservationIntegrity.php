<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AuditStockReservationIntegrity extends Command
{
    protected $signature = 'inventory:audit-stock-reservation-integrity {--format=csv} {--output=} {--product=} {--variant=} {--summary}';
    protected $description = 'Read-only audit for central stock caches and draft reservation integrity.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';
    private const RESERVATION_SCOPES = ['temporary_online', 'temporary_in_person', 'official'];
    private const ACTIVE_PREINVOICE_STATUSES = [
        'reserved_waiting_warehouse',
        'warehouse_reviewing',
        'warehouse_approved_waiting_finance',
        'finance_reviewing',
        'returned_to_warehouse',
    ];
    private const CSV_HEAD = [
        'product_id',
        'product_name',
        'variant_id',
        'variant_name',
        'variant_code',
        'is_active',
        'sales_enabled',
        'sell_price',
        'cached_available_stock',
        'central_available_stock',
        'non_central_stock',
        'cached_reserved',
        'active_reserved_quantity',
        'temporary_online_reserved',
        'temporary_in_person_reserved',
        'official_reserved',
        'total_controlled_stock',
        'difference',
        'reservation_ids',
        'preinvoice_order_ids',
        'anomaly_code',
        'severity',
        'recommended_action',
    ];

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
            $centralWarehouseId = $this->centralWarehouseId();
            if ($centralWarehouseId === null) {
                return self::FAILURE;
            }

            $rows = $this->buildRows($centralWarehouseId);
            $summary = $this->buildSummary($rows);
            $paths = $this->writeReports($rows, $summary, $format);

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

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if (self::$writeGuardEnabled && $this->isWriteStatement($query)) {
                throw new \RuntimeException('Unsafe write query blocked before execution during stock reservation audit.');
            }
        });

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

    private function centralWarehouseId(): ?int
    {
        if (! Schema::hasTable('warehouses')) {
            $this->error('warehouses table is missing.');
            return null;
        }

        $ids = DB::table('warehouses')->where('type', 'central')->pluck('id')->all();
        if (count($ids) !== 1) {
            $this->error(count($ids) === 0 ? 'Central warehouse was not found.' : 'More than one central warehouse was found.');
            return null;
        }

        return (int) $ids[0];
    }

    private function buildRows(int $centralWarehouseId): array
    {
        if (! Schema::hasTable('product_variants')) {
            return $this->categorizedRows([]);
        }

        $central = $this->stockByVariant($centralWarehouseId, true);
        $nonCentral = $this->stockByVariant($centralWarehouseId, false);
        $reserved = $this->activeReservations();
        $variants = $this->variants();
        $rows = [];

        foreach ($variants as $variantId => $variant) {
            $row = $this->baseRow($variant, $central[$variantId] ?? 0, $nonCentral[$variantId] ?? 0, $reserved[$variantId] ?? $this->emptyReservation());

            if ($row['cached_available_stock'] !== $row['central_available_stock']) {
                $rows['central-stock-cache-desync'][] = $this->anomaly($row, 'S01', 'High', 'Set product_variants.stock to central warehouse available stock after review.', $row['cached_available_stock'] - $row['central_available_stock']);
            }

            if ($row['cached_reserved'] !== $row['active_reserved_quantity']) {
                $rows['reservation-cache-desync'][] = $this->anomaly($row, 'R01', 'High', 'Set product_variants.reserved to active reservation quantity after review.', $row['cached_reserved'] - $row['active_reserved_quantity']);
            }

            if ($row['is_active'] && $row['sales_enabled'] && $row['sell_price'] <= 0 && $row['central_available_stock'] > 0) {
                $rows['central-stock-zero-prices'][] = $this->anomaly($row, 'P01', 'Critical', 'Price this active sellable variant before selling central stock.', $row['central_available_stock']);
            }

            if ($row['sell_price'] <= 0 && $row['central_available_stock'] <= 0 && $row['non_central_stock'] > 0) {
                $rows['non-central-stock-zero-prices'][] = $this->anomaly($row, 'P02', 'Medium', 'Review non-central stock before transfer or sale because sell_price is zero.', $row['non_central_stock']);
            }

            if ($row['sell_price'] <= 0 && $row['active_reserved_quantity'] > 0) {
                $rows['reservation-cache-desync'][] = $this->anomaly($row, 'P03', 'Critical', 'Review active reservations for a zero-price variant before conversion.', $row['active_reserved_quantity']);
            }
        }

        foreach ($this->invalidReservations() as $reservation) {
            $rows['reservation-cache-desync'][] = $this->reservationAnomaly($reservation, $variants, $central, $nonCentral, $reserved, 'R02', 'Critical', 'Release or correct reservation linked to an invalid product or variant.');
        }

        foreach ($this->staleTemporaryReservations() as $reservation) {
            $rows['stale-temporary-reservations'][] = $this->reservationAnomaly($reservation, $variants, $central, $nonCentral, $reserved, $reservation->reservation_scope === 'temporary_online' ? 'R03' : 'R04', 'Medium', 'Review stale temporary reservation and release it through the existing safe workflow if needed.');
        }

        foreach ($this->invalidOfficialReservations() as $reservation) {
            $rows['invalid-official-reservations'][] = $this->reservationAnomaly($reservation, $variants, $central, $nonCentral, $reserved, 'R05', 'High', 'Review official reservation because its preinvoice no longer requires stock reservation.');
        }

        return $this->categorizedRows($rows);
    }

    private function variants(): array
    {
        return DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->when($this->option('product'), fn ($q, $id) => $q->where('p.id', $id))
            ->when($this->option('variant'), fn ($q, $id) => $q->where('v.id', $id))
            ->select(['v.id', 'v.product_id', 'v.variant_name', 'v.variant_code', 'v.sell_price', 'v.stock', 'v.reserved', 'v.is_active', 'v.sales_enabled', 'p.name as product_name'])
            ->orderBy('v.id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function stockByVariant(int $centralWarehouseId, bool $central): array
    {
        if (! Schema::hasTable('warehouse_stocks')) {
            return [];
        }

        return DB::table('warehouse_stocks as ws')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->whereNotNull('ws.product_variant_id')
            ->where('w.type', $central ? '=' : '!=', 'central')
            ->where($central ? 'ws.warehouse_id' : 'ws.warehouse_id', $central ? '=' : '!=', $centralWarehouseId)
            ->select('ws.product_variant_id', DB::raw('sum(ws.quantity) as quantity'))
            ->groupBy('ws.product_variant_id')
            ->pluck('quantity', 'product_variant_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function activeReservations(): array
    {
        if (! Schema::hasTable('preinvoice_draft_reservations')) {
            return [];
        }

        $rows = DB::table('preinvoice_draft_reservations')
            ->where('quantity', '>', 0)
            ->whereNull('released_at')
            ->whereNull('release_reason')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('preinvoice_orders as consumed_orders')
                    ->whereColumn('consumed_orders.id', 'preinvoice_draft_reservations.preinvoice_order_id')
                    ->where('preinvoice_draft_reservations.reservation_scope', 'official')
                    ->where(function ($order): void {
                        $order->where('consumed_orders.status', 'converted_to_invoice')
                            ->orWhereNotNull('consumed_orders.stock_released_at');
                    });
            })
            ->select('variant_id', 'reservation_scope', DB::raw('sum(quantity) as quantity'), DB::raw('group_concat(id) as reservation_ids'), DB::raw('group_concat(preinvoice_order_id) as preinvoice_order_ids'))
            ->groupBy('variant_id', 'reservation_scope')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if (! $row->variant_id || ! in_array($row->reservation_scope, self::RESERVATION_SCOPES, true)) {
                continue;
            }
            $variantId = (int) $row->variant_id;
            $out[$variantId] ??= $this->emptyReservation();
            $scopeKey = $row->reservation_scope.'_reserved';
            $out[$variantId][$scopeKey] += (int) $row->quantity;
            $out[$variantId]['active_reserved_quantity'] += (int) $row->quantity;
            $out[$variantId]['reservation_ids'] = $this->appendCsv($out[$variantId]['reservation_ids'], (string) $row->reservation_ids);
            $out[$variantId]['preinvoice_order_ids'] = $this->appendCsv($out[$variantId]['preinvoice_order_ids'], (string) $row->preinvoice_order_ids);
        }

        return $out;
    }

    private function invalidReservations(): array
    {
        if (! Schema::hasTable('preinvoice_draft_reservations')) {
            return [];
        }

        return DB::table('preinvoice_draft_reservations as r')
            ->leftJoin('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'r.variant_id')
            ->where('r.quantity', '>', 0)
            ->whereNull('r.released_at')
            ->whereNull('r.release_reason')
            ->where(fn ($q) => $q->whereNull('p.id')->orWhereNull('v.id')->orWhereColumn('v.product_id', '!=', 'r.product_id'))
            ->select(['r.*'])
            ->get()
            ->all();
    }

    private function staleTemporaryReservations(): array
    {
        if (! Schema::hasTable('preinvoice_draft_reservations')) {
            return [];
        }

        return DB::table('preinvoice_draft_reservations as r')
            ->where('r.quantity', '>', 0)
            ->whereNull('r.released_at')
            ->whereNull('r.release_reason')
            ->whereIn('r.reservation_scope', ['temporary_online', 'temporary_in_person'])
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('r.reservation_scope', 'temporary_online')->where('r.expires_at', '<=', now()))
                    ->orWhere(fn ($q) => $q->where('r.reservation_scope', 'temporary_in_person')->where(fn ($qq) => $qq->whereNull('r.last_seen_at')->orWhere('r.last_seen_at', '<=', now()->subMinutes(15))));
            })
            ->select(['r.*'])
            ->get()
            ->all();
    }

    private function invalidOfficialReservations(): array
    {
        if (! Schema::hasTable('preinvoice_draft_reservations') || ! Schema::hasTable('preinvoice_orders')) {
            return [];
        }

        return DB::table('preinvoice_draft_reservations as r')
            ->leftJoin('preinvoice_orders as o', 'o.id', '=', 'r.preinvoice_order_id')
            ->where('r.quantity', '>', 0)
            ->whereNull('r.released_at')
            ->whereNull('r.release_reason')
            ->where('r.reservation_scope', 'official')
            ->where(fn ($q) => $q->whereNull('o.id')->orWhereNotIn('o.status', self::ACTIVE_PREINVOICE_STATUSES)->orWhereNotNull('o.stock_released_at')->orWhere('o.status', 'converted_to_invoice'))
            ->select(['r.*'])
            ->get()
            ->all();
    }

    private function baseRow(object $variant, int $centralStock, int $nonCentralStock, array $reservation): array
    {
        return [
            'product_id' => (int) $variant->product_id,
            'product_name' => (string) $variant->product_name,
            'variant_id' => (int) $variant->id,
            'variant_name' => (string) ($variant->variant_name ?? ''),
            'variant_code' => (string) ($variant->variant_code ?? ''),
            'is_active' => (bool) $variant->is_active,
            'sales_enabled' => (bool) $variant->sales_enabled,
            'sell_price' => (int) ($variant->sell_price ?? 0),
            'cached_available_stock' => (int) ($variant->stock ?? 0),
            'central_available_stock' => $centralStock,
            'non_central_stock' => $nonCentralStock,
            'cached_reserved' => (int) ($variant->reserved ?? 0),
            'active_reserved_quantity' => $reservation['active_reserved_quantity'],
            'temporary_online_reserved' => $reservation['temporary_online_reserved'],
            'temporary_in_person_reserved' => $reservation['temporary_in_person_reserved'],
            'official_reserved' => $reservation['official_reserved'],
            'total_controlled_stock' => $centralStock + $reservation['active_reserved_quantity'],
            'difference' => 0,
            'reservation_ids' => $reservation['reservation_ids'],
            'preinvoice_order_ids' => $reservation['preinvoice_order_ids'],
            'anomaly_code' => '',
            'severity' => '',
            'recommended_action' => '',
        ];
    }

    private function reservationAnomaly(object $reservation, array $variants, array $central, array $nonCentral, array $reserved, string $code, string $severity, string $action): array
    {
        $variant = $variants[$reservation->variant_id] ?? (object) ['id' => $reservation->variant_id, 'product_id' => $reservation->product_id, 'product_name' => '', 'variant_name' => '', 'variant_code' => '', 'sell_price' => 0, 'stock' => 0, 'reserved' => 0, 'is_active' => false, 'sales_enabled' => false];
        $row = $this->baseRow($variant, $central[$reservation->variant_id] ?? 0, $nonCentral[$reservation->variant_id] ?? 0, $reserved[$reservation->variant_id] ?? $this->emptyReservation());
        $row['reservation_ids'] = (string) $reservation->id;
        $row['preinvoice_order_ids'] = (string) ($reservation->preinvoice_order_id ?? '');

        return $this->anomaly($row, $code, $severity, $action, (int) ($reservation->quantity ?? 0));
    }

    private function anomaly(array $row, string $code, string $severity, string $action, int $difference): array
    {
        $row['anomaly_code'] = $code;
        $row['severity'] = $severity;
        $row['recommended_action'] = $action;
        $row['difference'] = $difference;

        return $row;
    }

    private function emptyReservation(): array
    {
        return ['active_reserved_quantity' => 0, 'temporary_online_reserved' => 0, 'temporary_in_person_reserved' => 0, 'official_reserved' => 0, 'reservation_ids' => '', 'preinvoice_order_ids' => ''];
    }

    private function appendCsv(string $left, string $right): string
    {
        $values = array_filter(array_merge(explode(',', $left), explode(',', $right)), fn ($value) => $value !== '');

        return implode(',', array_values(array_unique($values)));
    }

    private function categorizedRows(array $rows): array
    {
        foreach (['central-stock-cache-desync', 'reservation-cache-desync', 'stale-temporary-reservations', 'invalid-official-reservations', 'central-stock-zero-prices', 'non-central-stock-zero-prices'] as $key) {
            $rows[$key] ??= [];
        }

        return $rows;
    }

    private function buildSummary(array $rows): array
    {
        $flat = array_merge(...array_values($rows));

        return [
            'central_stock_cache_desync' => count($rows['central-stock-cache-desync']),
            'reservation_cache_desync' => count(array_filter($rows['reservation-cache-desync'], fn ($row) => $row['anomaly_code'] === 'R01')),
            'invalid_reservations' => count(array_filter($rows['reservation-cache-desync'], fn ($row) => $row['anomaly_code'] === 'R02')),
            'stale_temporary_reservations' => count($rows['stale-temporary-reservations']),
            'invalid_official_reservations' => count($rows['invalid-official-reservations']),
            'central_stock_zero_prices' => count($rows['central-stock-zero-prices']),
            'non_central_stock_zero_prices' => count($rows['non-central-stock-zero-prices']),
            'zero_price_active_reservations' => count(array_filter($rows['reservation-cache-desync'], fn ($row) => $row['anomaly_code'] === 'P03')),
            'total_anomalies' => count($flat),
            'by_code' => array_count_values(array_column($flat, 'anomaly_code')),
            'data_changed' => false,
        ];
    }

    private function writeReports(array $rows, array $summary, string $format): array
    {
        $dir = $this->option('output') ?: 'reports/stock-reservation-integrity';
        Storage::disk('local')->makeDirectory($dir);
        $paths = ['summary' => "$dir/summary.json"];
        Storage::disk('local')->put($paths['summary'], json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        foreach ($rows as $name => $reportRows) {
            $paths[$name] = "$dir/$name.$format";
            if ($format === 'json') {
                Storage::disk('local')->put($paths[$name], json_encode($reportRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->putCsv($paths[$name], $reportRows);
            }
        }

        return array_map(fn ($path) => Storage::disk('local')->path($path), $paths);
    }

    private function putCsv(string $path, array $rows): void
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::CSV_HEAD);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($head) => $row[$head] ?? '', self::CSV_HEAD));
        }
        rewind($fh);
        Storage::disk('local')->put($path, stream_get_contents($fh));
    }
}
