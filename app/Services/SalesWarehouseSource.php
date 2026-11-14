<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Validation\ValidationException;

class SalesWarehouseSource
{
    public static function normalize(mixed $id): ?int
    {
        $id = (int) $id;
        if ($id <= 0 || $id === WarehouseStockService::centralWarehouseId()) {
            return null;
        }
        if (! Warehouse::whereKey($id)->where('type', 'showroom')->exists()) {
            throw ValidationException::withMessages(['source_warehouse_id' => 'فقط انبار مرکزی یا شوروم قابل انتخاب است.']);
        }

        return $id;
    }

    public static function showroomId(): ?int
    {
        return Warehouse::where('type', 'showroom')->where('is_active', true)->whereNull('parent_id')->orderBy('id')->value('id');
    }

    public static function label(?int $id): string
    {
        return $id ? 'تحویل از انبار شوروم' : 'انبار مرکزی';
    }

    public static function key(int $productId, int $variantId, ?int $source = null): string
    {
        return $productId.':'.$variantId.($source ? ':'.$source : '');
    }

    public static function assertReservable(int $productId, int $variantId, ?int $id): void
    {
        if (! $id) {
            return;
        }
        self::normalize($id);
        if (! Warehouse::whereKey($id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['source_warehouse_id' => 'انبار شوروم غیرفعال است.']);
        }
        ProductVariant::whereKey($variantId)->where('product_id', $productId)->lockForUpdate()->firstOrFail();
    }
}
