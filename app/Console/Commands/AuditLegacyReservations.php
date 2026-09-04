<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceDraftReservation;
use App\Services\ReservationClassificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 4-A — Reservation Legacy Audit.
 *
 * Strictly read-only: identifies reservations that are probably stale legacy
 * data and reports them for manual review. It never releases a reservation,
 * never touches product/variant/warehouse stock caches, and never writes to
 * invoices or preinvoices — a write-query guard (same pattern as
 * AuditLegacyReservationIntegrity / AuditStockReservationIntegrity) throws
 * if anything in this command's transaction attempts a write.
 *
 * Classification and age rules are not reinvented here — every row is
 * classified via the existing ReservationClassificationService (Phase 2),
 * which itself only reuses existing model scopes. This command adds no new
 * business rules; it only aggregates and reports what already exists.
 */
class AuditLegacyReservations extends Command
{
    protected $signature = 'inventory:audit-legacy-reservations {--output=reports/legacy-reservations-audit}';

    protected $description = 'Read-only audit that identifies stale/legacy reservation records for manual review. Reports only — never modifies reservations, stock, or invoices.';

    private const WRITE_VERBS = 'insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke';

    private const CSV_HEAD = [
        'id', 'product', 'variant', 'quantity', 'type', 'classification',
        'created_at', 'last_activity', 'age_days', 'user', 'customer',
        'preinvoice_order_id', 'invoice_id', 'recommended_action',
    ];

    private const AGE_40_DAYS = 40;

    private const AGE_80_DAYS = 80;

    private static bool $writeGuardEnabled = false;

    private static ?\WeakMap $guardedConnections = null;

    public function handle(ReservationClassificationService $classification): int
    {
        $startedAt = now()->toISOString();

        $this->installWriteQueryGuard();
        try {
            $report = DB::transaction(fn () => $this->buildReport($classification), 1);
        } finally {
            $this->disableWriteQueryGuard();
        }

        $paths = $this->writeReport($report, $startedAt);

        $this->line(json_encode(['summary' => $report['summary'], 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->table(['metric', 'value'], collect($report['summary'])->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }

    private function buildReport(ReservationClassificationService $classification): array
    {
        $now = now();

        // Only currently-open reservations are candidates for legacy review —
        // already-released rows are resolved and belong to the "history" tab,
        // not this audit.
        $reservations = PreinvoiceDraftReservation::query()
            ->whereNull('released_at')
            ->where('quantity', '>', 0)
            ->with([
                'product:id,name,sku,code',
                'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                'user:id,name',
                'order:id,uuid,status,customer_id,customer_name,customer_mobile',
                'order.invoice:id,preinvoice_order_id',
                'activeDrafts:id,draft_token,status',
            ])
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $summary = [
            'audit_started_at' => $now->toISOString(),
            'audit_finished_at' => null,
            'total_reservations_scanned' => 0,
            'age_40_plus_days_count' => 0,
            'age_80_plus_days_count' => 0,
            'legacy_candidate_count' => 0,
            'total_reserved_quantity_affected' => 0,
            'official_preinvoice_count' => 0,
            'invoice_linked_count' => 0,
            'recommended_keep' => 0,
            'recommended_release' => 0,
            'recommended_remove_legacy' => 0,
            'data_changed' => false,
            'stock_changed' => false,
            'reservations_changed' => false,
            'invoices_changed' => false,
        ];

        foreach ($reservations as $reservation) {
            $ageDays = (int) floor((float) ($reservation->created_at?->diffInDays($now) ?? 0));
            $classified = $classification->classify($reservation, $now);
            $type = $classified['type'];
            $label = $classified['label'];
            $hasInvoice = $reservation->order?->invoice !== null;
            $action = $this->recommendedAction($label, $hasInvoice);

            $summary['total_reservations_scanned']++;

            if ($ageDays >= self::AGE_40_DAYS) {
                $summary['age_40_plus_days_count']++;
            }
            if ($ageDays >= self::AGE_80_DAYS) {
                $summary['age_80_plus_days_count']++;
            }
            if ($label === ReservationClassificationService::LABEL_LEGACY_CANDIDATE) {
                $summary['legacy_candidate_count']++;
                $summary['total_reserved_quantity_affected'] += (int) $reservation->quantity;
            }
            if ($type === ReservationClassificationService::TYPE_OFFICIAL) {
                $summary['official_preinvoice_count']++;
            }
            if ($hasInvoice) {
                $summary['invoice_linked_count']++;
            }
            match ($action) {
                'KEEP' => $summary['recommended_keep']++,
                'RELEASE' => $summary['recommended_release']++,
                'REMOVE_LEGACY' => $summary['recommended_remove_legacy']++,
            };

            $rows[] = [
                'id' => (int) $reservation->id,
                'product' => (string) ($reservation->product?->name ?? ''),
                'variant' => (string) ($reservation->variant?->variant_name ?: $reservation->variant?->variety_name ?: ''),
                'quantity' => (int) $reservation->quantity,
                'type' => $type,
                'classification' => $label,
                'created_at' => optional($reservation->created_at)->toDateTimeString(),
                'last_activity' => optional($reservation->managementLastActivityAt())->toDateTimeString(),
                'age_days' => $ageDays,
                'user' => (string) ($reservation->user?->name ?? ''),
                'customer' => (string) ($reservation->order?->customer_name ?? ''),
                'preinvoice_order_id' => $reservation->preinvoice_order_id,
                'invoice_id' => $reservation->order?->invoice?->id,
                'recommended_action' => $action,
            ];
        }

        $summary['audit_finished_at'] = now()->toISOString();

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * Report-only recommendation, never applied automatically. Purely a
     * lookup from the existing classification label (see
     * ReservationClassificationService) to a suggested next action:
     *
     * - Invoice already linked -> KEEP (already consumed by a real sale,
     *   must never be touched by any future cleanup tooling).
     * - legacy_candidate -> REMOVE_LEGACY (matches the existing
     *   LegacyReservationCleanupService/scopeLegacyCleanupCandidates
     *   definition already used elsewhere in the app).
     * - critical or temporary_orphan -> RELEASE (needs a human to release it
     *   through the existing InventoryReservationReleaseService; not old
     *   enough / not unlinked enough yet to be an outright legacy removal).
     * - official_preinvoice or temporary_active -> KEEP (still legitimately
     *   in progress).
     */
    private function recommendedAction(string $label, bool $hasInvoice): string
    {
        if ($hasInvoice) {
            return 'KEEP';
        }

        return match ($label) {
            ReservationClassificationService::LABEL_LEGACY_CANDIDATE => 'REMOVE_LEGACY',
            ReservationClassificationService::LABEL_CRITICAL,
            ReservationClassificationService::LABEL_TEMPORARY_ORPHAN => 'RELEASE',
            default => 'KEEP',
        };
    }

    /** @return array{summary: string, csv: string} */
    private function writeReport(array $report, string $startedAt): array
    {
        $base = trim((string) $this->option('output'), '/');
        $csvPath = "{$base}/legacy-reservations.csv";
        $summaryPath = "{$base}/summary.json";

        Storage::disk('local')->put($csvPath, $this->csv($report['rows']));
        Storage::disk('local')->put($summaryPath, json_encode($report['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ['summary' => $summaryPath, 'csv' => $csvPath];
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::CSV_HEAD);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $row[$column] ?? '', self::CSV_HEAD));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function installWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = true;
        $connection = DB::connection();
        self::$guardedConnections ??= new \WeakMap;
        if (isset(self::$guardedConnections[$connection])) {
            return;
        }

        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if (! self::$writeGuardEnabled) {
                return;
            }

            $normalized = ltrim(preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s', ' ', $query) ?? $query);
            if (preg_match('/^('.self::WRITE_VERBS.')\b/i', $normalized)) {
                throw new \RuntimeException('Unsafe write query blocked before execution during legacy reservation audit.');
            }
        });
        self::$guardedConnections[$connection] = true;
    }

    private function disableWriteQueryGuard(): void
    {
        self::$writeGuardEnabled = false;
    }
}
