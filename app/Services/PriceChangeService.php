<?php

namespace App\Services;

use App\Models\Category;
use App\Models\PriceChangeDocument;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PriceChangeService
{
    public function buildPreview(array $payload, ?int $perPage = null): Collection
    {
        $variants = $this->variantsForScope($payload)
            ->with(['product:id,name,sku,code,category_id', 'modelList:id,name'])
            ->orderBy('product_id')
            ->orderBy('id')
            ->when($perPage, fn ($q) => $q->limit($perPage))
            ->get()
            ->unique('id')
            ->values();

        return $variants->map(fn (ProductVariant $variant) => $this->previewRow($variant, $payload));
    }

    public function previewSummary(array $payload): array
    {
        $items = $this->buildPreview($payload);
        $valid = $items->filter(fn ($item) => blank($item['error']));
        $oldTotal = (int) $valid->sum('old_price');
        $newTotal = (int) $valid->sum('new_price');

        return [
            'products_count' => $items->pluck('product_id')->unique()->count(),
            'variants_count' => $items->count(),
            'valid_count' => $valid->count(),
            'errors_count' => $items->count() - $valid->count(),
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'average_percent' => $oldTotal > 0 ? round((($newTotal - $oldTotal) / $oldTotal) * 100, 2) : null,
            'large_scope_warning' => $items->count() > 500,
        ];
    }

    public function scopeCounts(array $payload): array
    {
        $base = $this->variantsForScope($payload);
        $all = $this->variantsForScope($payload, ignoreVariantFilters: true);

        return [
            'products_count' => (clone $base)->distinct('product_variants.product_id')->count('product_variants.product_id'),
            'variants_count' => (clone $base)->distinct('product_variants.id')->count('product_variants.id'),
            'active_variants_count' => (clone $all)->where('product_variants.is_active', true)->distinct('product_variants.id')->count('product_variants.id'),
            'invalid_variants_count' => max(0, (clone $all)->distinct('product_variants.id')->count('product_variants.id') - (clone $base)->distinct('product_variants.id')->count('product_variants.id')),
        ];
    }

    public function calculateNewPrice(int $oldPrice, string $changeType, mixed $changeValue, string $roundingMode): int
    {
        $value = (float) ($changeValue ?? 0);
        $price = match ($changeType) {
            PriceChangeDocument::CHANGE_INCREASE_PERCENT => $oldPrice + ($oldPrice * $value / 100),
            PriceChangeDocument::CHANGE_DECREASE_PERCENT => $oldPrice - ($oldPrice * $value / 100),
            PriceChangeDocument::CHANGE_INCREASE_AMOUNT => $oldPrice + $value,
            PriceChangeDocument::CHANGE_DECREASE_AMOUNT => $oldPrice - $value,
            PriceChangeDocument::CHANGE_SET_FIXED_PRICE => $value,
            default => throw new RuntimeException('نوع تغییر قیمت معتبر نیست.'),
        };

        $price = (int) round($price);
        $step = match ($roundingMode) {
            PriceChangeDocument::ROUND_1000 => 1000,
            PriceChangeDocument::ROUND_5000 => 5000,
            PriceChangeDocument::ROUND_10000 => 10000,
            PriceChangeDocument::ROUND_50000 => 50000,
            default => 0,
        };

        return $step > 0 ? (int) (round($price / $step) * $step) : $price;
    }

    public function storeDraft(array $payload, Collection $previewItems): PriceChangeDocument
    {
        return DB::transaction(function () use ($payload, $previewItems) {
            $document = PriceChangeDocument::query()->create([
                'uuid' => (string) Str::uuid(), 'code' => $this->nextCode(), 'title' => $payload['title'] ?? null,
                'scope_type' => $this->scopeType($payload), 'scope_payload' => $this->scopePayload($payload),
                'change_type' => $payload['change_type'], 'change_value' => $payload['change_value'] ?? null,
                'rounding_mode' => $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE, 'status' => PriceChangeDocument::STATUS_DRAFT,
                'items_count' => $previewItems->count(), 'created_by' => auth()->id(), 'note' => $payload['note'] ?? null,
            ]);

            $previewItems->chunk(500)->each(function (Collection $chunk) use ($document, $payload) {
                $now = now();
                $rows = $chunk->map(fn ($item) => [
                    'price_change_document_id' => $document->id, 'product_id' => $item['product_id'], 'product_variant_id' => $item['variant_id'],
                    'product_name_snapshot' => $item['product_name'], 'variant_name_snapshot' => $item['variant_name'], 'sku_snapshot' => $item['sku'],
                    'old_price' => $item['old_price'], 'new_price' => $item['new_price'], 'change_type' => $payload['change_type'],
                    'change_value' => $payload['change_value'] ?? null, 'rounding_mode' => $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all();
                DB::table('price_change_document_items')->insert($rows);
            });

            return $document;
        });
    }

    public function applyDocument(PriceChangeDocument $document, User $user): PriceChangeDocument
    {
        return DB::transaction(function () use ($document, $user) {
            $lockedDocument = PriceChangeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($lockedDocument->status !== PriceChangeDocument::STATUS_DRAFT) throw new RuntimeException('فقط سند پیش‌نویس قابل اعمال است.');
            $items = $lockedDocument->items()->lockForUpdate()->get(); $now = now();
            foreach ($items as $item) {
                $variant = ProductVariant::query()->whereKey($item->product_variant_id)->lockForUpdate()->first();
                if (! $variant || (int) $variant->sell_price !== (int) $item->old_price) throw new RuntimeException('قیمت برخی تنوع‌ها پس از ثبت پیش‌نویس تغییر کرده است. لطفاً سند جدیدی با پیش‌نمایش به‌روز ثبت کنید.');
                if ((int) $item->new_price <= 0) throw new RuntimeException('قیمت جدید باید بزرگ‌تر از صفر باشد.');
                $variant->forceFill(['sell_price' => (int) $item->new_price])->save();
                $item->forceFill(['applied_at' => $now])->save();
            }
            $lockedDocument->forceFill(['status' => PriceChangeDocument::STATUS_APPLIED, 'applied_by' => $user->id, 'applied_at' => $now])->save();
            return $lockedDocument->refresh();
        });
    }

    public function cancelDocument(PriceChangeDocument $document, User $user): PriceChangeDocument
    {
        return DB::transaction(function () use ($document, $user) {
            $document = PriceChangeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== PriceChangeDocument::STATUS_DRAFT) throw new RuntimeException('فقط سند پیش‌نویس قابل لغو است.');
            $document->forceFill(['status' => PriceChangeDocument::STATUS_CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now()])->save();
            return $document->refresh();
        });
    }

    public function variantsForScope(array $payload, bool $ignoreVariantFilters = false): Builder
    {
        $this->assertScopeIsConsistent($payload);
        $query = ProductVariant::query()->select('product_variants.*');
        $variantIds = array_values(array_unique(array_map('intval', $payload['variant_ids'] ?? [])));
        if ($variantIds) $query->whereIn('product_variants.id', $variantIds);
        elseif (! empty($payload['product_id'])) $query->where('product_variants.product_id', (int) $payload['product_id']);
        else {
            $categoryId = (int) ($payload['subcategory_id'] ?: $payload['category_id']);
            $categoryIds = Category::selfAndDescendantIds($categoryId);
            $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $categoryIds));
        }
        if (($payload['include_active_products_only'] ?? true)) $query->whereHas('product', fn ($q) => $q->where('is_sellable', true));
        if (! $ignoreVariantFilters && ($payload['include_active_variants_only'] ?? true)) $query->where('product_variants.is_active', true);
        if (! $ignoreVariantFilters && ! empty($payload['in_stock_only'])) $query->where('product_variants.stock', '>', 0);
        return $query;
    }

    public function categoryPath(Category $category): string
    {
        $names = collect([$category->name]); $parent = $category->parent;
        while ($parent) { $names->prepend($parent->name); $parent = $parent->parent; }
        return $names->implode(' / ');
    }

    private function previewRow(ProductVariant $variant, array $payload): array
    {
        $oldPrice = (int) $variant->sell_price;
        $newPrice = $this->calculateNewPrice($oldPrice, $payload['change_type'], $payload['change_value'] ?? null, $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE);
        $difference = $newPrice - $oldPrice;
        return ['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'product_name' => $variant->product?->name,
            'variant_name' => $variant->variant_name ?: $variant->variety_name ?: $variant->unique_key ?: 'تنوع اصلی',
            'sku' => $variant->variant_code ?: $variant->sku ?: $variant->product?->sku, 'old_price' => $oldPrice, 'new_price' => $newPrice,
            'difference' => $difference, 'difference_percent' => $oldPrice > 0 ? round(($difference / $oldPrice) * 100, 2) : null,
            'status' => $newPrice > $oldPrice ? 'increase' : ($newPrice < $oldPrice ? 'decrease' : 'same'),
            'error' => $newPrice <= 0 ? 'قیمت جدید باید بزرگ‌تر از صفر باشد.' : null];
    }

    public function assertScopeIsConsistent(array $payload): void
    {
        if (empty($payload['category_id'])) throw new RuntimeException('انتخاب دسته‌بندی اصلی الزامی است.');
        $categoryIds = Category::selfAndDescendantIds((int) $payload['category_id']);
        if (! empty($payload['subcategory_id']) && ! in_array((int) $payload['subcategory_id'], $categoryIds, true)) throw new RuntimeException('زیر‌دسته انتخاب‌شده زیرمجموعه دسته‌بندی اصلی نیست.');
        $effectiveIds = Category::selfAndDescendantIds((int) ($payload['subcategory_id'] ?: $payload['category_id']));
        if (! empty($payload['product_id'])) {
            $product = Product::query()->find((int) $payload['product_id']);
            if (! $product || ! in_array((int) $product->category_id, $effectiveIds, true)) throw new RuntimeException('محصول انتخاب‌شده در محدوده دسته‌بندی نیست.');
        }
        $variantIds = array_values(array_unique(array_map('intval', $payload['variant_ids'] ?? [])));
        if ($variantIds && empty($payload['product_id'])) throw new RuntimeException('برای انتخاب تنوع، ابتدا محصول را انتخاب کنید.');
        if ($variantIds) {
            $bad = ProductVariant::query()->whereIn('id', $variantIds)->where('product_id', '!=', (int) $payload['product_id'])->exists();
            $missing = ProductVariant::query()->whereIn('id', $variantIds)->count() !== count($variantIds);
            if ($bad || $missing) throw new RuntimeException('تنوع انتخاب‌شده متعلق به محصول انتخاب‌شده نیست.');
        }
    }

    private function scopeType(array $payload): string
    {
        if (! empty($payload['variant_ids'])) return PriceChangeDocument::SCOPE_VARIANT;
        if (! empty($payload['product_id'])) return PriceChangeDocument::SCOPE_PRODUCT;
        return PriceChangeDocument::SCOPE_CATEGORY;
    }

    private function scopePayload(array $payload): array
    {
        return ['root_category_id' => (int) $payload['category_id'], 'category_id' => (int) ($payload['subcategory_id'] ?: $payload['category_id']),
            'subcategory_id' => $payload['subcategory_id'] ? (int) $payload['subcategory_id'] : null, 'product_id' => $payload['product_id'] ? (int) $payload['product_id'] : null,
            'variant_ids' => array_values(array_unique(array_map('intval', $payload['variant_ids'] ?? []))),
            'include_active_products_only' => (bool) ($payload['include_active_products_only'] ?? true), 'include_active_variants_only' => (bool) ($payload['include_active_variants_only'] ?? true), 'in_stock_only' => (bool) ($payload['in_stock_only'] ?? false)];
    }

    private function nextCode(): string
    {
        return 'PC-' . now()->format('Ymd') . '-' . str_pad((string) (((int) PriceChangeDocument::query()->max('id')) + 1), 4, '0', STR_PAD_LEFT);
    }
}
