<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Services\InventoryReservationReleaseService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepairConvertedReservations extends Command
{
    /**
     * Keep the original preinvoice namespace while exposing the inventory
     * namespace used by the repair runbook. Both names execute the exact same
     * lifecycle-only repair.
     */
    protected $aliases = ['preinvoice:repair-converted-reservations'];

    protected $signature = 'inventory:repair-converted-reservations
        {--dry-run : Report converted reservations without changing data}
        {--apply : Complete the converted reservation lifecycle}';

    protected $description = 'Safely mark invoice-consumed reservations as released without changing invoices or stock.';

    public function __construct(private readonly InventoryReservationReleaseService $releaseService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $matched = PreinvoiceDraftReservation::query()->convertedUnreleased();
        $eligible = $this->eligibleQuery();

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'records' => (clone $matched)->count(),
            'quantity' => (int) (clone $matched)->sum('quantity'),
            'products' => (clone $matched)->distinct()->count('product_id'),
            'variants' => (clone $matched)->distinct()->count('variant_id'),
            'eligible_records' => (clone $eligible)->count(),
            'eligible_quantity' => (int) (clone $eligible)->sum('quantity'),
            'skipped_unverified_records' => (clone $matched)->count() - (clone $eligible)->count(),
            'released_records' => 0,
            'released_quantity' => 0,
            'invoice_changed' => false,
            'warehouse_stock_changed' => false,
            'stock_movement_created' => false,
            'activity_log_created' => false,
        ];

        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->renderProducts($matched);
        $this->renderVariants($matched);

        if (! $apply) {
            return self::SUCCESS;
        }

        $eligible->select('id')->lazyById(500)->each(function (PreinvoiceDraftReservation $candidate) use (&$summary): void {
            $result = $this->releaseService->releaseConvertedReservation($candidate, null, false);
            if ($result['released']) {
                $summary['released_records']++;
                $summary['released_quantity'] += $result['quantity'];
            }
        });

        $this->newLine();
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function eligibleQuery(): Builder
    {
        return PreinvoiceDraftReservation::query()
            ->convertedUnreleased()
            ->where(function (Builder $query): void {
                $query->where('release_reason', 'consumed')
                    ->orWhereHas('order', function (Builder $order): void {
                        $order->where('status', PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE)
                            ->orWhereHas('invoice');
                    });
            })
            ->orderBy('id');
    }

    private function renderProducts(Builder $query): void
    {
        $rows = (clone $query)
            ->leftJoin('products', 'products.id', '=', 'preinvoice_draft_reservations.product_id')
            ->groupBy('preinvoice_draft_reservations.product_id', 'products.name')
            ->orderBy('preinvoice_draft_reservations.product_id')
            ->get([
                'preinvoice_draft_reservations.product_id',
                'products.name',
                DB::raw('COUNT(*) as records'),
                DB::raw('SUM(preinvoice_draft_reservations.quantity) as quantity'),
            ]);

        $this->table(['Product ID', 'Product', 'Records', 'Quantity'], $rows->map(fn ($row) => [
            (int) $row->product_id,
            (string) ($row->name ?? ''),
            (int) $row->records,
            (int) $row->quantity,
        ])->all());
    }

    private function renderVariants(Builder $query): void
    {
        $rows = (clone $query)
            ->leftJoin('product_variants', 'product_variants.id', '=', 'preinvoice_draft_reservations.variant_id')
            ->groupBy('preinvoice_draft_reservations.variant_id', 'product_variants.variant_name', 'product_variants.variant_code')
            ->orderBy('preinvoice_draft_reservations.variant_id')
            ->get([
                'preinvoice_draft_reservations.variant_id',
                'product_variants.variant_name',
                'product_variants.variant_code',
                DB::raw('COUNT(*) as records'),
                DB::raw('SUM(preinvoice_draft_reservations.quantity) as quantity'),
            ]);

        $this->table(['Variant ID', 'Variant', 'Code', 'Records', 'Quantity'], $rows->map(fn ($row) => [
            (int) $row->variant_id,
            (string) ($row->variant_name ?? ''),
            (string) ($row->variant_code ?? ''),
            (int) $row->records,
            (int) $row->quantity,
        ])->all());
    }
}
