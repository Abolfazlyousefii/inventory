<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductVariantPriceNormalizationService
{
    /** @var array<int, true> */
    private static array $normalizing = [];

    public function normalize(int $productId): int
    {
        if ($productId <= 0 || isset(self::$normalizing[$productId])) {
            return 0;
        }

        self::$normalizing[$productId] = true;

        try {
            return DB::transaction(function () use ($productId): int {
                // Lock the product first, then its variants in a stable order.
                $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
                if (! $product) {
                    return 0;
                }

                // Keep the existing product-structure rules: legacy/invalid, inactive,
                // sales-disabled and zero/unset-price variants are not repriced.
                // Stock is not a price: an out-of-stock variant with an actual
                // positive selling price remains eligible for price alignment.
                $variants = $product->validVariants()
                    ->where('sales_enabled', true)
                    ->where('sell_price', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $highestPrice = max(0, (int) ($variants->max('sell_price') ?? 0));
                if ($highestPrice === 0) {
                    return 0;
                }

                $changed = 0;
                foreach ($variants as $variant) {
                    if ((int) $variant->sell_price >= $highestPrice) {
                        continue;
                    }

                    // Use Eloquent so the existing audit and site-sync observers run.
                    $variant->sell_price = $highestPrice;
                    $variant->save();
                    $changed++;
                }

                // The existing product summary is the minimum usable positive
                // variant price. After alignment, every eligible price equals
                // $highestPrice. Update only that summary field; do not recalculate
                // stock or reserved quantities as part of a price change.
                if ((int) $product->price !== $highestPrice) {
                    $product->price = $highestPrice;
                    $product->save();
                }

                return $changed;
            }, 3);
        } finally {
            // Saves above fire the observer again; nested runs must be ignored.
            unset(self::$normalizing[$productId]);
        }
    }
}
