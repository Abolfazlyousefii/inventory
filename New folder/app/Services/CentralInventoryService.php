<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;
use Throwable;

class CentralInventoryService
{
    public const API_UNAVAILABLE_MESSAGE = 'امکان بررسی موجودی انبار مرکزی وجود ندارد. لطفاً دوباره تلاش کنید.';
    public const INSUFFICIENT_STOCK_MESSAGE = 'موجودی انبار مرکزی برای این کالا کافی نیست.';

    public function availableForVariant(int $variantId): int
    {
        try {
            $variant = ProductVariant::query()
                ->whereKey($variantId)
                ->where('is_active', true)
                ->first();
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'products' => self::API_UNAVAILABLE_MESSAGE,
            ]);
        }

        if (! $variant) {
            throw ValidationException::withMessages([
                'products' => self::API_UNAVAILABLE_MESSAGE,
            ]);
        }

        return max(0, (int) $variant->stock);
    }

    public function assertVariantAvailable(int $variantId, int $requiredQuantity): void
    {
        if ($requiredQuantity <= 0) {
            return;
        }

        $available = $this->availableForVariant($variantId);

        if ($available < $requiredQuantity) {
            throw ValidationException::withMessages([
                'products' => self::INSUFFICIENT_STOCK_MESSAGE . " موجودی: {$available} | درخواست: {$requiredQuantity}",
            ]);
        }
    }
}
