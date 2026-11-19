<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\BundleComponentAllocationService;
use App\Services\ReservationQueryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Report protected but unsold component stock, then optionally release an
 * operator-specified surplus back to ordinary stock. No inference is made from
 * historical recipe edits; those may require an inventory reconciliation.
 */
class ReleaseUnusedBundleAllocations extends Command
{
    protected $signature = 'bundle:release-unused
        {bundle_variant_id : Selling variant ID of the automatic bundle}
        {--keep= : Number of complete uncommitted bundles to retain in allocations}
        {--apply : Commit the displayed stock return; omitted means report only}';

    protected $description = 'Audit an automatic bundle\'s remaining component allocations and optionally release an explicitly chosen surplus';

    public function handle(): int
    {
        $bundleVariantId = (int) $this->argument('bundle_variant_id');
        $keepOption = $this->option('keep');
        $keep = null;
        if ($bundleVariantId < 1 || ($keepOption !== null && (!ctype_digit((string) $keepOption)
            || (int) $keepOption > 1000000))) {
            $this->error('Use a valid bundle variant ID and --keep as a non-negative number <= 1000000.');
            return self::FAILURE;
        }
        if ($keepOption !== null) {
            $keep = (int) $keepOption;
        }
        if ($this->option('apply') && $keep === null) {
            $this->error('--apply requires --keep=N. The command never guesses how much to release.');
            return self::FAILURE;
        }

        try {
            // Recheck balances under a bundle product lock when applying. This
            // serializes with checkout and the Inventory allocations form.
            $plan = $this->option('apply')
                ? DB::transaction(function () use ($bundleVariantId, $keep): array {
                    $variant = ProductVariant::query()->findOrFail($bundleVariantId);
                    Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
                    $plan = $this->plan($bundleVariantId, $keep, true);
                    if ($plan['released_total'] === 0) {
                        return $plan;
                    }
                    $this->assertSafeToRelease($bundleVariantId);
                    app(BundleComponentAllocationService::class)->setAllocations($bundleVariantId, $plan['desired']);
                    return $plan;
                }, 3)
                : $this->plan($bundleVariantId, $keep, false);
        } catch (Throwable $exception) {
            $this->error('No stock was released: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Bundle variant: '.$bundleVariantId.' | mode: automatic | central warehouse: '.$plan['warehouse_id']);
        $this->table(
            ['Component variant', 'Units per bundle', 'Ordinary stock', 'Allocated free', 'Keep allocated', 'Return to stock'],
            $plan['rows']
        );
        if ($keep === null) {
            $this->comment('Report only. Set --keep=N to preview a release. This never touches invoice/site reservations.');
        } elseif ($this->option('apply')) {
            $this->info('Released '.$plan['released_total'].' component units to ordinary warehouse stock across the listed variants.');
        } else {
            $this->comment('Preview only: '.$plan['released_total'].' component units would return to ordinary stock.');
            $this->comment('After checking the quantities and taking a database backup, repeat with --apply.');
        }
        return self::SUCCESS;
    }

    /** @return array{warehouse_id:int,desired:array<int,int>,released_total:int,rows:array<int,array<int,string|int>>} */
    private function plan(int $bundleVariantId, ?int $keep, bool $lock): array
    {
        $variant = ProductVariant::query()->with('product')->findOrFail($bundleVariantId);
        if (!$variant->product?->is_bundle || $variant->product->bundle_stock_mode !== 'automatic') {
            throw new InvalidArgumentException('The variant must belong to a bundle in automatic stock mode.');
        }
        $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)
            ->orderBy('component_variant_id')->pluck('quantity', 'component_variant_id');
        if ($recipe->isEmpty()) {
            throw new InvalidArgumentException('The bundle has no saved recipe.');
        }
        $allocatedQuery = DB::table('bundle_component_allocations')
            ->where('bundle_variant_id', $bundleVariantId)->orderBy('component_variant_id');
        $allocated = ($lock ? $allocatedQuery->lockForUpdate() : $allocatedQuery)
            ->get()->keyBy('component_variant_id');
        foreach ($allocated as $id => $row) {
            if (!$recipe->has($id) && (int) $row->remaining > 0) {
                throw new InvalidArgumentException("Orphan allocation for component {$id}; audit the recipe history before releasing it.");
            }
        }
        $warehouseId = (int) (Warehouse::query()->where('type', 'central')->where('name', 'انبار مرکزی')->value('id') ?? 0);
        if ($warehouseId < 1) {
            throw new InvalidArgumentException('Central warehouse not found.');
        }
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $recipe->keys())
            ->groupBy('product_variant_id')->selectRaw('product_variant_id, SUM(quantity) AS quantity')
            ->pluck('quantity', 'product_variant_id');

        $rows = [];
        $desired = [];
        $releasedTotal = 0;
        foreach ($recipe as $id => $unitsPerBundle) {
            $id = (int) $id;
            $unitsPerBundle = (int) $unitsPerBundle;
            $remaining = (int) ($allocated->get($id)?->remaining ?? 0);
            if ($unitsPerBundle < 1 || $remaining < 0) {
                throw new InvalidArgumentException("Invalid recipe/allocation for component {$id}.");
            }
            $next = $keep === null ? $remaining : $keep * $unitsPerBundle;
            if ($next > 1000000) {
                throw new InvalidArgumentException("Allocation target exceeds supported maximum for component {$id}.");
            }
            if ($next > $remaining) {
                throw new InvalidArgumentException("Cannot retain {$keep} bundles: component {$id} has only {$remaining} allocated, needs {$next}. No additional stock will be taken.");
            }
            $release = $remaining - $next;
            $releasedTotal += $release;
            $desired[$id] = $next;
            $rows[] = [
                $id, $unitsPerBundle, (int) ($stock[$id] ?? 0), $remaining,
                $keep === null ? 'not specified' : $next,
                $keep === null ? 'not specified' : $release,
            ];
        }
        return ['warehouse_id' => $warehouseId, 'desired' => $desired,
            'released_total' => $releasedTotal, 'rows' => $rows];
    }

    private function assertSafeToRelease(int $bundleVariantId): void
    {
        if ((int) app(ReservationQueryService::class)
            ->quantitiesByVariant(variantIds: [$bundleVariantId])->get($bundleVariantId, 0) > 0) {
            throw new InvalidArgumentException('Active preinvoice bundle reservations exist; resolve them first.');
        }
        if (Schema::hasTable('site_stock_holds') && DB::table('site_stock_holds')
            ->whereIn('status', ['held', 'payment_confirmed'])
            ->whereRaw('JSON_CONTAINS_PATH(bundle_allocated_lines, \'one\', ?) = 1', ['$.' . '"' . $bundleVariantId . '"'])
            ->exists()) {
            throw new InvalidArgumentException('Active Site bundle holds exist; resolve them first.');
        }
        if (WarehouseStock::query()->where('product_variant_id', $bundleVariantId)->where('quantity', '>', 0)->exists()) {
            throw new InvalidArgumentException('Finished bundle stock also exists; reconcile physical packs before releasing components.');
        }
    }
}
