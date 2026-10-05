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

            // Product activation applies to every structurally active variant,
            // including variants disabled by the retired per-variant workflow.
            $targets = $targets->filter(fn (ProductVariant $variant) => (bool) $variant->sales_enabled !== $desired);

            if ($targets->isEmpty()) {
                if ($scope === ProductDeactivationDocument::SCOPE_PRODUCT && $desired && ! $currentAggregate) {
                    $activeCount = $variants->where('is_active', true)->count();
                    if ($activeCount === 0) {
                        throw ValidationException::withMessages([
                            'action_type' => 'این کالا هیچ تنوع ساختاری فعالی ندارد؛ ابتدا ساختار کالا را بررسی کنید.',
                        ]);
                    }
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
            $product->update([
                'is_sellable' => $aggregate,
                'inventory_to_site_synced' => false,
                'site_to_inventory_verified' => false,
            ]);
            $document->update(['items_count' => $document->items()->count()]);

            return $document->fresh();
        }, 3);
    }
}
