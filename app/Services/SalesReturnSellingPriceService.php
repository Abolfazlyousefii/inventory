<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Services\Sync\SiteStockChangeMarker;
use Illuminate\Validation\ValidationException;

class SalesReturnSellingPriceService
{
    public static function catalogPrice(Product $product): int
    {
        return max(0, (int) ($product->uniform_sale_price ?? $product->price));
    }

    public function validateForSubmission(SalesReturnDocument $document, bool $requireSellingPrice = true): void
    {
        if (! $document->isSazehHesab()) {
            return;
        }
        $document->loadMissing('items.product');
        $proposals = [];
        foreach ($document->items as $index => $item) {
            if ((int) $item->return_quantity < 1) {
                continue;
            }
            if ((int) $item->refund_unit_price < 1) {
                throw ValidationException::withMessages(["items.$index.refund_unit_price" => 'مبلغ واحد برگشت مشتری باید بیشتر از صفر باشد.']);
            }
            if ($item->item_source !== SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT || ! $item->product) {
                continue;
            }
            if (self::catalogPrice($item->product) > 0) {
                continue;
            }
            $price = (int) $item->sell_price;
            if (! $requireSellingPrice && $price <= 0) {
                continue;
            }
            if ($price < 1 || $price > 1000000000000) {
                throw ValidationException::withMessages(["items.$index.sell_price" => 'قیمت فروش کالای بدون قیمت را به ریال وارد کنید.']);
            }
            if (isset($proposals[$item->product_id]) && $proposals[$item->product_id] !== $price) {
                throw ValidationException::withMessages(["items.$index.sell_price" => 'قیمت فروش تمام تنوع‌های یک کالا باید یکسان باشد.']);
            }
            $proposals[$item->product_id] = $price;
        }
    }

    /** Called only inside the receipt transaction, for quantities actually accepted. */
    public function finalize(SalesReturnDocument $document, ?int $actorId): void
    {
        if (! $document->isSazehHesab()) {
            return;
        }
        $groups = $document->items->filter(fn ($item) => $item->item_source === SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT
            && (int) $item->return_quantity > 0 && (int) $item->sell_price > 0
        )->groupBy('product_id')->sortKeys();
        foreach ($groups as $productId => $items) {
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            // A price approved elsewhere while this return was pending always wins.
            $price = self::catalogPrice($product);
            if ($price <= 0) {
                if ($items->pluck('sell_price')->map(fn ($value) => (int) $value)->unique()->count() !== 1) {
                    throw ValidationException::withMessages(['items' => 'قیمت پیشنهادی تنوع‌های یک کالا یکسان نیست.']);
                }
                $price = (int) $items->first()->sell_price;
            }
            if ($price > 1000000000000) {
                throw ValidationException::withMessages(['items' => 'قیمت فروش خارج از محدوده مجاز است.']);
            }
            $before = self::catalogPrice($product);
            // Same parent-first locking and bulk alignment as uniform price documents.
            $product->variants()->orderBy('id')->lockForUpdate()->get(['id']);
            $product->forceFill(['uniform_sale_price' => $price, 'price' => $price])->save();
            $changed = $product->variants()->where(fn ($q) => $q->whereNull('sell_price')->orWhere('sell_price', '<>', $price))
                ->update(['sell_price' => $price, 'updated_at' => now()]);
            if ($changed || $before !== $price) {
                SiteStockChangeMarker::mark((int) $productId);
                ActivityLog::create(['user_id' => $actorId, 'action' => 'sales_return.selling_price_finalized',
                    'subject_type' => SalesReturnDocument::class, 'subject_id' => $document->id,
                    'description' => 'تعیین قیمت فروش یکسان هنگام دریافت برگشت',
                    'properties' => ['product_id' => $productId, 'before' => $before, 'after' => $price, 'variants_updated' => $changed],
                    'occurred_at' => now()]);
            }
        }
    }
}
