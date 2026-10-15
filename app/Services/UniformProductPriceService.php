<?php

namespace App\Services;

use App\Models\PriceChangeDocument;
use App\Models\PriceChangeDocumentItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use RuntimeException;

class UniformProductPriceService
{
    public function preview(Collection $variants, array $payload, PriceChangeService $calculator): Collection
    {
        return $variants->groupBy('product_id')->flatMap(function (Collection $group) use ($payload, $calculator) {
            $product = $group->first()->product;
            $prices = $group->pluck('sell_price')->map(fn ($price) => (int) $price)->unique();
            $master = $product->uniform_sale_price;
            $base = $master ?? ($prices->count() === 1 ? $prices->first() : null);
            $fixed = $payload['change_type'] === PriceChangeDocument::CHANGE_SET_FIXED_PRICE;
            $new = $fixed || $base > 0
                ? $calculator->calculateNewPrice((int) $base, $payload['change_type'], $payload['change_value'], $payload['rounding_mode'] ?? 'none') : 0;
            $error = ! $fixed && ! ($base > 0) ? 'قیمت تنوع‌ها یکسان یا معتبر نیست؛ ابتدا «قیمت ثابت جدید» را مشخص کنید.' : null;
            if (! $error && $new <= 0) {
                $error = 'قیمت جدید باید بزرگ‌تر از صفر باشد.';
            }
            if (! $product->is_sellable) {
                $error = 'محصول قابل فروش نیست.';
            }

            return $group->map(function (ProductVariant $variant) use ($product, $master, $new, $error) {
                $old = (int) $variant->sell_price;

                return ['product_id' => $product->id, 'variant_id' => $variant->id, 'product_name' => $product->name,
                    'variant_name' => $variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی',
                    'sku' => $variant->variant_code ?: $product->sku, 'old_price' => $old, 'new_price' => $new,
                    'difference' => $new - $old, 'difference_percent' => $old > 0 ? round(($new - $old) / $old * 100, 2) : null,
                    'change_direction' => $new > $old ? 'increase' : ($new < $old ? 'decrease' : 'same'),
                    'status' => $error ? PriceChangeDocumentItem::STATUS_INVALID : PriceChangeDocumentItem::STATUS_VALID,
                    'error_message' => $error, 'validation_details' => ['uniform_pricing' => true, 'master_before' => $master === null ? null : (int) $master]];
            });
        })->values();
    }

    /** Called inside the price document transaction. Parent locks also serialize purchase price writes. */
    public function apply(PriceChangeDocument $document, Collection $items): void
    {
        foreach ($items->groupBy('product_id')->sortKeys() as $productId => $group) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variants = $product->variants()->orderBy('id')->lockForUpdate()->get();
            $ids = $variants->pluck('id')->sort()->values()->all();
            $expectedIds = $group->pluck('product_variant_id')->sort()->values()->all();
            $master = $product->uniform_sale_price === null ? null : (int) $product->uniform_sale_price;
            if ($ids !== $expectedIds || $group->pluck('new_price')->unique()->count() !== 1 || ! $product->is_sellable) {
                throw new RuntimeException('تنوع‌ها یا وضعیت کالا تغییر کرده است؛ پیش‌نمایش و سند جدید ایجاد کنید.');
            }
            foreach ($group as $item) {
                $variant = $variants->firstWhere('id', $item->product_variant_id);
                if (($item->validation_details['master_before'] ?? null) !== $master || (int) $variant->sell_price !== (int) $item->old_price) {
                    throw new RuntimeException('قیمت مرجع یا تنوع‌ها تغییر کرده است؛ پیش‌نمایش و سند جدید ایجاد کنید.');
                }
            }
            $price = (int) $group->first()->new_price;
            if ($price <= 0) {
                throw new RuntimeException('قیمت فروش باید بزرگ‌تر از صفر باشد.');
            }
            // Price history remains on the document items. No stock or historical sales rows are updated.
            $product->forceFill(['uniform_sale_price' => $price, 'price' => $price])->save();
            $product->variants()->update(['sell_price' => $price, 'updated_at' => now()]);
            foreach ($group as $item) {
                $item->forceFill(['status' => PriceChangeDocumentItem::STATUS_APPLIED, 'applied_at' => now()])->save();
            }
        }
    }
}
