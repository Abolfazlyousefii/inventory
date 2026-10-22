<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prices are in Inventory rials; the normal bundle price includes every unit of each component. */
final class BundleDiscountService
{
    /** @param int[] $componentIds @param array<int,int> $quantitiesByVariantId */
    public function regularPrice(array $componentIds, array $quantitiesByVariantId = []): int
    {
        $ids = array_values(array_unique(array_map('intval', $componentIds)));
        if (!$ids) throw ValidationException::withMessages(['components' => 'حداقل یک تنوع انتخاب کنید.']);
        $prices = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.id', $ids)
            ->where('product_variants.is_active', 1)
            ->where('product_variants.sales_enabled', 1)
            ->where('products.is_sellable', 1)
            ->where('products.is_bundle', 0)
            ->get(['product_variants.*'])->keyBy('id');
        if ($prices->count() !== count($ids)) throw ValidationException::withMessages(['components' => 'تنوع نامعتبر یا غیرقابل فروش انتخاب شده است.']);
        $total = 0;
        foreach ($ids as $id) {
            // Apply an active component sale BEFORE the bundle's own discount.
            $price = self::effectiveComponentPrice($prices[$id]);
            $units = (int) ($quantitiesByVariantId[$id] ?? 1);
            if ($price <= 0 || $units < 1 || $units > 100000 || $price > intdiv(PHP_INT_MAX, $units)) {
                throw ValidationException::withMessages(['components' => 'قیمت یا تعداد یکی از قطعات معتبر نیست.']);
            }
            $linePrice = $price * $units;
            if ($total > PHP_INT_MAX - $linePrice) {
                throw ValidationException::withMessages(['components' => 'مجموع قیمت قطعات از حد مجاز بیشتر است.']);
            }
            $total += $linePrice;
        }
        return $total;
    }

    /** Calculate a component's currently payable base in Inventory rials. */
    public static function effectiveComponentPrice(object $variant): int
    {
        $sell = (int) ($variant->sell_price ?? 0);
        $regular = (int) ($variant->regular_price ?? $sell);
        $discount = (int) ($variant->discount_price ?? 0);
        $expiry = $variant->discount_expire_at ?? null;
        $active = !$expiry || (($expiresAt = strtotime((string) $expiry)) !== false && $expiresAt > time());

        return $active && $discount > 0 && $regular > 0 && $discount < $regular
            ? min($sell, $discount)
            : $sell;
    }

    /** @return array{regular:int,final:int,savings:int,discount_percent:int} */
    public function quote(int $regular, string $type, int $value): array
    {
        if ($regular < 1) throw ValidationException::withMessages(['components' => 'مجموع قیمت قطعات باید بیشتر از صفر باشد.']);
        if ($type === 'percent' && $value >= 0 && $value <= 99) {
            $final = $regular - (int) round($regular * $value / 100, 0, PHP_ROUND_HALF_UP);
        } elseif ($type === 'final' && $value > 0 && $value <= $regular) {
            $final = $value;
        } else {
            throw ValidationException::withMessages(['bundle_discount_value' => 'درصد تخفیف باید بین ۰ و ۹۹ باشد، یا قیمت نهایی باید بزرگ‌تر از صفر و حداکثر برابر مجموع قیمت قطعات باشد.']);
        }
        if ($final <= 0) throw ValidationException::withMessages(['bundle_discount_value' => 'قیمت نهایی باید بزرگ‌تر از صفر باشد.']);
        return ['regular' => $regular, 'final' => $final, 'savings' => $regular - $final,
            'discount_percent' => (int) round(($regular - $final) / $regular * 100, 0, PHP_ROUND_HALF_UP)];
    }

    /** Amount deducted from ONE component unit, in Inventory rials. */
    public static function componentUnitDiscount(int $price, string $type, int $value): int
    {
        if ($price < 1 || $value < 0 || !in_array($type, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages(['components' => 'مبلغ یا نوع تخفیف یکی از قطعات معتبر نیست.']);
        }
        if ($type === 'percent' && $value <= 99) {
            $discount = (int) round($price * $value / 100, 0, PHP_ROUND_HALF_UP);
        } elseif ($type === 'fixed' && $value < $price) {
            $discount = $value;
        } else {
            throw ValidationException::withMessages(['components' => 'درصد تخفیف هر قطعه باید بین ۰ تا ۹۹ باشد؛ تخفیف ریالی باید کمتر از قیمت یک عدد آن قطعه باشد.']);
        }
        return min($discount, $price - 1);
    }

    /**
     * Apply each component's per-unit discount to its current selling price, then the overall
     * bundle markdown. The regular price remains before component markdowns for invoices.
     * @param array<int,array{variant_id:int,quantity:int,discount_type?:string,discount_value?:int}> $components
     * @return array{regular:int,final:int,savings:int,discount_percent:int}
     */
    public function quoteForComponents(array $components, string $type, int $value): array
    {
        $ids = array_column($components, 'variant_id');
        $quantities = array_column($components, 'quantity', 'variant_id');
        $regular = $this->regularPrice($ids, $quantities);
        $variantPrices = DB::table('product_variants')->whereIn('id', $ids)
            ->get(['id', 'sell_price'])->keyBy('id');
        $componentSavings = 0;
        foreach ($components as $component) {
            $id = (int) $component['variant_id'];
            $price = self::effectiveComponentPrice($variantPrices[$id]);
            $perUnit = self::componentUnitDiscount(
                $price, (string) ($component['discount_type'] ?? 'percent'), (int) ($component['discount_value'] ?? 0)
            );
            $units = (int) $component['quantity'];
            if ($perUnit > 0 && $units > intdiv(PHP_INT_MAX - $componentSavings, $perUnit)) {
                throw ValidationException::withMessages(['components' => 'مجموع تخفیف قطعات بیش از حد مجاز است.']);
            }
            $componentSavings += $perUnit * $units;
        }
        $quote = $this->quote($regular - $componentSavings, $type, $value);
        $savings = $regular - $quote['final'];
        return ['regular' => $regular, 'final' => $quote['final'], 'savings' => $savings,
            'discount_percent' => (int) round($savings / $regular * 100, 0, PHP_ROUND_HALF_UP)];
    }

    /** @return array{regular:int,final:int,savings:int,discount_percent:int} */
    public function quoteForBundle(int $bundleVariantId, string $type, int $value): array
    {
        $parts = DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)
            ->get(['component_variant_id', 'quantity', 'discount_type', 'discount_value']);
        if ($parts->isEmpty()) {
            throw ValidationException::withMessages(['components' => 'بسته باید حداقل یک قطعه داشته باشد.']);
        }
        return $this->quoteForComponents($parts->map(fn ($part) => [
            'variant_id' => (int) $part->component_variant_id,
            'quantity' => (int) $part->quantity,
            'discount_type' => (string) ($part->discount_type ?? 'percent'),
            'discount_value' => (int) ($part->discount_value ?? 0),
        ])->all(), $type, $value);
    }
}
