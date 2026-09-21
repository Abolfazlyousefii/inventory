<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Owns the reserved projection caches; never mutates physical stock or reservation lifecycle rows. */
class ReservationProjectionService
{
    public function __construct(private readonly ReservationQueryService $reservations)
    {
    }

    public function inspect(array $productIds = [], ?CarbonInterface $at = null): array
    {
        return $this->buildReport($this->normalizeIds($productIds), $at ?? now(), false);
    }

    public function rebuild(array $productIds, ?CarbonInterface $at = null): array
    {
        $productIds = $this->normalizeIds($productIds);
        if ($productIds === []) {
            return $this->emptyReport();
        }

        return DB::transaction(function () use ($productIds, $at): array {
            $at ??= now();

            DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $variantIds = DB::table('product_variants')
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            DB::table('preinvoice_draft_reservations')
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $report = $this->buildReport($productIds, $at, true, $variantIds);
            $now = now();
            foreach ($report['variants'] as $row) {
                if ($row['difference'] === 0) {
                    continue;
                }
                DB::table('product_variants')->where('id', $row['variant_id'])->update([
                    'reserved' => $row['expected_reserved'],
                    'updated_at' => $now,
                ]);
            }
            foreach ($report['products'] as $row) {
                if ($row['difference'] === 0) {
                    continue;
                }
                DB::table('products')->where('id', $row['product_id'])->update([
                    'reserved' => $row['expected_reserved'],
                    'updated_at' => $now,
                ]);
            }

            $report['summary']['reserved_difference_after'] = 0;

            return $report;
        }, 3);
    }

    private function buildReport(array $productIds, CarbonInterface $at, bool $locked, array $lockedVariantIds = []): array
    {
        $productsQuery = DB::table('products')->orderBy('id');
        if ($productIds !== []) {
            $productsQuery->whereIn('id', $productIds);
        }
        $products = $productsQuery->get(['id', 'name', 'reserved']);
        $resolvedProductIds = $products->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $variantsQuery = DB::table('product_variants')->whereIn('product_id', $resolvedProductIds)->orderBy('id');
        if ($locked && $lockedVariantIds !== []) {
            $variantsQuery->whereIn('id', $lockedVariantIds);
        }
        $variants = $variantsQuery->get(['id', 'product_id', 'variant_name', 'variant_code', 'reserved']);
        $variantIds = $variants->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $expected = $this->reservations->quantitiesByVariant(variantIds: $variantIds, at: $at);

        $variantRows = [];
        $productTotals = array_fill_keys($resolvedProductIds, 0);
        foreach ($variants as $variant) {
            $variantId = (int) $variant->id;
            $productId = (int) $variant->product_id;
            $before = (int) $variant->reserved;
            $expectedReserved = (int) ($expected[$variantId] ?? 0);
            $productTotals[$productId] = ($productTotals[$productId] ?? 0) + $expectedReserved;
            $variantRows[$variantId] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'variant_name' => (string) ($variant->variant_name ?? ''),
                'variant_code' => (string) ($variant->variant_code ?? ''),
                'reserved_before' => $before,
                'expected_reserved' => $expectedReserved,
                'difference' => $expectedReserved - $before,
            ];
        }

        $productRows = [];
        foreach ($products as $product) {
            $productId = (int) $product->id;
            $before = (int) $product->reserved;
            $expectedReserved = (int) ($productTotals[$productId] ?? 0);
            $productRows[$productId] = [
                'product_id' => $productId,
                'product_name' => (string) ($product->name ?? ''),
                'reserved_before' => $before,
                'expected_reserved' => $expectedReserved,
                'difference' => $expectedReserved - $before,
            ];
        }

        $differenceBefore = array_sum(array_map(fn (array $row): int => abs($row['difference']), $variantRows))
            + array_sum(array_map(fn (array $row): int => abs($row['difference']), $productRows));

        return [
            'summary' => [
                'variants_checked' => count($variantRows),
                'variants_changed' => count(array_filter($variantRows, fn (array $row): bool => $row['difference'] !== 0)),
                'products_changed' => count(array_filter($productRows, fn (array $row): bool => $row['difference'] !== 0)),
                'reserved_difference_before' => $differenceBefore,
                'reserved_difference_after' => $locked ? $differenceBefore : $differenceBefore,
                'warehouse_stock_changed' => false,
                'stock_movement_created' => false,
            ],
            'variants' => $variantRows,
            'products' => $productRows,
        ];
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function emptyReport(): array
    {
        return [
            'summary' => [
                'variants_checked' => 0, 'variants_changed' => 0, 'products_changed' => 0,
                'reserved_difference_before' => 0, 'reserved_difference_after' => 0,
                'warehouse_stock_changed' => false, 'stock_movement_created' => false,
            ],
            'variants' => [],
            'products' => [],
        ];
    }
}
