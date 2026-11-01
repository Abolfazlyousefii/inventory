<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Services\ReservationClassificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** INV-06: evidence collection only. Never releases or archives a reservation. */
class ReviewHistoricalReservations extends Command
{
    protected $signature = 'inventory:review-historical-reservations
        {--ids= : Optional comma-separated reservation IDs to inspect, including changed/released rows}
        {--summary : Print counts only; do not create local report files}
        {--output=reports/inv06-historical-reservations : Local report directory}';

    protected $description = 'INV-06 read-only, reservation-by-reservation historical evidence and manual decision queue.';

    private static bool $guardEnabled = false;
    private static ?\WeakMap $guardedConnections = null;

    public function handle(ReservationClassificationService $classification): int
    {
        $value = trim((string) $this->option('ids'));
        if ($value !== '' && ! preg_match('/^[1-9][0-9]*(?:\s*,\s*[1-9][0-9]*)*$/D', $value)) {
            $this->error('--ids must contain positive integer IDs separated by commas. No other options perform changes.');
            return self::INVALID;
        }
        $parts = $value === '' ? [] : preg_split('/\s*,\s*/', $value);
        $ids = [];
        foreach ($parts as $part) {
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                $this->error('--ids contains an invalid or out-of-range reservation ID.');
                return self::INVALID;
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 100) {
            $this->error('--ids must contain at most 100 valid positive IDs.');
            return self::INVALID;
        }

        $directory = trim((string) $this->option('output'));
        if (! $this->option('summary') && ($directory === '' || str_starts_with($directory, '/') || preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)|^[A-Za-z]:|[\\\\]~', $directory))) {
            $this->error('--output must be a relative directory inside the local storage disk.');
            return self::INVALID;
        }

        $this->enableReadOnlyGuard();
        try {
            foreach (['id', 'quantity', 'product_id', 'variant_id', 'released_at', 'release_reason', 'preinvoice_order_id'] as $column) {
                if (! Schema::hasColumn('preinvoice_draft_reservations', $column)) {
                    $this->error('Reservation schema is missing column: '.$column.'. Use the migrated database for INV-06 review.');
                    return self::FAILURE;
                }
            }
            $at = now();
            $query = PreinvoiceDraftReservation::query()
                ->with(['order.invoice', 'activeDrafts'])
                ->orderBy('id');
            if ($ids !== []) {
                $query->whereKey($ids);
            } else {
                $query->whereNull('released_at')->whereNull('release_reason');
            }

            $rows = [];
            foreach ($query->get() as $reservation) {
                $classified = $classification->classify($reservation, $at);
                if ($ids === [] && $classified['state'] !== ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS) {
                    continue;
                }
                $rows[] = $this->evidence($reservation, $classified, $at->toISOString());
            }

            $foundIds = array_column($rows, 'reservation_id');
            $missingIds = array_values(array_diff($ids, $foundIds));
            $historical = array_values(array_filter($rows, fn (array $r): bool => $r['classification'] === ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS));
            $summary = [
                'evaluated_at' => $at->toISOString(),
                'selection' => $ids === [] ? 'all_current_historical_ambiguous' : 'explicit_ids',
                'selected_ids' => $ids,
                'not_found_ids' => $missingIds,
                'rows_reported' => count($rows),
                'historical_ambiguous_count' => count($historical),
                'historical_ambiguous_quantity' => array_sum(array_column($historical, 'quantity')),
                'reference_baseline' => ['count' => 4, 'quantity' => 230, 'as_of' => '2026-10-23'],
                'matches_reference_baseline' => $ids === []
                    ? (count($historical) === 4 && array_sum(array_column($historical, 'quantity')) === 230)
                    : null,
                'decisions_pending' => count($rows) + count($missingIds),
                'data_changed' => false,
                'warehouse_stock_changed' => false,
                'reservations_released' => 0,
            ];
        } finally {
            self::$guardEnabled = false;
        }

        if ($this->option('summary')) {
            $this->line(json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $directory = trim($directory, '/');
            $jsonPath = $directory.'/review.json';
            $csvPath = $directory.'/review.csv';
            if (Storage::disk('local')->exists($jsonPath) || Storage::disk('local')->exists($csvPath)) {
                $this->error('Report already exists. Use a different --output directory to preserve the previous evidence.');
                return self::FAILURE;
            }
            $missingCases = array_map(static fn (int $id): array => [
                'reservation_id' => $id,
                'classification' => 'NOT_FOUND',
                'review' => [
                    'status' => 'OPEN_MANUAL_REVIEW',
                    'decision_owner' => null,
                    'note' => 'Not found in the current database; check authoritative historical backups before deciding.',
                ],
            ], $missingIds);
            $jsonWritten = Storage::disk('local')->put($jsonPath, json_encode(['summary' => $summary, 'reservations' => $rows, 'missing_cases' => $missingCases, 'evidence_limitations' => [
                'Activity logs and order-referenced stock movements are bounded excerpts; absence of a record is not proof that no movement or release occurred.',
                'Current stock/reserved caches cannot establish a historical causal effect or authorize a stock change.',
                'No decision is approved by this automated report.',
            ]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (! $jsonWritten || ! Storage::disk('local')->put($csvPath, $this->csv($rows, $missingIds))) {
                $this->error('Could not write the complete private evidence report; check disk permissions and keep the case open.');
                return self::FAILURE;
            }
            $this->line(json_encode(['summary' => $summary, 'files' => ['json' => $jsonPath, 'csv' => $csvPath]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        if ($summary['matches_reference_baseline'] === false) {
            $this->warn('Current findings differ from the 2026-10-23 reference (4 rows, 230 units); do not infer a stock discrepancy or release anything.');
        }
        $this->warn('INV-06 is evidence-only. Every decision remains OPEN until documented approval. No reservation or stock was changed.');
        return self::SUCCESS;
    }

    private function evidence(PreinvoiceDraftReservation $reservation, array $classification, string $evaluatedAt): array
    {
        $order = $reservation->order;
        $invoice = $order?->invoice;
        $productId = (int) $reservation->product_id;
        $variantId = (int) $reservation->variant_id;
        $orderId = $order?->id;
        $invoiceId = $invoice?->id;

        $orderQty = $orderId && Schema::hasTable('preinvoice_order_items')
            ? (int) DB::table('preinvoice_order_items')->where('preinvoice_order_id', $orderId)->where('product_id', $productId)->where('variant_id', $variantId)->sum('quantity')
            : null;
        $invoiceQty = $invoiceId && Schema::hasTable('invoice_items')
            ? (int) DB::table('invoice_items')->where('invoice_id', $invoiceId)->where('product_id', $productId)->where('variant_id', $variantId)->sum('quantity')
            : null;

        $variant = Schema::hasTable('product_variants')
            ? DB::table('product_variants')->where('id', $variantId)->first(['id', 'product_id', 'reserved', 'stock'])
            : null;
        $centralStock = Schema::hasTable('warehouse_stocks') && Schema::hasTable('warehouses')
            ? (int) DB::table('warehouse_stocks as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->where('w.type', 'central')->where('s.product_variant_id', $variantId)->sum('s.quantity')
            : null;

        $logs = [];
        $logTotal = null;
        if (Schema::hasTable('activity_logs')) {
            $logQuery = ActivityLog::query()->where(function ($query) use ($reservation, $orderId, $invoiceId): void {
                $query->where(fn ($q) => $q->where('subject_type', PreinvoiceDraftReservation::class)->where('subject_id', $reservation->id));
                if ($orderId) $query->orWhere(fn ($q) => $q->where('subject_type', PreinvoiceOrder::class)->where('subject_id', $orderId));
                if ($invoiceId) $query->orWhere(fn ($q) => $q->where('subject_type', Invoice::class)->where('subject_id', $invoiceId));
            });
            $logTotal = (clone $logQuery)->count();
            $logs = $logQuery->orderByDesc('id')->limit(30)->get(['id', 'subject_type', 'subject_id', 'action', 'user_id', 'occurred_at'])
                ->map(fn ($log) => [
                    'id' => (int) $log->id,
                    'subject_type' => class_basename($log->subject_type),
                    'subject_id' => (int) $log->subject_id,
                    'action' => (string) $log->action,
                    'actor_id' => $log->user_id,
                    'at' => $log->occurred_at?->toISOString(),
                ])->all();
        }

        $movementTotal = null;
        $movements = [];
        if ($orderId && Schema::hasTable('stock_movements') && Schema::hasColumn('stock_movements', 'reference_type') && Schema::hasColumn('stock_movements', 'reference_id')) {
            // Strictly order-linked references only, never infer a relationship
            // solely from matching an unrelated document ID or product variant.
            $query = DB::table('stock_movements')->where('reference_id', $orderId)
                ->where('product_id', $productId)
                ->whereIn('reference_type', [PreinvoiceOrder::class, 'preinvoice_order', 'preinvoice_orders']);
            if (Schema::hasColumn('stock_movements', 'product_variant_id')) {
                $query->where('product_variant_id', $variantId);
            }
            $movementTotal = (clone $query)->count();
            $movements = $query->orderByDesc('id')->limit(30)->get(['id', 'type', 'reason', 'quantity', 'reference_type', 'reference_id', 'created_at'])
                ->map(fn ($m) => (array) $m)->all();
        }

        return [
            'reservation_id' => (int) $reservation->id,
            'reservation_user_id' => $reservation->user_id,
            'preinvoice_order_id' => $reservation->preinvoice_order_id,
            'quantity' => (int) $reservation->quantity,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'reservation_scope' => $reservation->reservation_scope,
            'created_at' => $reservation->created_at?->toISOString(),
            'converted_at' => $reservation->converted_at?->toISOString(),
            'released_at' => $reservation->released_at?->toISOString(),
            'released_by' => $reservation->released_by,
            'release_reason' => $reservation->release_reason,
            'release_note' => $reservation->release_note,
            'classification' => $classification['state'],
            'classification_reason' => $classification['reason'],
            'order' => $order ? [
                'id' => (int) $order->id,
                'uuid' => (string) $order->uuid,
                'status' => (string) $order->status,
                'stock_released_at' => $order->stock_released_at?->toISOString(),
                'matching_line_quantity' => $orderQty,
            ] : null,
            'invoice' => $invoice ? [
                'id' => (int) $invoice->id,
                'uuid' => (string) $invoice->uuid,
                'status' => (string) $invoice->status,
                'matching_line_quantity' => $invoiceQty,
            ] : null,
            'matching_active_draft_ids' => $reservation->activeDrafts->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'activity_log_total' => $logTotal,
            'activity_log_excerpt' => $logs,
            'stock_movements_with_explicit_order_reference_total' => $movementTotal,
            'stock_movements_with_explicit_order_reference_excerpt' => $movements,
            'inventory_snapshot' => [
                'variant_reserved_cache' => $variant?->reserved,
                'variant_stock_cache' => $variant?->stock,
                'central_warehouse_quantity' => $centralStock,
                'snapshot_is_not_proof_of_reservation_stock_impact' => true,
            ],
            'review' => [
                'status' => 'OPEN_MANUAL_REVIEW',
                'decision_owner' => null,
                'decision' => null,
                'decision_reason' => null,
                'approved_by' => null,
                'reviewed_at' => null,
                'evidence_evaluated_at' => $evaluatedAt,
                'note' => 'No automatic release or archive. Verify cancellation, conversion, previous release and stock impact from primary records.',
            ],
        ];
    }

    private function csv(array $rows, array $missingIds): string
    {
        $stream = fopen('php://temp', 'r+');
        $columns = ['reservation_id', 'reservation_user_id', 'preinvoice_order_id', 'quantity', 'classification', 'classification_reason', 'product_id', 'variant_id', 'order_id', 'order_status', 'order_line_qty', 'order_stock_released_at', 'invoice_id', 'invoice_status', 'invoice_line_qty', 'reservation_released_at', 'release_reason', 'activity_log_count', 'order_linked_stock_movement_count', 'variant_reserved_cache', 'central_warehouse_qty', 'review_status', 'decision_owner', 'decision_reason'];
        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['reservation_id'], $row['reservation_user_id'], $row['preinvoice_order_id'], $row['quantity'], $row['classification'], $row['classification_reason'], $row['product_id'], $row['variant_id'],
                $row['order']['id'] ?? '', $row['order']['status'] ?? '', $row['order']['matching_line_quantity'] ?? '', $row['order']['stock_released_at'] ?? '',
                $row['invoice']['id'] ?? '', $row['invoice']['status'] ?? '', $row['invoice']['matching_line_quantity'] ?? '',
                $row['released_at'] ?? '', $row['release_reason'] ?? '', $row['activity_log_total'] ?? '',
                $row['stock_movements_with_explicit_order_reference_total'] ?? '',
                $row['inventory_snapshot']['variant_reserved_cache'] ?? '', $row['inventory_snapshot']['central_warehouse_quantity'] ?? '',
                $row['review']['status'], '', '',
            ]);
        }
        foreach ($missingIds as $id) {
            fputcsv($stream, [$id, '', '', '', 'NOT_FOUND', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'OPEN_MANUAL_REVIEW', '', '']);
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    private function enableReadOnlyGuard(): void
    {
        self::$guardEnabled = true;
        self::$guardedConnections ??= new \WeakMap();
        $connection = DB::connection();
        if (isset(self::$guardedConnections[$connection])) return;
        $connection->beforeExecuting(function (string $sql, array $bindings, Connection $connection): void {
            if (! self::$guardEnabled) return;
            $normalized = ltrim(preg_replace('~^(?:\s|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))+~s', '', $sql) ?? $sql);
            if (! preg_match('/^(select|show|describe|pragma|explain)\b/i', $normalized)) {
                throw new \RuntimeException('INV-06 blocked a non-read-only database statement.');
            }
        });
        self::$guardedConnections[$connection] = true;
    }
}
