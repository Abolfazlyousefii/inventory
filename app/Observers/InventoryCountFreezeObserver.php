<?php

namespace App\Observers;

use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Services\InventoryCountFreezeService;

class InventoryCountFreezeObserver
{
    public function saving($model): void
    {
        $service = app(InventoryCountFreezeService::class);
        if ($model instanceof InvoiceItem) {
            if (! $model->exists || $model->isDirty(['quantity', 'product_id', 'product_variant_id'])) {
                $service->assertProduct((int) $model->product_id);
            }

return;
        }
        if ($model instanceof PreinvoiceOrder) {
            if ($model->isDirty('status') && ! in_array($model->status, ['draft', 'returned_to_sales', 'reservation_expired', 'cancelled_by_finance', 'cancelled_by_warehouse'], true)) {
                foreach ($model->items()->pluck('product_id')->unique() as $id) {
                    $service->assertProduct((int) $id);
                }
            }

return;
        }
        if ($model->exists && $model->isDirty(['stock', 'reserved', 'category_id', 'product_id', 'bundle_shared_remaining', 'is_bundle', 'bundle_stock_mode'])) {
            $service->assertProduct((int) ($model instanceof Product ? $model->id : $model->product_id));
        }
    }

    public function deleting($model): void
    {
        app(InventoryCountFreezeService::class)->assertProduct((int) ($model instanceof Product ? $model->id : $model->product_id));
    }
}
