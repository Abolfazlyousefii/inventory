<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RepairMissingOfficialReservations extends Command
{
    protected $signature = 'inventory:repair-missing-official-reservations {--order=} {--dry-run} {--apply} {--output=}';
    protected $description = 'Safely backfill missing official preinvoice reservations without touching stock or cache columns.';

    private const ACTIVE_STATUSES = [
        'reserved_waiting_warehouse',
        'warehouse_reviewing',
        'warehouse_approved_waiting_finance',
        'finance_reviewing',
        'pending_finance',
        'returned_to_warehouse',
    ];

    private const REJECTED_STATUSES = [
        'draft',
        'returned_to_sales',
        'cancelled',
        'reservation_expired',
        'converted_to_invoice',
        'cancelled_by_warehouse',
        'cancelled_by_finance',
    ];

    public function handle(): int
    {
        $orderId = $this->option('order');
        if (! filled($orderId) || ! ctype_digit((string) $orderId)) {
            $this->error('The --order option is required and must be a numeric preinvoice_order_id.');
            return self::FAILURE;
        }

        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $report = null;

        try {
            if ($apply) {
                $report = DB::transaction(fn () => $this->buildAndMaybeApplyReport((int) $orderId, true));
            } else {
                $report = $this->buildAndMaybeApplyReport((int) $orderId, false);
            }
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->line($json);

        if (filled($this->option('output'))) {
            file_put_contents((string) $this->option('output'), $json.PHP_EOL);
        }

        return self::SUCCESS;
    }

    private function buildAndMaybeApplyReport(int $orderId, bool $apply): array
    {
        $order = DB::table('preinvoice_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (! $order) {
            throw new \RuntimeException('Preinvoice order not found.');
        }

        $this->guardEligibleOrder($order);

        $lines = $this->reservationLines($orderId);
        $backfilled = [];

        foreach ($lines as $line) {
            if ($line['missing_quantity'] <= 0) {
                continue;
            }

            if ($line['cached_reserved'] < $line['missing_quantity']) {
                throw new \RuntimeException("Abort: cached_reserved is lower than missing_quantity for variant {$line['variant_id']}.");
            }

            if ($apply) {
                DB::table('preinvoice_draft_reservations')->insert([
                    'token' => (string) Str::uuid(),
                    'user_id' => null,
                    'preinvoice_order_id' => $orderId,
                    'product_id' => $line['product_id'],
                    'variant_id' => $line['variant_id'],
                    'quantity' => $line['missing_quantity'],
                    'expires_at' => null,
                    'last_seen_at' => null,
                    'browser_session_id' => null,
                    'converted_at' => null,
                    'released_at' => null,
                    'released_by' => null,
                    'release_reason' => null,
                    'release_note' => null,
                    'reservation_scope' => 'official',
                    'reservation_tier' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $backfilled[] = $line;
        }

        $after = $this->reservationLines($orderId, $backfilled);

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'order_id' => $orderId,
            'eligible' => true,
            'before' => $lines,
            'after' => $after,
            'backfilled' => $backfilled,
            'stock_changed' => false,
            'reserved_cache_changed' => false,
            'preinvoice_changed' => false,
        ];
    }

    private function guardEligibleOrder(object $order): void
    {
        if (in_array((string) $order->status, self::REJECTED_STATUSES, true) || ! in_array((string) $order->status, self::ACTIVE_STATUSES, true)) {
            throw new \RuntimeException("Preinvoice status {$order->status} is not eligible for reservation backfill.");
        }

        if ($order->stock_released_at !== null) {
            throw new \RuntimeException('Preinvoice stock has already been released.');
        }

        if (DB::table('invoices')->where('preinvoice_order_id', $order->id)->exists()) {
            throw new \RuntimeException('Preinvoice already has a related invoice.');
        }

        if (! DB::table('preinvoice_order_items')->where('preinvoice_order_id', $order->id)->where('quantity', '>', 0)->exists()) {
            throw new \RuntimeException('Preinvoice has no positive item quantity.');
        }
    }

    private function reservationLines(int $orderId, array $overlayBackfills = []): array
    {
        return DB::table('preinvoice_order_items as i')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->where('i.preinvoice_order_id', $orderId)
            ->where('i.quantity', '>', 0)
            ->groupBy('i.product_id', 'i.variant_id', 'v.reserved')
            ->selectRaw('i.product_id, i.variant_id, SUM(i.quantity) as required_quantity, COALESCE(v.reserved, 0) as cached_reserved')
            ->get()
            ->map(function ($row) use ($orderId, $overlayBackfills): array {
                $official = (int) DB::table('preinvoice_draft_reservations')
                    ->where('preinvoice_order_id', $orderId)
                    ->where('product_id', $row->product_id)
                    ->where('variant_id', $row->variant_id)
                    ->where('reservation_scope', 'official')
                    ->whereNull('released_at')
                    ->whereNull('release_reason')
                    ->sum('quantity');

                foreach ($overlayBackfills as $backfill) {
                    if ((int) $backfill['product_id'] === (int) $row->product_id && (int) $backfill['variant_id'] === (int) $row->variant_id) {
                        $official += (int) $backfill['missing_quantity'];
                    }
                }

                $required = (int) $row->required_quantity;

                return [
                    'product_id' => (int) $row->product_id,
                    'variant_id' => (int) $row->variant_id,
                    'required_quantity' => $required,
                    'official_quantity' => $official,
                    'missing_quantity' => max(0, $required - $official),
                    'cached_reserved' => (int) $row->cached_reserved,
                ];
            })
            ->values()
            ->all();
    }
}
