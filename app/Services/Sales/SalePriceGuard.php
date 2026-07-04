<?php

namespace App\Services\Sales;

use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class SalePriceGuard
{
    public const MESSAGE = 'برای این کالا قیمت فروش ثبت نشده است. ابتدا قیمت فروش را تکمیل کنید.';

    public function assertVariantHasSalePrice(ProductVariant $variant, ?string $field = 'items'): void
    {
        if ((int) ($variant->sell_price ?? 0) > 0) {
            return;
        }

        throw ValidationException::withMessages([
            $field ?: 'items' => self::MESSAGE,
        ]);
    }

    public function assertInvoiceUnitPrice(int $price, ?string $field = 'items'): void
    {
        if ($price > 0) {
            return;
        }

        throw ValidationException::withMessages([
            $field ?: 'items' => self::MESSAGE,
        ]);
    }
}
