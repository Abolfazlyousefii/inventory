<?php

namespace App\Services;

use App\Models\Category;
use App\Models\PriceChangeDocument;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PriceChangeService
{
    public function buildPreview(array $payload): Collection
    {
        $variants = $this->variantsForScope($payload)->unique('id')->values();

        return $variants->map(function (ProductVariant $variant) use ($payload) {
            $oldPrice = (int) $variant->sell_price;
            $newPrice = $this->calculateNewPrice($oldPrice, $payload['change_type'], $payload['change_value'] ?? null, $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE);
            $difference = $newPrice - $oldPrice;

            return [
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'product_name' => $variant->product?->name,
                'variant_name' => $variant->variant_name ?: $variant->variety_name ?: $variant->unique_key ?: 'تنوع اصلی',
                'sku' => $variant->variant_code ?: $variant->sku ?: $variant->product?->sku,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'difference' => $difference,
                'difference_percent' => $oldPrice > 0 ? round(($difference / $oldPrice) * 100, 2) : null,
                'error' => $newPrice <= 0 ? 'قیمت جدید باید بزرگ‌تر از صفر باشد.' : null,
            ];
        });
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
                'uuid' => (string) Str::uuid(),
                'code' => $this->nextCode(),
                'title' => $payload['title'] ?? null,
                'scope_type' => $payload['scope_type'],
                'scope_payload' => $this->scopePayload($payload),
                'change_type' => $payload['change_type'],
                'change_value' => $payload['change_value'] ?? null,
                'rounding_mode' => $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE,
                'status' => PriceChangeDocument::STATUS_DRAFT,
                'items_count' => $previewItems->count(),
                'created_by' => auth()->id(),
                'note' => $payload['note'] ?? null,
            ]);

            foreach ($previewItems as $item) {
                $document->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['variant_id'],
                    'product_name_snapshot' => $item['product_name'],
                    'variant_name_snapshot' => $item['variant_name'],
                    'sku_snapshot' => $item['sku'],
                    'old_price' => $item['old_price'],
                    'new_price' => $item['new_price'],
                    'change_type' => $payload['change_type'],
                    'change_value' => $payload['change_value'] ?? null,
                    'rounding_mode' => $payload['rounding_mode'] ?? PriceChangeDocument::ROUND_NONE,
                ]);
            }

            return $document;
        });
    }

    public function applyDocument(PriceChangeDocument $document, User $user): PriceChangeDocument
    {
        return DB::transaction(function () use ($document, $user) {
            $lockedDocument = PriceChangeDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($lockedDocument->status !== PriceChangeDocument::STATUS_DRAFT) {
                throw new RuntimeException('فقط سند پیش‌نویس قابل اعمال است.');
            }

            $items = $lockedDocument->items()->lockForUpdate()->get();
            $now = now();
            foreach ($items as $item) {
                $variant = ProductVariant::query()->whereKey($item->product_variant_id)->lockForUpdate()->first();
                if (! $variant || (int) $variant->sell_price !== (int) $item->old_price) {
                    throw new RuntimeException('قیمت برخی تنوع‌ها پس از ثبت پیش‌نویس تغییر کرده است. لطفاً سند را دوباره پیش‌نمایش و ثبت کنید.');
                }
                if ((int) $item->new_price <= 0) {
                    throw new RuntimeException('قیمت جدید باید بزرگ‌تر از صفر باشد.');
                }
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
            if ($document->status !== PriceChangeDocument::STATUS_DRAFT) {
                throw new RuntimeException('فقط سند پیش‌نویس قابل لغو است.');
            }
            $document->forceFill(['status' => PriceChangeDocument::STATUS_CANCELLED, 'cancelled_by' => $user->id, 'cancelled_at' => now()])->save();
            return $document->refresh();
        });
    }

    private function variantsForScope(array $payload): Collection
    {
        $query = ProductVariant::query()->with('product:id,name,sku,category_id')->active();
        return match ($payload['scope_type']) {
            PriceChangeDocument::SCOPE_CATEGORY => $query->whereHas('product', fn ($q) => $q->whereIn('category_id', Category::selfAndDescendantIds((int) $payload['category_id'])))->get(),
            PriceChangeDocument::SCOPE_PRODUCT => $query->where('product_id', (int) $payload['product_id'])->get(),
            PriceChangeDocument::SCOPE_VARIANT => $query->whereKey((int) $payload['variant_id'])->get(),
            PriceChangeDocument::SCOPE_MANUAL => $query->whereIn('id', array_map('intval', $payload['variant_ids'] ?? []))->get(),
            default => collect(),
        };
    }

    private function scopePayload(array $payload): array
    {
        return match ($payload['scope_type']) {
            PriceChangeDocument::SCOPE_CATEGORY => ['category_id' => (int) $payload['category_id']],
            PriceChangeDocument::SCOPE_PRODUCT => ['product_id' => (int) $payload['product_id']],
            PriceChangeDocument::SCOPE_VARIANT => ['variant_id' => (int) $payload['variant_id']],
            PriceChangeDocument::SCOPE_MANUAL => ['variant_ids' => array_values(array_map('intval', $payload['variant_ids'] ?? []))],
            default => [],
        };
    }

    private function nextCode(): string
    {
        $lastId = (int) PriceChangeDocument::query()->max('id');
        return 'PC-' . now()->format('Ymd') . '-' . str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
