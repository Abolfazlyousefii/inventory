<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceOrder;
use App\Support\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class PreinvoiceReservationAuditCommand extends Command
{
    protected $signature = 'preinvoice:reservation-audit {--order= : Audit/repair one preinvoice order id} {--dry-run : Report only} {--apply : Apply the repair}';

    protected $description = 'Audit and repair active preinvoice reservations without expiring or deleting active order data.';

    private const ACTIVE_STATUSES = [
        PreinvoiceOrder::STATUS_DRAFT,
        PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
        PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
        PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
        PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
        PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
        PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        'warehouse_approved',
        'pending_finance',
        'waiting_finance',
        'pending_finance_approval',
        'pending_finance_reapproval',
        'finance_approved',
        'invoiced',
    ];

    public function handle(): int
    {
        $orderId = $this->option('order') !== null ? (int) $this->option('order') : null;
        $apply = (bool) $this->option('apply');

        if ($apply && (bool) $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');
            return SymfonyCommand::FAILURE;
        }

        $report = $this->buildReport($orderId);
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode: no data changed.');
        $this->table(['metric', 'value'], [
            ['active_orders', $report['active_orders']->count()],
            ['variant_mismatches', $report['variant_mismatches']->count()],
            ['reserved_greater_than_stock', $report['reserved_over_stock']->count()],
            ['expired_active_orders', $report['expired_active_orders']->count()],
            ['orders_missing_converted_trace', $report['orders_missing_trace']->count()],
            ['converted_reservations_with_expires_at', $report['converted_with_expiry']->count()],
        ]);

        if ($report['variant_mismatches']->isNotEmpty()) {
            $this->warn('Reserved mismatches:');
            $this->table(['variant_id', 'product_id', 'stock', 'current_reserved', 'expected_reserved', 'delta'], $report['variant_mismatches']->values()->all());
        }
        if ($report['reserved_over_stock']->isNotEmpty()) {
            $this->warn('Reserved greater than stock (data inconsistency, not clamped in audit):');
            $this->table(['variant_id', 'product_id', 'stock', 'current_reserved', 'expected_reserved'], $report['reserved_over_stock']->values()->all());
        }
        if ($report['expired_active_orders']->isNotEmpty()) {
            $this->warn('Active orders with stock_frozen_until in the past:');
            $this->table(['order_id', 'uuid', 'status', 'stock_frozen_until'], $report['expired_active_orders']->values()->all());
        }
        if ($report['orders_missing_trace']->isNotEmpty()) {
            $this->warn('Active orders with items but missing converted reservation trace:');
            $this->table(['order_id', 'uuid', 'status', 'items_quantity'], $report['orders_missing_trace']->values()->all());
        }
        if ($report['converted_with_expiry']->isNotEmpty()) {
            $this->warn('Converted reservation rows that still have expires_at:');
            $this->table(['reservation_id', 'order_id', 'variant_id', 'quantity', 'expires_at'], $report['converted_with_expiry']->values()->all());
        }

        if (! $apply) {
            return SymfonyCommand::SUCCESS;
        }

        DB::transaction(function () use ($orderId, $report) {
            $backups = $this->createBackups($orderId);
            $this->info('Backup tables created: ' . implode(', ', $backups));

            foreach ($report['expected_by_variant'] as $variantId => $row) {
                DB::table('product_variants')
                    ->where('id', (int) $variantId)
                    ->lockForUpdate()
                    ->update(['reserved' => (int) $row['expected_reserved'], 'updated_at' => now()]);
            }

            $touchedProductIds = $report['expected_by_variant']->pluck('product_id')->map(fn ($id) => (int) $id)->unique();
            foreach ($touchedProductIds as $productId) {
                $reservedSum = (int) DB::table('product_variants')->where('product_id', $productId)->sum('reserved');
                DB::table('products')->where('id', $productId)->lockForUpdate()->update(['reserved' => $reservedSum, 'updated_at' => now()]);
            }

            DB::table('preinvoice_orders')
                ->whereIn('id', $report['active_orders']->pluck('id')->all())
                ->update(['stock_frozen_until' => null, 'updated_at' => now()]);

            DB::table('preinvoice_draft_reservations')
                ->whereNotNull('converted_at')
                ->when($orderId, fn ($query) => $query->where('preinvoice_order_id', $orderId))
                ->whereIn('preinvoice_order_id', $report['active_orders']->pluck('id')->all())
                ->update(['expires_at' => null, 'updated_at' => now()]);

            $this->repairConvertedReservationTrace($report['required_by_order_variant']);

            foreach ($report['active_orders'] as $orderRow) {
                $order = PreinvoiceOrder::query()->find((int) $orderRow->id);
                if ($order) {
                    ActivityLogger::log('preinvoice_reservation_audit_repaired', $order, 'Audit رزرو پیش‌فاکتور اجرا و ناسازگاری‌ها ترمیم شد.', [
                        'command' => 'preinvoice:reservation-audit',
                        'order_id' => (int) $orderRow->id,
                    ]);
                }
            }
        });

        $this->info('Reservation audit repair completed.');
        return SymfonyCommand::SUCCESS;
    }

    private function buildReport(?int $orderId): array
    {
        $activeOrders = DB::table('preinvoice_orders')
            ->whereNull('stock_released_at')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($orderId, fn ($query) => $query->where('id', $orderId))
            ->get(['id', 'uuid', 'status', 'stock_frozen_until']);

        $activeOrderIds = $activeOrders->pluck('id')->map(fn ($id) => (int) $id)->all();

        $required = collect();
        if (! empty($activeOrderIds)) {
            $required = DB::table('preinvoice_order_items AS poi')
                ->join('preinvoice_orders AS po', 'po.id', '=', 'poi.preinvoice_order_id')
                ->whereIn('po.id', $activeOrderIds)
                ->whereNotNull('poi.variant_id')
                ->select('po.id AS order_id', 'poi.product_id', 'poi.variant_id', DB::raw('SUM(poi.quantity) AS quantity'))
                ->groupBy('po.id', 'poi.product_id', 'poi.variant_id')
                ->get();
        }

        $requiredByOrderVariant = $required->keyBy(fn ($row) => ((int) $row->order_id) . ':' . ((int) $row->variant_id));
        $expectedByVariant = $required
            ->groupBy('variant_id')
            ->map(fn ($rows) => [
                'variant_id' => (int) $rows->first()->variant_id,
                'product_id' => (int) $rows->first()->product_id,
                'expected_reserved' => (int) $rows->sum('quantity'),
            ]);

        $variantIds = $expectedByVariant->keys()->map(fn ($id) => (int) $id)->all();
        $variantRows = empty($variantIds) ? collect() : DB::table('product_variants')
            ->whereIn('id', $variantIds)
            ->get(['id', 'product_id', 'stock', 'reserved'])
            ->keyBy('id');

        $variantMismatches = $expectedByVariant->map(function ($row, $variantId) use ($variantRows) {
            $variant = $variantRows->get((int) $variantId);
            $current = (int) ($variant->reserved ?? 0);
            return [
                'variant_id' => (int) $variantId,
                'product_id' => (int) ($variant->product_id ?? $row['product_id']),
                'stock' => (int) ($variant->stock ?? 0),
                'current_reserved' => $current,
                'expected_reserved' => (int) $row['expected_reserved'],
                'delta' => (int) $row['expected_reserved'] - $current,
            ];
        })->filter(fn ($row) => (int) $row['delta'] !== 0)->values();

        $reservedOverStock = $variantRows->filter(fn ($variant) => (int) $variant->reserved > (int) $variant->stock)
            ->map(fn ($variant) => [
                'variant_id' => (int) $variant->id,
                'product_id' => (int) $variant->product_id,
                'stock' => (int) $variant->stock,
                'current_reserved' => (int) $variant->reserved,
                'expected_reserved' => (int) ($expectedByVariant[(int) $variant->id]['expected_reserved'] ?? 0),
            ]);

        $convertedByOrderVariant = empty($activeOrderIds) ? collect() : DB::table('preinvoice_draft_reservations')
            ->whereIn('preinvoice_order_id', $activeOrderIds)
            ->whereNotNull('converted_at')
            ->select('preinvoice_order_id', 'variant_id', DB::raw('SUM(quantity) AS quantity'))
            ->groupBy('preinvoice_order_id', 'variant_id')
            ->get()
            ->keyBy(fn ($row) => ((int) $row->preinvoice_order_id) . ':' . ((int) $row->variant_id));

        return [
            'active_orders' => $activeOrders,
            'required_by_order_variant' => $requiredByOrderVariant,
            'expected_by_variant' => $expectedByVariant,
            'variant_mismatches' => $variantMismatches,
            'reserved_over_stock' => $reservedOverStock,
            'expired_active_orders' => $activeOrders->filter(fn ($order) => $order->stock_frozen_until !== null && \Illuminate\Support\Carbon::parse($order->stock_frozen_until)->lt(now())),
            'orders_missing_trace' => $this->ordersMissingConvertedTrace($activeOrders, $required, $convertedByOrderVariant),
            'converted_with_expiry' => $this->convertedReservationsWithExpiry($activeOrderIds),
        ];
    }

    private function ordersMissingConvertedTrace(Collection $activeOrders, Collection $required, Collection $convertedByOrderVariant): Collection
    {
        return $activeOrders->filter(function ($order) use ($required, $convertedByOrderVariant) {
            $orderRequired = $required->where('order_id', $order->id);
            if ($orderRequired->isEmpty()) {
                return false;
            }

            foreach ($orderRequired as $row) {
                $key = ((int) $row->order_id) . ':' . ((int) $row->variant_id);
                if ((int) ($convertedByOrderVariant->get($key)->quantity ?? 0) < (int) $row->quantity) {
                    return true;
                }
            }

            return false;
        })->map(fn ($order) => [
            'order_id' => (int) $order->id,
            'uuid' => $order->uuid,
            'status' => $order->status,
            'items_quantity' => (int) $required->where('order_id', $order->id)->sum('quantity'),
        ]);
    }

    private function convertedReservationsWithExpiry(array $activeOrderIds): Collection
    {
        if (empty($activeOrderIds)) {
            return collect();
        }

        return DB::table('preinvoice_draft_reservations')
            ->whereIn('preinvoice_order_id', $activeOrderIds)
            ->whereNotNull('converted_at')
            ->whereNotNull('expires_at')
            ->get(['id AS reservation_id', 'preinvoice_order_id AS order_id', 'variant_id', 'quantity', 'expires_at']);
    }

    private function repairConvertedReservationTrace(Collection $requiredByOrderVariant): void
    {
        foreach ($requiredByOrderVariant as $row) {
            $rows = DB::table('preinvoice_draft_reservations')
                ->where('preinvoice_order_id', (int) $row->order_id)
                ->where('variant_id', (int) $row->variant_id)
                ->whereNotNull('converted_at')
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $requiredQty = (int) $row->quantity;
            $currentQty = (int) $rows->sum('quantity');
            $delta = $requiredQty - $currentQty;

            if ($rows->isEmpty()) {
                DB::table('preinvoice_draft_reservations')->insert([
                    'token' => (string) \Illuminate\Support\Str::uuid(),
                    'user_id' => null,
                    'preinvoice_order_id' => (int) $row->order_id,
                    'product_id' => (int) $row->product_id,
                    'variant_id' => (int) $row->variant_id,
                    'quantity' => $requiredQty,
                    'expires_at' => null,
                    'converted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                continue;
            }

            if ($delta > 0) {
                $first = $rows->first();
                DB::table('preinvoice_draft_reservations')
                    ->where('id', $first->id)
                    ->update(['quantity' => (int) $first->quantity + $delta, 'expires_at' => null, 'updated_at' => now()]);
            } elseif ($delta < 0) {
                $remainingReduction = abs($delta);
                foreach ($rows as $reservationRow) {
                    if ($remainingReduction <= 0) {
                        break;
                    }

                    $currentRowQty = (int) $reservationRow->quantity;
                    $rowReduction = min($currentRowQty, $remainingReduction);
                    DB::table('preinvoice_draft_reservations')
                        ->where('id', $reservationRow->id)
                        ->update(['quantity' => $currentRowQty - $rowReduction, 'expires_at' => null, 'updated_at' => now()]);
                    $remainingReduction -= $rowReduction;
                }
            }
        }
    }

    private function createBackups(?int $orderId): array
    {
        $suffix = now()->format('Ymd_His') . ($orderId ? '_order_' . $orderId : '');
        $tables = ['product_variants', 'products', 'preinvoice_orders', 'preinvoice_draft_reservations'];
        $names = [];
        foreach ($tables as $table) {
            $name = 'backup_' . $table . '_reservation_audit_' . $suffix;
            DB::statement("CREATE TABLE `{$name}` AS SELECT * FROM `{$table}`");
            $names[] = $name;
        }

        return $names;
    }
}
