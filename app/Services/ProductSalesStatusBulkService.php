<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductDeactivationDocumentItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProductSalesStatusBulkService
{
    public const MAX_PRODUCTS = 500;

    /** @param array<int, int|string> $productIds */
    public function resolveProductIds(string $scope, ?int $categoryId, ?int $subcategoryId, array $productIds): array
    {
        if ($scope === ProductDeactivationDocument::SCOPE_MULTIPLE_PRODUCTS) {
            $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['product_ids' => 'حداقل یک کالا را انتخاب کنید.']);
            }
            if ($ids->count() > self::MAX_PRODUCTS) {
                throw ValidationException::withMessages(['product_ids' => 'حداکثر ۵۰۰ کالا در هر عملیات مجاز است.']);
            }
            if (Product::query()->whereKey($ids)->count() !== $ids->count()) {
                throw ValidationException::withMessages(['product_ids' => 'یک یا چند کالای انتخاب‌شده معتبر نیست.']);
            }

            return $ids->all();
        }

        $targetCategory = $scope === ProductDeactivationDocument::SCOPE_CATEGORY ? $categoryId : $subcategoryId;
        if (! $targetCategory) {
            throw ValidationException::withMessages(['category_id' => 'انتخاب دسته‌بندی الزامی است.']);
        }
        if ($scope === ProductDeactivationDocument::SCOPE_SUBCATEGORY) {
            $valid = DB::table('categories')->where('id', $targetCategory)->where('parent_id', $categoryId)->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['subcategory_id' => 'زیردسته انتخاب‌شده متعلق به دسته‌بندی موردنظر نیست.']);
            }
        }

        $categoryIds = $scope === ProductDeactivationDocument::SCOPE_CATEGORY
            ? $this->selfAndDescendantIds($targetCategory)
            : [$targetCategory];
        $ids = Product::query()->whereIn('category_id', $categoryIds)->orderBy('id')->limit(self::MAX_PRODUCTS + 1)->pluck('id');
        if ($ids->count() > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['scope_type' => 'این محدوده بیش از ۵۰۰ کالا دارد؛ محدوده کوچک‌تری انتخاب کنید.']);
        }

        return $ids->map(fn ($id) => (int) $id)->all();
    }

    /** @param array<int, int> $productIds */
    public function preview(array $productIds, string $action, string $scope): array
    {
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->get(['id', 'name', 'is_sellable']);
        $variants = ProductVariant::query()->whereIn('product_id', $productIds)->orderBy('product_id')->orderBy('id')->get(['id', 'product_id', 'variant_name', 'is_active', 'sales_enabled']);

        return $this->analyse($products, $variants, $action, $scope);
    }

    /** @param array<int, int> $productIds */
    public function execute(array $productIds, string $action, string $scope, string $reasonType, ?string $reasonText, User $actor, string $previewToken): ProductDeactivationDocument
    {
        return DB::transaction(function () use ($productIds, $action, $scope, $reasonType, $reasonText, $actor, $previewToken): ProductDeactivationDocument {
            $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            $products = Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'name', 'is_sellable']);
            $variants = ProductVariant::query()->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('id')->lockForUpdate()->get(['id', 'product_id', 'variant_name', 'is_active', 'sales_enabled']);
            $analysis = $this->analyse($products, $variants, $action, $scope);

            if (! hash_equals($analysis['preview_token'], $previewToken)) {
                throw ValidationException::withMessages(['preview_token' => 'پیش‌نمایش منقضی شده است؛ دوباره پیش‌نمایش بگیرید.']);
            }
            if ($analysis['effective_changes'] === 0) {
                throw ValidationException::withMessages(['action_type' => 'هیچ موردی برای تغییر وضعیت وجود ندارد.']);
            }

            $firstProduct = $products->first();
            $document = ProductDeactivationDocument::create([
                'document_number' => 'TMP-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'action_type' => $action,
                'scope_type' => $scope,
                'deactivation_type' => 'product',
                'product_id' => $firstProduct->id,
                'variant_id' => null,
                'items_count' => count($analysis['effective_variant_ids']),
                'reason_type' => $reasonType,
                'reason_text' => $reasonText ?? '',
                'product_name_snapshot' => $products->count() === 1 ? $firstProduct->name : $products->count().' کالا',
                'variant_name_snapshot' => null,
                'created_by' => $actor->id,
            ]);
            $document->update(['document_number' => 'SS-'.now()->format('Ymd').'-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            $desired = $action === ProductDeactivationDocument::ACTION_ACTIVATE;
            $effective = $variants->whereIn('id', $analysis['effective_variant_ids']);
            $productNames = $products->pluck('name', 'id');
            $now = now();
            foreach ($effective->chunk(200) as $chunk) {
                ProductDeactivationDocumentItem::query()->insert($chunk->map(fn (ProductVariant $variant) => [
                    'document_id' => $document->id,
                    'action_type' => $action,
                    'scope_type' => $scope,
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'deactivation_type' => 'product',
                    'deactivation_status' => $desired ? 'activated' : 'deactivated',
                    'previous_sales_enabled' => $variant->sales_enabled,
                    'new_sales_enabled' => $desired,
                    'product_name_snapshot' => $productNames[$variant->product_id],
                    'variant_name_snapshot' => $variant->variant_name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            ProductVariant::query()->whereIn('id', $analysis['effective_variant_ids'])->update(['sales_enabled' => $desired, 'updated_at' => $now]);
            foreach ($products as $product) {
                $aggregate = $variants->where('product_id', $product->id)->contains(fn (ProductVariant $variant) => $variant->is_active && (in_array($variant->id, $analysis['effective_variant_ids'], true) ? $desired : $variant->sales_enabled));
                if ((bool) $product->is_sellable !== $aggregate) {
                    $product->update(['is_sellable' => $aggregate]);
                } else {
                    $this->markSyncPending($product->id);
                }
            }

            return $document->fresh('items');
        }, 3);
    }

    private function analyse(Collection $products, Collection $variants, string $action, string $scope): array
    {
        $active = $variants->where('is_active', true);
        $desired = $action === ProductDeactivationDocument::ACTION_ACTIVATE;
        $latest = collect();
        if ($desired && $active->isNotEmpty()) {
            $latestIds = ProductDeactivationDocumentItem::query()->whereIn('variant_id', $active->pluck('id'))->selectRaw('MAX(id) as id')->groupBy('variant_id')->pluck('id');
            $latest = ProductDeactivationDocumentItem::query()->whereIn('id', $latestIds)->get(['variant_id', 'action_type', 'scope_type', 'deactivation_type', 'new_sales_enabled'])->keyBy('variant_id');
        }

        $effective = $active->filter(function (ProductVariant $variant) use ($desired, $latest): bool {
            if (! $desired) {
                return (bool) $variant->sales_enabled;
            }
            if ($variant->sales_enabled) {
                return false;
            }
            $event = $latest->get($variant->id);

            if (! $event) {
                return false;
            }

            $isDeactivation = $event->action_type === ProductDeactivationDocument::ACTION_DEACTIVATE
                || ($event->action_type === null && $event->new_sales_enabled !== true);

            if (! $isDeactivation) {
                return false;
            }

            return in_array($event->scope_type, ProductDeactivationDocument::PRODUCT_LEVEL_SCOPES, true)
                || in_array($event->deactivation_type, [
                    ProductDeactivationDocument::TYPE_PRODUCT,
                    ProductDeactivationDocument::TYPE_CATEGORY,
                    ProductDeactivationDocument::TYPE_SUBCATEGORY,
                ], true);
        })->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $already = $active->filter(fn (ProductVariant $variant) => (bool) $variant->sales_enabled === $desired)->count();
        $unable = $variants->where('is_active', false)->count() + ($desired ? $active->where('sales_enabled', false)->count() - count($effective) : 0);
        $payload = ['product_ids' => $products->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(), 'action' => $action, 'scope' => $scope, 'effective_variant_ids' => $effective];

        return [
            'products_count' => $products->count(),
            'structurally_active_variants' => $active->count(),
            'effective_changes' => count($effective),
            'already_desired' => $already,
            'unable_to_change' => $unable,
            'effective_variant_ids' => $effective,
            'sample' => $active->whereIn('id', $effective)->take(20)->map(fn (ProductVariant $variant) => ['product' => $products->firstWhere('id', $variant->product_id)?->name, 'variant' => $variant->variant_name])->values(),
            'preview_token' => hash_hmac('sha256', json_encode($payload), (string) config('app.key')),
        ];
    }

    private function selfAndDescendantIds(int $categoryId): array
    {
        $rows = DB::select('WITH RECURSIVE category_tree AS (SELECT id FROM categories WHERE id = ? UNION ALL SELECT c.id FROM categories c INNER JOIN category_tree t ON c.parent_id = t.id) SELECT id FROM category_tree ORDER BY id', [$categoryId]);
        if ($rows === []) {
            throw ValidationException::withMessages(['category_id' => 'دسته‌بندی انتخاب‌شده معتبر نیست.']);
        }

        return collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function markSyncPending(int $productId): void
    {
        $fields = array_filter([
            Schema::hasColumn('products', 'inventory_to_site_synced') ? 'inventory_to_site_synced' : null,
            Schema::hasColumn('products', 'site_to_inventory_verified') ? 'site_to_inventory_verified' : null,
        ]);
        if ($fields !== []) {
            DB::table('products')->where('id', $productId)->update(array_fill_keys($fields, false));
        }
    }
}
