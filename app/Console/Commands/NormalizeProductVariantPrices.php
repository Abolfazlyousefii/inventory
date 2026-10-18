<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductVariantPriceNormalizationService;
use Illuminate\Console\Command;

class NormalizeProductVariantPrices extends Command
{
    protected $signature = 'products:normalize-variant-prices {--product= : Limit to one product ID} {--apply : Save changes (otherwise preview only)}';

    protected $description = 'Align positive selling prices of valid, active, sales-enabled variants to the highest eligible variant price.';

    public function handle(ProductVariantPriceNormalizationService $normalizer): int
    {
        $productId = $this->option('product');
        if ($productId !== null && (! ctype_digit((string) $productId) || (int) $productId <= 0)) {
            $this->error('--product must be a positive product ID.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $changed = 0;
        $products = 0;

        Product::query()
            ->when($productId !== null, fn ($query) => $query->whereKey((int) $productId))
            ->select(['id', 'models', 'has_colors'])
            ->chunkById(100, function ($batch) use ($normalizer, $apply, &$changed, &$products): void {
                foreach ($batch as $product) {
                    $productId = (int) $product->id;
                    if ($apply) {
                        $count = $normalizer->normalize($productId);
                    } else {
                        $eligible = $product->validVariants()
                            ->where('sales_enabled', true)
                            ->where('sell_price', '>', 0);
                        $highestPrice = (int) (clone $eligible)->max('sell_price');
                        $count = $highestPrice > 0
                            ? (clone $eligible)->where('sell_price', '<', $highestPrice)->count()
                            : 0;
                    }

                    if ($count > 0) {
                        $products++;
                        $changed += $count;
                    }
                }
            });

        $this->info(sprintf('%s: %d variants in %d products.', $apply ? 'Updated' : 'Would update', $changed, $products));

        return self::SUCCESS;
    }
}
