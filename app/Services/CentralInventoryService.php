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
        return max(0, (int) $this->findActiveVariant($variantId)->stock);
    }

    public function assertVariantAvailable(int $variantId, int $requiredQuantity): void
    {
        if ($requiredQuantity <= 0) {
            return;
        }

        $variant = $this->findActiveVariant($variantId);
        $available = max(0, (int) $variant->stock);

        if ($available < $requiredQuantity) {
            throw ValidationException::withMessages([
                'products' => $this->insufficientStockMessage($variant, $available, $requiredQuantity),
            ]);
        }
    }

    private function findActiveVariant(int $variantId): ProductVariant
    {
        try {
            $variant = ProductVariant::query()
                ->with('product:id,name,code,short_barcode,sku,barcode')
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

        return $variant;
    }

    private function insufficientStockMessage(ProductVariant $variant, int $available, int $requiredQuantity): string
    {
        $product = $variant->product;
        $productName = trim((string) ($product?->name ?? '')) ?: 'کالای بدون نام';
        $productCode = trim((string) (
            $product?->code
            ?: $product?->short_barcode
            ?: $product?->sku
            ?: $product?->barcode
            ?: ''
        ));
        $variantName = trim((string) (
            $variant->variant_name
            ?: $variant->variety_name
            ?: ''
        ));
        $variantCode = trim((string) (
            $variant->variant_code
            ?: $variant->variety_code
            ?: ''
        ));

        $identity = "کالا: {$productName}";
        $identity .= $productCode !== ''
            ? " | کد کالا: {$productCode}"
            : " | شناسه کالا: {$variant->product_id}";

        if ($variantName !== '') {
            $identity .= " | تنوع: {$variantName}";
        }

        if ($variantCode !== '') {
            $identity .= " | کد تنوع: {$variantCode}";
        }

        return self::INSUFFICIENT_STOCK_MESSAGE
            . " {$identity} | موجودی: {$available} | درخواست: {$requiredQuantity}";
    }
}
