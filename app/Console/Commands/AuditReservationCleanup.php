<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceDraftReservation;
use App\Services\ReservationClassificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditReservationCleanup extends Command
{
    protected $signature = 'inventory:audit-reservation-cleanup
        {--reservation= : Reservation ID}
        {--product= : Product ID}
        {--variant= : Variant ID}
        {--user= : User ID}
        {--output=reports/reservation-cleanup-audit : Local storage report directory}';

    protected $description = 'Read-only canonical audit of reservation cleanup eligibility.';

    private const WRITE = '/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename|grant|revoke)\b/i';

    private const COLUMNS = [
        'reservation_id', 'token', 'user_id', 'product_id', 'product_name', 'variant_id', 'variant_name',
        'quantity', 'reservation_scope', 'reservation_tier', 'created_at', 'updated_at', 'last_seen_at',
        'expires_at', 'converted_at', 'released_at', 'age_minutes', 'age_hours', 'preinvoice_order_id',
        'preinvoice_uuid', 'preinvoice_status', 'invoice_id', 'invoice_status', 'matching_active_draft_id',
        'matching_active_draft_uuid', 'matching_active_draft_status', 'browser_session_id', 'classification',
        'classification_reason', 'recommended_action', 'would_change_warehouse_stock',
    ];

    public function handle(ReservationClassificationService $classifier): int
    {
        $writeDetected = null;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (&$writeDetected): void {
            if (preg_match(self::WRITE, preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$))+/s', '', $sql) ?? $sql)) {
                $writeDetected = $sql;
                throw new \RuntimeException('Write SQL blocked by reservation cleanup audit: '.$sql);
            }
        });

        $at = now();
        $rows = PreinvoiceDraftReservation::query()
            ->when($this->option('reservation'), fn ($q, $id) => $q->whereKey((int) $id))
            ->when($this->option('product'), fn ($q, $id) => $q->where('product_id', (int) $id))
            ->when($this->option('variant'), fn ($q, $id) => $q->where('variant_id', (int) $id))
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', (int) $id))
            ->with(['product:id,name', 'variant:id,product_id,variant_name,variety_name', 'order.invoice', 'activeDrafts'])
            ->orderBy('id')
            ->get()
            ->map(function (PreinvoiceDraftReservation $reservation) use ($classifier, $at): array {
                $classification = $classifier->classify($reservation, $at);
                $draft = $reservation->activeDrafts->first();
                $invoice = $reservation->order?->invoice;
                $ageMinutes = max(0, (int) $reservation->created_at?->diffInMinutes($at));

                return [
                    'reservation_id' => (int) $reservation->id,
                    'token' => (string) $reservation->token,
                    'user_id' => $reservation->user_id,
                    'product_id' => (int) $reservation->product_id,
                    'product_name' => (string) ($reservation->product?->name ?? ''),
                    'variant_id' => (int) $reservation->variant_id,
                    'variant_name' => (string) ($reservation->variant?->variant_name ?? $reservation->variant?->variety_name ?? ''),
                    'quantity' => (int) $reservation->quantity,
                    'reservation_scope' => $reservation->reservation_scope,
                    'reservation_tier' => $reservation->reservation_tier,
                    'created_at' => $reservation->created_at?->toISOString(),
                    'updated_at' => $reservation->updated_at?->toISOString(),
                    'last_seen_at' => $reservation->last_seen_at?->toISOString(),
                    'expires_at' => $reservation->expires_at?->toISOString(),
                    'converted_at' => $reservation->converted_at?->toISOString(),
                    'released_at' => $reservation->released_at?->toISOString(),
                    'age_minutes' => $ageMinutes,
                    'age_hours' => round($ageMinutes / 60, 2),
                    'preinvoice_order_id' => $reservation->preinvoice_order_id,
                    'preinvoice_uuid' => $reservation->order?->uuid,
                    'preinvoice_status' => $reservation->order?->status,
                    'invoice_id' => $invoice?->id,
                    'invoice_status' => $invoice?->status,
                    'matching_active_draft_id' => $draft?->id,
                    'matching_active_draft_uuid' => $draft?->uuid,
                    'matching_active_draft_status' => $draft?->status,
                    'browser_session_id' => $reservation->browser_session_id,
                    'classification' => $classification['state'],
                    'classification_reason' => $classification['reason'],
                    'recommended_action' => $classification['recommended_action'],
                    'would_change_warehouse_stock' => $classification['would_change_warehouse_stock'],
                ];
            })->all();

        if ($writeDetected !== null) {
            return self::FAILURE;
        }

        $directory = trim((string) $this->option('output'), '/');
        $csvPath = "{$directory}/reservations.csv";
        $jsonPath = "{$directory}/summary.json";
        Storage::disk('local')->put($csvPath, $this->csv($rows));
        Storage::disk('local')->put($jsonPath, json_encode([
            'evaluated_at' => $at->toISOString(),
            'count' => count($rows),
            'by_classification' => array_count_values(array_column($rows, 'classification')),
            'data_changed' => false,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('Reservation cleanup audit complete: '.count($rows).' rows.');
        $this->line(json_encode(['csv' => $csvPath, 'json' => $jsonPath, 'data_changed' => false], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, self::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $column) => match (true) {
                is_bool($row[$column] ?? null) => $row[$column] ? 'true' : 'false',
                default => $row[$column] ?? '',
            }, self::COLUMNS));
        }
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}
