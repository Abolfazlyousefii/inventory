<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Read-only display of a bundle's components. Never creates invoice/stock lines. */
final class BundleContentsDisplay
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private static array $cache = [];

    public static function fromVariant(?int $variantId): array
    {
        $variantId = (int) $variantId;
        if ($variantId <= 0) return [];
        if (array_key_exists($variantId, self::$cache)) return self::$cache[$variantId];

        $isBundle = DB::table('product_variants as bv')
            ->join('products as bp', 'bp.id', '=', 'bv.product_id')
            ->where('bv.id', $variantId)
            ->where('bp.is_bundle', true)
            ->exists();
        if (!$isBundle) return self::$cache[$variantId] = [];

        return self::$cache[$variantId] = DB::table('product_bundle_components as c')
            ->join('product_variants as v', 'v.id', '=', 'c.component_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('c.bundle_variant_id', $variantId)
            ->orderBy('c.id')
            ->get(['p.name as product_name', 'v.variant_name', 'c.quantity as quantity_per_bundle'])
            ->map(static fn ($row): array => [
                'product_name' => (string) $row->product_name,
                'variant_name' => (string) ($row->variant_name ?? ''),
                'quantity_per_bundle' => (int) $row->quantity_per_bundle,
            ])->all();
    }

    /** Batch-load current recipe labels for product lists without one query per bundle. */
    public static function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($id) => $id > 0)));
        if (!$ids) return [];

        $rows = DB::table('product_bundle_components as c')
            ->join('product_variants as bundle_variant', 'bundle_variant.id', '=', 'c.bundle_variant_id')
            ->join('products as bundle', 'bundle.id', '=', 'bundle_variant.product_id')
            ->join('product_variants as v', 'v.id', '=', 'c.component_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('bundle.id', $ids)->where('bundle.is_bundle', true)
            ->orderBy('c.id')
            ->get(['bundle.id as bundle_product_id', 'p.name as product_name',
                'v.variant_name', 'c.quantity as quantity_per_bundle']);

        $byProduct = [];
        foreach ($rows as $row) {
            $productId = (int) $row->bundle_product_id;
            $byProduct[$productId][] = [
                'product_name' => (string) $row->product_name,
                'variant_name' => (string) ($row->variant_name ?? ''),
                'quantity_per_bundle' => (int) $row->quantity_per_bundle,
            ];
        }
        return $byProduct;
    }

    public static function fromProduct(?int $productId): array
    {
        $productId = (int) $productId;
        if ($productId <= 0) return [];
        $variantId = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.product_id', $productId)
            ->where('p.is_bundle', true)
            ->orderBy('v.id')->value('v.id');
        return self::fromVariant($variantId ? (int) $variantId : null);
    }

    public static function rows(?array $snapshot, ?int $variantId = null, ?int $productId = null): array
    {
        // A non-empty snapshot is authoritative: invoices must retain their as-sold recipe.
        // Older invoices may have NULL or [] because their recipe was not saved at conversion.
        $raw = is_array($snapshot) && $snapshot !== [] ? $snapshot : null;
        if ($raw === null) {
            $raw = $variantId ? self::fromVariant($variantId) : [];
            // An older invoice may reference a replaced/missing bundle variant.
            if (!$raw && $productId) $raw = self::fromProduct($productId);
        }
        if (!is_array($raw)) return [];
        $rows = [];
        foreach ($raw as $part) {
            if (!is_array($part)) continue;
            $quantity = (int) ($part['quantity_per_bundle'] ?? $part['quantity'] ?? 0);
            if ($quantity <= 0) continue;
            $name = trim((string) ($part['product_name'] ?? $part['name'] ?? ''));
            $rows[] = [
                'name' => $name !== '' ? $name : 'کالا',
                'variant' => trim((string) ($part['variant_name'] ?? $part['variant'] ?? '')),
                'quantity' => $quantity,
            ];
        }
        return $rows;
    }
}
