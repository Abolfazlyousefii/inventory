<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductDeactivationDocumentItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductSalesStatusService
{
    /** @param array<int, int|string> $variantIds */
    public function change(int $productId, string $action, string $scope, array $variantIds, string $reasonType, ?string $reasonText, User $actor): ProductDeactivationDocument
    {
        return DB::transaction(function () use ($productId, $action, $scope, $variantIds, $reasonType, $reasonText, $actor): ProductDeactivationDocument {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            $variants = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->lockForUpdate()->get();
            $selectedIds = collect($variantIds)->map(fn ($id) => (int) $id)->unique();

            if ($scope === ProductDeactivationDocument::SCOPE_VARIANTS && $selectedIds->isEmpty()) {
                throw ValidationException::withMessages(['variant_ids' => 'حداقل یک تنوع را انتخاب کنید.']);
            }

            $targets = $scope === ProductDeactivationDocument::SCOPE_PRODUCT
                ? $variants->where('is_active', true)
                : $variants->whereIn('id', $selectedIds);

            if ($targets->count() !== ($scope === ProductDeactivationDocument::SCOPE_VARIANTS ? $selectedIds->count() : $targets->count())) {
                throw ValidationException::withMessages(['variant_ids' => 'یک یا چند تنوع به این کالا تعلق ندارد.']);
            }

            if ($action === ProductDeactivationDocument::ACTION_ACTIVATE && $targets->contains(fn (ProductVariant $variant) => ! $variant->is_active)) {
                throw ValidationException::withMessages(['variant_ids' => 'این تنوع از نظر ساختاری غیرفعال است و ابتدا باید ساختار کالا اصلاح شود.']);
            }

            $desired = $action === ProductDeactivationDocument::ACTION_ACTIVATE;
            $currentAggregate = $variants->contains(
                fn (ProductVariant $variant) => (bool) $variant->is_active && (bool) $variant->sales_enabled
            );

            if ($scope === ProductDeactivationDocument::SCOPE_PRODUCT && $desired) {
                $latestItems = $this->latestStatusItems($targets->pluck('id'));
                $targets = $targets->filter(function (ProductVariant $variant) use ($latestItems): bool {
                    if ($variant->sales_enabled) {
                        return false;
                    }

                    return $this->isRestorableByProductActivation($latestItems->get($variant->id));
                });
            } else {
                $targets = $targets->filter(fn (ProductVariant $variant) => (bool) $variant->sales_enabled !== $desired);
            }

            if ($targets->isEmpty()) {
                if ($scope === ProductDeactivationDocument::SCOPE_PRODUCT && $desired && ! $currentAggregate) {
                    $activeCount = $variants->where('is_active', true)->count();
                    if ($activeCount === 0) {
                        throw ValidationException::withMessages([
                            'action_type' => 'این کالا هیچ تنوع ساختاری فعالی ندارد؛ ابتدا ساختار کالا را بررسی کنید.',
                        ]);
                    }

                    throw ValidationException::withMessages([
                        'action_type' => 'برای این کالا تنوع قابل‌بازیابی از سابقه غیرفعال‌سازی کل کالا پیدا نشد. اگر این تنوع‌ها عمداً به‌صورت مستقل غیرفعال شده‌اند، از «تنوع‌های مشخص» آن‌ها را فعال کنید.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'action_type' => 'وضعیت واقعی فروش از قبل همین مقدار است؛ سند تکراری ایجاد نشد.',
                ]);
            }

            $document = ProductDeactivationDocument::create([
                'document_number' => 'TMP-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'action_type' => $action,
                'scope_type' => $scope,
                'deactivation_type' => $scope === ProductDeactivationDocument::SCOPE_PRODUCT ? 'product' : 'variant',
                'product_id' => $product->id,
                'variant_id' => $scope === ProductDeactivationDocument::SCOPE_VARIANTS && $targets->count() === 1 ? $targets->first()->id : null,
                'items_count' => 0,
                'reason_type' => $reasonType,
                'reason_text' => $reasonText ?? '',
                'product_name_snapshot' => $product->name,
                'variant_name_snapshot' => $targets->count() === 1 ? $targets->first()->variant_name : null,
                'created_by' => $actor->id,
            ]);
            $document->update(['document_number' => 'SS-'.now()->format('Ymd').'-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($targets as $variant) {
                ProductDeactivationDocumentItem::create([
                    'document_id' => $document->id,
                    'action_type' => $action,
                    'scope_type' => $scope,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'deactivation_type' => $scope === ProductDeactivationDocument::SCOPE_PRODUCT ? 'product' : 'variant',
                    'deactivation_status' => $desired ? 'activated' : 'deactivated',
                    'previous_sales_enabled' => $variant->sales_enabled,
                    'new_sales_enabled' => $desired,
                    'product_name_snapshot' => $product->name,
                    'variant_name_snapshot' => $variant->variant_name,
                ]);
                $variant->update(['sales_enabled' => $desired]);
                $variant->sales_enabled = $desired;
            }

            $aggregate = $variants->contains(fn (ProductVariant $variant) => (bool) $variant->is_active && (bool) $variant->sales_enabled);
            if ((bool) $product->is_sellable !== $aggregate) {
                $product->update(['is_sellable' => $aggregate]);
            }
            $document->update(['items_count' => $document->items()->count()]);

            return $document->fresh();
        }, 3);
    }
    /** @param Collection<int, int|string> $variantIds */
    private function latestStatusItems(Collection $variantIds): Collection
    {
        if ($variantIds->isEmpty()) {
            return collect();
        }

        $latestIds = ProductDeactivationDocumentItem::query()
            ->whereIn('variant_id', $variantIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('variant_id')
            ->pluck('id');

        return ProductDeactivationDocumentItem::query()
            ->whereIn('id', $latestIds)
            ->get([
                'id',
                'variant_id',
                'action_type',
                'scope_type',
                'deactivation_type',
                'previous_sales_enabled',
                'new_sales_enabled',
            ])
            ->keyBy('variant_id');
    }

    private function isRestorableByProductActivation(?ProductDeactivationDocumentItem $item): bool
    {
        if (! $item) {
            return false;
        }

        // Rows created before the sales-status migration have no reliable
        // action/scope metadata. Detect them by the nullable before/after
        // snapshots and use the original deactivation_type as the source
        // of truth. This prevents a migration default from turning an old
        // variant-level stop into a product-level stop.
        $isLegacy = $item->previous_sales_enabled === null
            && $item->new_sales_enabled === null;

        if ($isLegacy) {
            return in_array($item->deactivation_type, [
                ProductDeactivationDocument::TYPE_PRODUCT,
                ProductDeactivationDocument::TYPE_CATEGORY,
                ProductDeactivationDocument::TYPE_SUBCATEGORY,
            ], true);
        }

        return $item->action_type === ProductDeactivationDocument::ACTION_DEACTIVATE
            && in_array($item->scope_type, ProductDeactivationDocument::PRODUCT_LEVEL_SCOPES, true)
            && $item->new_sales_enabled !== true;
    }

}
