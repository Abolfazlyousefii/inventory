<?php

namespace App\Services\Commissions;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CommissionTarget
{
    public static function resolve(string $type, int $id): Model
    {
        $model = match ($type) {
            'category' => Category::query()->find($id),
            'product' => Product::query()->find($id),
            'variant' => ProductVariant::query()->find($id),
            default => null,
        };

        if (! $model) {
            throw ValidationException::withMessages(['target_id' => 'هدف انتخاب‌شده معتبر نیست.']);
        }

        return $model;
    }

    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    public static function foreignKeys(string $type, int $id): array
    {
        return [
            'category_id' => $type === 'category' ? $id : null,
            'product_id' => $type === 'product' ? $id : null,
            'product_variant_id' => $type === 'variant' ? $id : null,
        ];
    }
}
