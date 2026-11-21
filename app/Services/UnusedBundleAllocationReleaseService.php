<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** One audited implementation for both the administrative UI and the maintenance command. */
final class UnusedBundleAllocationReleaseService
{
    /** Preview is read-only. Apply recomputes the same plan under a product lock. */
    public function preview(int $bundleVariantId, ?int $keep = null): array
    {
        return $this->plan($bundleVariantId, $keep, false);
    }

    public function apply(int $bundleVariantId, int $keep, ?string $expectedToken, ?int $actorId, string $reason): array
    {
        if ($keep < 0 || $keep > 1000000 || trim($reason) === '') {
            throw ValidationException::withMessages(['keep' => 'تعداد نگه‌داری یا علت آزادسازی معتبر نیست.']);
        }
        return DB::transaction(function () use ($bundleVariantId, $keep, $expectedToken, $actorId, $reason): array {
            $variant = ProductVariant::query()->findOrFail($bundleVariantId);
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            $plan = $this->plan($bundleVariantId, $keep, true);
            if ($expectedToken !== null && !hash_equals($expectedToken, $plan['token'])) {
                throw ValidationException::withMessages(['keep' => 'موجودی از زمان پیش‌نمایش تغییر کرده است. پیش‌نمایش را تازه کنید.']);
            }
            if ($plan['released_total'] === 0) return $plan; // Repeating a completed CLI operation is harmless.

            $this->assertSafeToRelease($bundleVariantId);
            // Never release without a durable, transactionally committed audit record.
            if (!Schema::hasTable('bundle_management_events')) {
                throw new InvalidArgumentException('Bundle management audit migration must be applied first.');
            }
            app(BundleComponentAllocationService::class)->setAllocations($bundleVariantId, $plan['desired']);
            $plan['event_id'] = (string) Str::uuid();
            DB::table('bundle_management_events')->insert([
                'event_id' => $plan['event_id'], 'bundle_variant_id' => $bundleVariantId,
                'actor_id' => $actorId, 'action' => 'release', 'reason' => trim($reason),
                'details' => json_encode(['keep' => $keep, 'warehouse_id' => $plan['warehouse_id'],
                    'rows' => $plan['rows'], 'released_total' => $plan['released_total']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            return $plan;
        }, 3);
    }

    /** @return array{warehouse_id:int,desired:array<int,int>,released_total:int,rows:array,token:string} */
    private function plan(int $bundleVariantId, ?int $keep, bool $lock): array
    {
        if ($bundleVariantId < 1 || ($keep !== null && ($keep < 0 || $keep > 1000000))) {
            throw new InvalidArgumentException('Invalid bundle variant or keep quantity.');
        }
        $variant = ProductVariant::query()->with('product')->findOrFail($bundleVariantId);
        if (!$variant->product?->is_bundle || $variant->product->bundle_stock_mode !== 'automatic') {
            throw new InvalidArgumentException('The variant must belong to an automatic bundle.');
        }
        $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)
            ->orderBy('component_variant_id')->pluck('quantity', 'component_variant_id');
        if ($recipe->isEmpty()) throw new InvalidArgumentException('The bundle has no saved recipe.');
        $query = DB::table('bundle_component_allocations')->where('bundle_variant_id', $bundleVariantId)
            ->orderBy('component_variant_id');
        $allocated = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('component_variant_id');
        foreach ($allocated as $id => $row) {
            if (!$recipe->has($id) && (int) $row->remaining > 0) {
                throw new InvalidArgumentException("Orphan allocation for component {$id}; audit its recipe history first.");
            }
        }
        $warehouse = WarehouseStockService::centralWarehouseId();
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse)
            ->whereIn('product_variant_id', $recipe->keys())->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(quantity) AS quantity')->pluck('quantity', 'product_variant_id');
        $rows = []; $desired = []; $released = 0;
        foreach ($recipe as $id => $perBundle) {
            $id = (int) $id; $perBundle = (int) $perBundle;
            $remaining = (int) ($allocated->get($id)?->remaining ?? 0);
            if ($perBundle < 1 || $remaining < 0) throw new InvalidArgumentException("Invalid component allocation {$id}.");
            $next = $keep === null ? $remaining : $keep * $perBundle;
            if ($next > 1000000 || $next > $remaining) {
                throw new InvalidArgumentException("Cannot keep {$keep} bundles: component {$id} has {$remaining} units, needs {$next}.");
            }
            $amount = $remaining - $next;
            $desired[$id] = $next; $released += $amount;
            $rows[] = [$id, $perBundle, (int) ($stock[$id] ?? 0), $remaining,
                $keep === null ? 'not specified' : $next,
                $keep === null ? 'not specified' : $amount];
        }
        // Include all displayed before-balances; a stale/repeated browser submission cannot release a new balance.
        $token = hash('sha256', json_encode([$bundleVariantId, $keep, $warehouse, $rows], JSON_THROW_ON_ERROR));
        return ['warehouse_id' => $warehouse, 'desired' => $desired,
            'released_total' => $released, 'rows' => $rows, 'token' => $token];
    }

    /** Also used to deny archiving while an order/hold or physical stock remains. */
    public function assertSafeToRelease(int $bundleVariantId): void
    {
        $this->assertNoActiveReservations($bundleVariantId);
        if (WarehouseStock::query()->where('product_variant_id', $bundleVariantId)->where('quantity', '>', 0)->exists()) {
            throw new InvalidArgumentException('Finished physical stock must be reconciled separately.');
        }
    }

    /** Existing preinvoices and Site holds retain their original recipe and stock. */
    public function assertNoActiveReservations(int $bundleVariantId): void
    {
        if ((int) app(ReservationQueryService::class)
            ->quantitiesByVariant(variantIds: [$bundleVariantId])->get($bundleVariantId, 0) > 0) {
            throw new InvalidArgumentException('Active preinvoice reservations must be resolved first.');
        }
        // A hold line is the canonical sold SKU even for older reservations
        // whose JSON snapshot did not use bundle_allocated_lines.
        if (Schema::hasTable('site_stock_holds') && Schema::hasTable('site_stock_hold_lines')
            && DB::table('site_stock_hold_lines as line')
                ->join('site_stock_holds as hold', 'hold.id', '=', 'line.hold_id')
                ->whereIn('hold.status', ['held', 'payment_confirmed'])
                ->where('line.variant_id', $bundleVariantId)->exists()) {
            throw new InvalidArgumentException('Active Site bundle holds must be resolved first.');
        }
        if (Schema::hasTable('site_stock_holds') && Schema::hasColumn('site_stock_holds', 'bundle_allocated_lines')
            && DB::table('site_stock_holds')
            ->whereIn('status', ['held', 'payment_confirmed'])
            ->whereRaw('JSON_CONTAINS_PATH(bundle_allocated_lines, \'one\', ?) = 1', ['$.' . '"' . $bundleVariantId . '"'])
            ->exists()) {
            throw new InvalidArgumentException('Active Site bundle holds must be resolved first.');
        }
    }
}
