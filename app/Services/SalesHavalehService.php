<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\CustomerLedger;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use App\Support\DocumentCodeGenerator;
use App\Support\ActivityLogger;
use App\Support\SalesDocumentTotals;
use App\Services\Sales\SalePriceGuard;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class SalesHavalehService
{
    public function __construct(
        private readonly SalesHavalehStatusService $statusService,
        private readonly InventoryService $inventoryService,
        private readonly CustomerLedgerService $ledgerService,
        private readonly SalesHavalehHistoryService $historyService,
        private readonly CentralInventoryService $centralInventoryService,
        private readonly SalesDocumentAccessService $accessService,
        private readonly SalePriceGuard $salePriceGuard,
    ) {}

    public function updateItemsForInvoice(
        Invoice $invoice,
        array $items,
        int $userId,
        ?string $changeReason = null,
        ?string $changeNote = null
    ): Invoice {
        $changeReason = trim((string) $changeReason);
        $changeNote = $changeNote !== null ? trim((string) $changeNote) : null;

        return DB::transaction(function () use ($invoice, $items, $userId, $changeReason, $changeNote) {
            $user = auth()->user();
            if (! $this->canEditSalesHavalehItems($invoice, $user)) {
                abort(403, 'فقط فروشنده ثبت‌کننده سند، مدیر یا انبار مجاز به ویرایش اقلام است.');
            }

            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedItems = $invoice->items()->with(['product', 'variant'])->lockForUpdate()->get();
            $invoice->setRelation('items', $lockedItems);
            $itemsById = $lockedItems->keyBy('id');

            $hasChanges = $this->itemsActuallyChanged($invoice, $items);
            if (! $hasChanges) {
                throw ValidationException::withMessages([
                    'items' => 'هیچ تغییری برای ثبت در حواله فروش پیدا نشد.',
                ]);
            }
            if (blank($changeReason)) {
                throw ValidationException::withMessages([
                    'change_reason' => 'برای حذف، اضافه یا ویرایش اقلام حواله فروش، انتخاب دلیل الزامی است.',
                ]);
            }

            $this->validateRequestedStockAvailability($invoice, $items);

            $requestedIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

            foreach ($invoice->items as $item) {
                if (!in_array((int) $item->id, $requestedIds, true)) {
                    $this->adjustSaleItemStock($invoice, $item, (int) $item->quantity, StockMovement::REASON_RETURN, 'برگشت موجودی بابت حذف آیتم حواله فروش', $changeReason, $changeNote);

                    $this->historyService->log($invoice, 'item_removed', 'items', (string) $item->id, null, $this->itemChangeDescription('آیتم ' . $this->itemLabel($item) . ' حذف شد؛ ' . number_format($oldQty ?? (int) $item->quantity) . ' عدد به موجودی مرکزی برگشت.', $changeReason, $changeNote), $userId);
                    $this->logItemStockAudit($invoice, $item, (int) $item->quantity, 0, $changeReason, $changeNote, $userId);
                    $item->delete();
                }
            }

            foreach ($items as $row) {
                $itemId = (int) ($row['id'] ?? 0);
                if ($itemId <= 0) {
                    $this->addInvoiceItem($invoice, $row, $changeReason, $changeNote, $userId);
                    continue;
                }

                $newQty = (int) $row['quantity'];
                $newPrice = (int) $row['price'];

                $item = $itemsById->get($itemId);
                if (!$item) {
                    continue;
                }
                if ($newQty > 0) {
                    $this->salePriceGuard->assertInvoiceUnitPrice($newPrice, 'items');
                    if ($item->variant) {
                        $this->salePriceGuard->assertVariantHasSalePrice($item->variant, 'items');
                    }
                }

                $oldQty = (int) $item->quantity;
                $oldPrice = (int) $item->price;

                if ($newQty <= 0) {
                    $this->adjustSaleItemStock($invoice, $item, $oldQty, StockMovement::REASON_RETURN, 'برگشت موجودی بابت حذف آیتم حواله فروش', $changeReason, $changeNote);
                    $this->historyService->log($invoice, 'item_removed', 'items', (string) $item->id, null, $this->itemChangeDescription('آیتم ' . $this->itemLabel($item) . ' حذف شد؛ ' . number_format($oldQty ?? (int) $item->quantity) . ' عدد به موجودی مرکزی برگشت.', $changeReason, $changeNote), $userId);
                    $this->logItemStockAudit($invoice, $item, $oldQty, 0, $changeReason, $changeNote, $userId);
                    $item->delete();

                    continue;
                }

                $delta = $newQty - $oldQty;

                if ($delta > 0) {
                    $this->centralInventoryService->assertVariantAvailable((int) $item->variant_id, $delta);
                    $this->adjustSaleItemStock($invoice, $item, -$delta, StockMovement::REASON_SALE, 'کسر موجودی بابت افزایش تعداد آیتم حواله فروش', $changeReason, $changeNote);
                    $this->historyService->log($invoice, 'item_quantity_increased', 'quantity', (string) $oldQty, (string) $newQty, $this->itemChangeDescription('تعداد آیتم ' . $this->itemLabel($item) . ' از ' . number_format($oldQty) . ' به ' . number_format($newQty) . ' تغییر کرد؛ ' . number_format($delta) . ' عدد از موجودی مرکزی کم شد.', $changeReason, $changeNote), $userId);
                    $this->logItemStockAudit($invoice, $item, $oldQty, $newQty, $changeReason, $changeNote, $userId);
                } elseif ($delta < 0) {
                    $this->adjustSaleItemStock($invoice, $item, abs($delta), StockMovement::REASON_RETURN, 'برگشت موجودی بابت کاهش تعداد آیتم حواله فروش', $changeReason, $changeNote);
                    $this->historyService->log($invoice, 'item_quantity_decreased', 'quantity', (string) $oldQty, (string) $newQty, $this->itemChangeDescription('تعداد آیتم ' . $this->itemLabel($item) . ' از ' . number_format($oldQty) . ' به ' . number_format($newQty) . ' تغییر کرد؛ ' . number_format(abs($delta)) . ' عدد به موجودی مرکزی برگشت.', $changeReason, $changeNote), $userId);
                    $this->logItemStockAudit($invoice, $item, $oldQty, $newQty, $changeReason, $changeNote, $userId);
                }

                if ($oldPrice !== $newPrice) {
                    $this->historyService->log($invoice, 'item_price_changed', 'price', (string) $oldPrice, (string) $newPrice, $this->itemChangeDescription('قیمت آیتم ' . $this->itemLabel($item) . ' از ' . number_format($oldPrice) . ' به ' . number_format($newPrice) . ' تغییر کرد.', $changeReason, $changeNote), $userId);
                }

                $lineTotal = max(($newQty * $newPrice) - (int) ($item->line_discount_amount ?? 0), 0);
                $item->update([
                    'quantity' => $newQty,
                    'price' => $newPrice,
                    'line_total' => $lineTotal,
                ]);
            }

            $invoice->refresh()->load(['items', 'preinvoiceOrder']);
            $totals = SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price);
            $subtotal = $totals['subtotal_before_discount'];
            $newTotal = $totals['grand_total'];
            $oldTotal = (int) $invoice->total;

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $newTotal,
                'status' => Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                'status_changed_at' => now(),
                'status_changed_by' => $userId,
                'items_updated_at' => now(),
                'items_updated_by' => $userId,
            ]);



            if ($oldTotal !== $newTotal) {
                $this->historyService->log($invoice, 'amount_recalculated', 'total', (string) $oldTotal, (string) $newTotal, 'بروزرسانی مبلغ کل حواله فروش', $userId);
            }

            $this->syncLinkedPreinvoiceForFinanceReapproval($invoice->fresh(['items', 'preinvoiceOrder.items']), $userId, $changeReason, $changeNote, $oldTotal, $newTotal);

            return $invoice->fresh(['items.product', 'items.variant', 'preinvoiceOrder']);
        });
    }



    public function updateInvoiceByFinance(
        Invoice $invoice,
        array $header,
        array $items,
        int $userId,
        string $editReason
    ): Invoice {
        $editReason = trim($editReason);

        return DB::transaction(function () use ($invoice, $header, $items, $userId, $editReason) {
            if ($editReason === '') {
                throw ValidationException::withMessages(['edit_reason' => 'ثبت یادداشت ویرایش فاکتور برای مالی الزامی است.']);
            }

            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedItems = $invoice->items()->with(['product', 'variant'])->lockForUpdate()->get();
            $invoice->setRelation('items', $lockedItems);
            $itemsById = $lockedItems->keyBy('id');
            $oldTotal = (int) $invoice->total;

            $requestedIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            foreach ($lockedItems as $item) {
                if (! in_array((int) $item->id, $requestedIds, true)) {
                    $this->adjustSaleItemStock($invoice, $item, (int) $item->quantity, StockMovement::REASON_RETURN, 'برگشت موجودی بابت حذف ردیف توسط مالی', 'finance_correction', $editReason);
                    $this->historyService->log($invoice, 'finance_item_removed', 'items', (string) $item->id, null, $this->itemChangeDescription('ردیف ' . $this->itemLabel($item) . ' توسط مالی حذف شد و موجودی آن برگشت.', 'finance_correction', $editReason), $userId);
                    $item->delete();
                }
            }

            foreach (array_values($items) as $index => $row) {
                $itemId = (int) ($row['id'] ?? 0);
                if ($itemId <= 0) {
                    $this->addInvoiceItem($invoice, array_merge($row, ['sort_order' => $index + 1]), 'finance_correction', $editReason, $userId);
                    continue;
                }

                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $newQty = (int) ($row['quantity'] ?? 0);
                $newPrice = (int) ($row['price'] ?? 0);
                $newDiscount = max(0, (int) ($row['line_discount_amount'] ?? 0));
                $newVariantId = (int) ($row['variant_id'] ?? $item->variant_id);
                $newProductId = (int) ($row['product_id'] ?? $item->product_id);

                if ($newQty > 0) {
                    $this->salePriceGuard->assertInvoiceUnitPrice($newPrice, 'items');
                }

                if ($newQty <= 0) {
                    $this->adjustSaleItemStock($invoice, $item, (int) $item->quantity, StockMovement::REASON_RETURN, 'برگشت موجودی بابت حذف ردیف توسط مالی', 'finance_correction', $editReason);
                    $item->delete();
                    continue;
                }

                if ($newVariantId !== (int) $item->variant_id || $newProductId !== (int) $item->product_id) {
                    $variant = ProductVariant::query()->with('product')->whereKey($newVariantId)->where('product_id', $newProductId)->lockForUpdate()->firstOrFail();
                    $this->salePriceGuard->assertVariantHasSalePrice($variant, 'items');
                    $this->centralInventoryService->assertVariantAvailable($newVariantId, $newQty);
                    $this->adjustSaleItemStock($invoice, $item, (int) $item->quantity, StockMovement::REASON_RETURN, 'برگشت موجودی کالای قبلی بابت تغییر تنوع توسط مالی', 'finance_correction', $editReason);
                    $item->forceFill(['product_id' => (int) $variant->product_id, 'variant_id' => $newVariantId]);
                    $this->adjustSaleItemStock($invoice, $item, -$newQty, StockMovement::REASON_SALE, 'کسر موجودی کالای جدید بابت تغییر تنوع توسط مالی', 'finance_correction', $editReason);
                } else {
                    if ($newQty > 0 && $item->variant) {
                        $this->salePriceGuard->assertVariantHasSalePrice($item->variant, 'items');
                    }
                    $delta = $newQty - (int) $item->quantity;
                    if ($delta > 0) {
                        $this->centralInventoryService->assertVariantAvailable((int) $item->variant_id, $delta);
                        $this->adjustSaleItemStock($invoice, $item, -$delta, StockMovement::REASON_SALE, 'کسر موجودی بابت افزایش تعداد توسط مالی', 'finance_correction', $editReason);
                    } elseif ($delta < 0) {
                        $this->adjustSaleItemStock($invoice, $item, abs($delta), StockMovement::REASON_RETURN, 'برگشت موجودی بابت کاهش تعداد توسط مالی', 'finance_correction', $editReason);
                    }
                }

                $lineDiscount = min($newDiscount, $newQty * $newPrice);
                $item->update([
                    'quantity' => $newQty,
                    'price' => $newPrice,
                    'line_discount_amount' => $lineDiscount,
                    'line_total' => max(($newQty * $newPrice) - $lineDiscount, 0),
                    'sort_order' => $index + 1,
                ]);
            }

            $invoice->refresh()->load(['items', 'preinvoiceOrder.items']);
            $totals = SalesDocumentTotals::calculate($invoice->items, (int) ($header['discount_amount'] ?? $invoice->discount_amount), (int) ($header['shipping_price'] ?? $invoice->shipping_price));
            $invoice->update([
                'discount_amount' => (int) ($header['discount_amount'] ?? 0),
                'shipping_price' => (int) ($header['shipping_price'] ?? 0),
                'subtotal' => (int) $totals['subtotal_before_discount'],
                'total' => (int) $totals['grand_total'],
                'items_updated_at' => now(),
                'items_updated_by' => $userId,
            ]);

            $this->ledgerService->syncInvoiceDebit($invoice->fresh());
            $this->syncLinkedPreinvoiceValues($invoice->fresh(['items', 'preinvoiceOrder.items']), $userId);
            $this->historyService->log($invoice->fresh(), 'finance_invoice_edited', 'total', (string) $oldTotal, (string) $invoice->fresh()->total, 'ویرایش کامل فاکتور توسط مالی: ' . $editReason, $userId);
            ActivityLogger::log('finance_invoice_edited', $invoice->fresh(), 'فاکتور توسط مالی ویرایش شد.', ['reason' => $editReason, 'old_total' => $oldTotal, 'new_total' => (int) $invoice->fresh()->total]);

            return $invoice->fresh(['items.product', 'items.variant', 'preinvoiceOrder']);
        });
    }

    private function syncLinkedPreinvoiceValues(Invoice $invoice, int $userId): void
    {
        $order = $invoice->preinvoiceOrder()->lockForUpdate()->first();
        if (! $order) return;
        $order->items()->delete();
        foreach ($invoice->items as $item) {
            $order->items()->create([
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_total' => (int) $item->line_total,
                'sort_order' => (int) ($item->sort_order ?: 0),
                'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
            ]);
        }
        $order->update([
            'customer_name' => $invoice->customer_name,
            'customer_mobile' => $invoice->customer_mobile,
            'customer_address' => $invoice->customer_address,
            'discount_amount' => (int) $invoice->discount_amount,
            'shipping_price' => (int) $invoice->shipping_price,
            'total_price' => (int) $invoice->total,
            'items_updated_at' => now(),
            'items_updated_by' => $userId,
        ]);
    }




    public function updateItems(
        Invoice $invoice,
        array $items,
        int $userId,
        ?string $changeReason = null,
        ?string $changeNote = null
    ): Invoice {
        return $this->updateItemsForInvoice($invoice, $items, $userId, (string) $changeReason, $changeNote);
    }


    private function syncLinkedPreinvoiceForFinanceReapproval(Invoice $invoice, int $userId, ?string $reason, ?string $note, int $oldTotal, int $newTotal): void
    {
        $order = $invoice->preinvoiceOrder()->lockForUpdate()->first();
        if (! $order) {
            return;
        }

        $oldStatus = (string) $order->status;
        $order->items()->delete();
        foreach ($invoice->items as $item) {
            $order->items()->create([
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_total' => max(((int) $item->quantity * (int) $item->price) - (int) ($item->line_discount_amount ?? 0), 0),
                'sort_order' => (int) ($item->sort_order ?: 0),
                'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
            ]);
        }

        $order->update([
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'customer_mobile' => $invoice->customer_mobile,
            'customer_address' => $invoice->customer_address,
            'province_id' => $invoice->province_id,
            'city_id' => $invoice->city_id,
            'shipping_id' => $invoice->shipping_id,
            'shipping_price' => (int) $invoice->shipping_price,
            'discount_amount' => (int) $invoice->discount_amount,
            'total_price' => (int) $invoice->total,
            'status' => PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            'stock_frozen_until' => null,
            'stock_released_at' => null,
            'items_updated_at' => now(),
            'items_updated_by' => $userId,
        ]);

        CustomerLedger::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', 'debit')
            ->delete();

        $this->historyService->log($invoice, 'sent_to_finance_reapproval', 'status', null, Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 'ویرایش اقلام توسط انبار ثبت شد و سند با همان شماره برای تایید مالی مجدد ارسال شد.', $userId);
        ActivityLogger::log('invoice_warehouse_edit_finance_reapproval', $invoice->fresh(), 'فاکتور توسط انبار ویرایش و برای تایید مالی مجدد ارسال شد.', [
            'preinvoice_order_id' => $order->id,
            'old_preinvoice_status' => $oldStatus,
            'new_preinvoice_status' => PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'reason' => $reason,
            'note' => $note,
        ]);
    }

    private function itemsActuallyChanged(Invoice $invoice, array $items): bool
    {
        $itemsById = $invoice->items->keyBy('id');
        $requestedIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        foreach ($invoice->items as $item) {
            if (! in_array((int) $item->id, $requestedIds, true)) {
                return true;
            }
        }

        foreach ($items as $row) {
            $itemId = (int) ($row['id'] ?? 0);
            $newQty = (int) ($row['quantity'] ?? 0);
            $newPrice = (int) ($row['price'] ?? 0);
            $newDiscount = (int) ($row['line_discount_amount'] ?? 0);
            $newVariantId = (int) ($row['variant_id'] ?? 0);

            if ($itemId <= 0) {
                if ($newQty > 0 && (int) ($row['variant_id'] ?? 0) > 0) {
                    return true;
                }
                continue;
            }

            $item = $itemsById->get($itemId);
            if (! $item) {
                continue;
            }

            if ($newQty !== (int) $item->quantity || $newPrice !== (int) $item->price || $newDiscount !== (int) ($item->line_discount_amount ?? 0) || ($newVariantId > 0 && $newVariantId !== (int) $item->variant_id)) {
                return true;
            }
        }

        return false;
    }


    private function validateRequestedStockAvailability(Invoice $invoice, array $items): void
    {
        $currentItems = $invoice->items;
        $itemsById = $currentItems->keyBy('id');

        $oldTotals = $currentItems
            ->filter(fn (InvoiceItem $item) => (int) $item->variant_id > 0)
            ->groupBy(fn (InvoiceItem $item) => (int) $item->variant_id)
            ->map(fn ($rows) => (int) $rows->sum('quantity'));

        $newTotals = collect($items)->reduce(function (array $carry, array $row) use ($itemsById) {
            $quantity = max(0, (int) ($row['quantity'] ?? 0));
            if ($quantity <= 0) {
                return $carry;
            }

            $itemId = (int) ($row['id'] ?? 0);
            $existingItem = $itemId > 0 ? $itemsById->get($itemId) : null;
            $variantId = (int) ($row['variant_id'] ?? ($existingItem?->variant_id ?? 0));

            if ($variantId > 0) {
                $carry[$variantId] = ($carry[$variantId] ?? 0) + $quantity;
            }

            return $carry;
        }, []);

        $variantIds = collect(array_keys($newTotals))
            ->merge($oldTotals->keys())
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            return;
        }

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $centralId = WarehouseStockService::centralWarehouseId();
        $warehouseStocks = WarehouseStock::query()
            ->where('warehouse_id', $centralId)
            ->whereIn('product_variant_id', $variantIds)
            ->lockForUpdate()
            ->pluck('quantity', 'product_variant_id');

        foreach ($variantIds as $variantId) {
            $oldTotal = (int) ($oldTotals[$variantId] ?? 0);
            $newTotal = (int) ($newTotals[$variantId] ?? 0);
            $delta = $newTotal - $oldTotal;

            if ($delta <= 0) {
                continue;
            }

            $freeStock = max(0, (int) ($warehouseStocks[$variantId] ?? 0));
            if ($delta <= $freeStock) {
                continue;
            }

            $variant = $variants->get($variantId);
            $label = trim(($variant?->product?->name ?: ('#' . $variantId)) . ($variant?->variant_name ? (' / ' . $variant->variant_name) : ''));
            $maxAllowed = $oldTotal + $freeStock;

            throw ValidationException::withMessages([
                'items' => "موجودی آزاد کالای {$label} کافی نیست. موجودی آزاد: {$freeStock} عدد، حداکثر قابل ثبت برای این حواله: {$maxAllowed} عدد.",
            ]);
        }
    }

    private function addInvoiceItem(Invoice $invoice, array $row, ?string $reason, ?string $note, ?int $userId): void
    {
        $variantId = (int) ($row['variant_id'] ?? 0);
        $quantity = (int) ($row['quantity'] ?? 0);
        $price = (int) ($row['price'] ?? 0);

        if ($quantity <= 0) {
            return;
        }
        if ($variantId <= 0) {
            abort(422, 'برای اضافه کردن کالا، انتخاب تنوع کالا الزامی است.');
        }

        $variant = ProductVariant::query()->with('product')->whereKey($variantId)->lockForUpdate()->firstOrFail();
        $this->salePriceGuard->assertInvoiceUnitPrice($price, 'items');
        $this->salePriceGuard->assertVariantHasSalePrice($variant, 'items');
        if (! $variant->is_active) {
            abort(422, 'تنوع کالای انتخاب‌شده فعال نیست.');
        }
        if ($variant->product && $variant->product->is_sellable === false) {
            abort(422, 'کالای انتخاب‌شده قابل فروش نیست.');
        }

        $this->centralInventoryService->assertVariantAvailable($variantId, $quantity);

        $existingItem = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->where('product_id', (int) $variant->product_id)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($existingItem) {
            $existingItem->loadMissing(['product', 'variant']);
            $oldQty = (int) $existingItem->quantity;
            $oldPrice = (int) $existingItem->price;
            $newQty = $oldQty + $quantity;

            $this->adjustSaleItemStock($invoice, $existingItem, -$quantity, StockMovement::REASON_SALE, 'کسر موجودی بابت افزودن تعداد به آیتم موجود حواله فروش', $reason, $note);

            if ($oldPrice !== $price) {
                $this->historyService->log($invoice, 'item_price_changed', 'price', (string) $oldPrice, (string) $price, $this->itemChangeDescription('قیمت آیتم ' . $this->itemLabel($existingItem) . ' از ' . number_format($oldPrice) . ' به ' . number_format($price) . ' تغییر کرد.', $reason, $note), $userId);
            }

            $existingItem->update([
                'quantity' => $newQty,
                'price' => $price,
                'line_total' => max(($newQty * $price) - (int) ($existingItem->line_discount_amount ?? 0), 0),
            ]);

            $this->historyService->log($invoice, 'item_quantity_increased', 'quantity', (string) $oldQty, (string) $newQty, $this->itemChangeDescription('آیتم ' . $this->itemLabel($existingItem) . ' قبلاً در حواله وجود داشت؛ تعداد از ' . number_format($oldQty) . ' به ' . number_format($newQty) . ' افزایش یافت و ' . number_format($quantity) . ' عدد از موجودی مرکزی کم شد.', $reason, $note), $userId);
            $this->logItemStockAudit($invoice, $existingItem, $oldQty, $newQty, $reason, $note, $userId);

            return;
        }

        $nextSortOrder = $this->nextInvoiceItemSortOrder($invoice);

        $item = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => (int) $variant->product_id,
            'product_name_snapshot' => $variant->product?->name,
            'variant_id' => $variantId,
            'variant_name_snapshot' => $variant->variant_name,
            'variant_code_snapshot' => $variant->variant_code,
            'quantity' => $quantity,
            'price' => $price,
            'line_total' => max(($quantity * $price) - (int) ($row['line_discount_amount'] ?? 0), 0),
            'sort_order' => (int) ($row['sort_order'] ?? $nextSortOrder),
            'line_discount_amount' => min((int) ($row['line_discount_amount'] ?? 0), $quantity * $price),
        ]);

        $this->adjustSaleItemStock($invoice, $item, -$quantity, StockMovement::REASON_SALE, 'کسر موجودی بابت افزودن آیتم جدید به حواله فروش', $reason, $note);
        $this->historyService->log($invoice, 'item_added', 'items', null, (string) $item->id, $this->itemChangeDescription('آیتم ' . $this->itemLabel($item) . ' اضافه شد؛ ' . number_format($quantity) . ' عدد از موجودی مرکزی کم شد.', $reason, $note), $userId);
        $this->logItemStockAudit($invoice, $item, 0, $quantity, $reason, $note, $userId);
    }


    private function nextInvoiceItemSortOrder(Invoice $invoice): int
    {
        $maxSortOrder = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->selectRaw('MAX(COALESCE(sort_order, id)) as max_order')
            ->value('max_order');

        return ((int) $maxSortOrder) + 1;
    }

    private function itemLabel(InvoiceItem $item): string
    {
        return trim(($item->product?->name ?: ('#' . $item->product_id)) . ' ' . ($item->variant?->variant_name ? ('/ ' . $item->variant->variant_name) : ''));
    }

    private function itemChangeDescription(string $prefix, ?string $reason, ?string $note): string
    {
        $description = $prefix;
        if ($reason) {
            $description .= ' دلیل: ' . $this->reasonLabel($reason) . '.';
        }
        if ($note) {
            $description .= ' توضیح: ' . $note;
        }

        return $description;
    }

    private function reasonLabel(string $reason): string
    {
        return [
            'price_correction' => 'اصلاح قیمت برای همین فاکتور',
            'customer_quantity_change' => 'تغییر تعداد به درخواست مشتری',
            'item_removed' => 'حذف کالا از فاکتور',
            'item_added' => 'افزودن کالا به فاکتور',
            'physical_shortage' => 'کسری فیزیکی / کالا در انبار پیدا نشد',
            'customer_cancelled' => 'انصراف مشتری',
            'wrong_item' => 'کالای اشتباه',
            'warehouse_correction' => 'اصلاح انبار',
            'finance_correction' => 'اصلاح مالی',
            'replacement' => 'جایگزینی کالا',
            'other' => 'سایر',
        ][$reason] ?? $reason;
    }

    private function canEditSalesHavalehItems(Invoice $invoice, $user): bool
    {
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin', 'Admin', 'warehouse', 'Warehouse', 'manager', 'Manager'])) {
            return true;
        }

        return $this->accessService->canSellerEditInvoiceItems($invoice, $user);
    }

    private function logItemStockAudit(Invoice $invoice, InvoiceItem $item, int $oldQty, int $newQty, ?string $reason, ?string $note, ?int $userId): void
    {
        $delta = $newQty - $oldQty;
        $this->historyService->log(
            $invoice,
            $newQty <= 0 ? 'item_removed_stock_adjusted' : ($delta > 0 ? 'item_quantity_increased_stock_adjusted' : 'item_quantity_decreased_stock_adjusted'),
            'quantity',
            (string) $oldQty,
            (string) $newQty,
            ($reason === 'physical_shortage')
                ? 'موجودی به انبار مرکزی برگشت داده شد. برای اصلاح موجودی واقعی، لطفاً انبارگردانی/کسری کالا ثبت کنید.'
                : 'ثبت audit تغییر تعداد/حذف آیتم فروش و اصلاح موجودی متناظر.',
            $userId,
            [
                'invoice_item_id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'old_quantity' => $oldQty,
                'new_quantity' => $newQty,
                'delta' => $delta,
                'returned_to_stock_quantity' => $delta < 0 ? abs($delta) : ($newQty <= 0 ? $oldQty : 0),
                'consumed_from_stock_quantity' => $delta > 0 ? $delta : 0,
                'reason' => $reason ?: 'other',
                'note' => $note,
            ]
        );
    }

    private function adjustSaleItemStock(Invoice $invoice, InvoiceItem $item, int $quantityDelta, string $movementReason, string $message, ?string $reason, ?string $note, ?string $transactionType = null): void
    {
        if ($quantityDelta === 0) {
            return;
        }

        if ((int) $item->variant_id <= 0) {
            abort(422, 'برگشت یا کسر موجودی آیتم فروش بدون تنوع کالا مجاز نیست.');
        }

        $businessReason = $reason ?: 'other';
        $noteText = trim($message . ($businessReason ? ' | دلیل: ' . $businessReason : '') . ($note ? ' | توضیح: ' . $note : ''));

        $this->inventoryService->adjustCentralStock(
            (int) $item->product_id,
            (int) $item->variant_id,
            $quantityDelta,
            $invoice->uuid,
            $noteText,
            [
                'reason' => $movementReason,
                'transaction_type' => $transactionType ?: StockMovement::TRANSACTION_SALES_HAVALEH_ADJUSTMENT,
                'reference_type' => Invoice::class,
                'reference_id' => $invoice->id,
            ]
        );
    }


    private function officialCodeForPreinvoiceConversion(PreinvoiceOrder $order): string
    {
        if (is_string($order->uuid) && preg_match('/^\d{5}$/', $order->uuid) === 1) {
            return $order->uuid;
        }

        $code = DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class);
        $order->update(['uuid' => $code]);

        return $code;
    }

    private function changeReservedOnly(int $productId, int $variantId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
        if ($variant) {
            $variant->reserved = max(0, (int) $variant->reserved + $delta);
            $variant->save();
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = max(0, (int) $product->reserved + $delta);
            $product->save();
        }
    }

    public function createFromFinancialRecord(int $preinvoiceOrderId, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($preinvoiceOrderId, $userId) {
            $order = PreinvoiceOrder::query()->with('items')->lockForUpdate()->findOrFail($preinvoiceOrderId);

            if ($order->status !== 'finance_approved') {
                throw ValidationException::withMessages([
                    'financial' => 'فقط پیش‌فاکتور تایید مالی‌شده قابل تبدیل به حواله فروش است.',
                ]);
            }

            $existing = Invoice::query()->where('preinvoice_order_id', $order->id)->first();
            if ($existing) {
                return $existing;
            }

            $totals = SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price);
            $subtotal = $totals['subtotal_before_discount'];
            $total = $totals['grand_total'];

            $invoice = Invoice::query()->create([
                'uuid' => $this->officialCodeForPreinvoiceConversion($order),
                'preinvoice_order_id' => $order->id,
                'document_date' => $order->display_document_date,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_mobile' => $order->customer_mobile,
                'customer_address' => $order->customer_address,
                'province_id' => $order->province_id,
                'city_id' => $order->city_id,
                'shipping_id' => $order->shipping_id,
                'shipping_price' => (int) $order->shipping_price,
                'discount_amount' => (int) $order->discount_amount,
                'subtotal' => $subtotal,
                'total' => $total,
                'status' => SalesHavalehStatusService::COLLECTING,
                'status_changed_at' => now(),
                'status_changed_by' => $userId,
            ]);

            foreach ($order->items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => (int) $item->product_id,
                    'variant_id' => (int) $item->variant_id,
                    'quantity' => (int) $item->quantity,
                    'price' => (int) $item->price,
                    'line_total' => (int) $item->quantity * (int) $item->price,
                ]);

                $this->adjustSaleItemStock($invoice, $invoice->items()->where('variant_id', (int) $item->variant_id)->latest('id')->firstOrFail(), -((int) $item->quantity), StockMovement::REASON_SALE, 'کسر موجودی بابت ایجاد حواله فروش از رکورد مالی', null, null);
            }

            $this->ledgerService->syncInvoiceDebit($invoice);
            $this->historyService->log($invoice, 'created', null, null, null, 'ایجاد حواله فروش از رکورد مالی', $userId);

            return $invoice;
        });
    }

    public function changeStatus(Invoice $invoice, string $newStatus, ?string $note = null, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $newStatus, $note, $userId) {
            $user = auth()->user();

            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $oldStatus = (string) $invoice->status;
            $this->statusService->assertValidTransition($invoice, $newStatus, $user);

            $invoice->update([
                'status' => $newStatus,
                'status_changed_at' => now(),
                'status_changed_by' => $userId,
            ]);

            $this->historyService->log(
                $invoice,
                $newStatus === SalesHavalehStatusService::SHIPPED ? 'shipped' : 'status_changed',
                'status',
                $oldStatus,
                $newStatus,
                $note,
                $userId
            );

            return $invoice->fresh();
        });
    }

    public function cancelAndRestore(Invoice $invoice, ?string $note = null, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $note, $userId) {
            $invoice = Invoice::query()->with('items')->lockForUpdate()->findOrFail($invoice->id);
            $oldStatus = (string) $invoice->status;
            if ((string) $invoice->status === SalesHavalehStatusService::NOT_SHIPPED) {
                return $invoice;
            }

            if (! $this->hasCancellationStockReturn($invoice)) {
                foreach ($invoice->items as $item) {
                    $this->adjustSaleItemStock($invoice, $item, (int) $item->quantity, StockMovement::REASON_RETURN, 'برگشت موجودی بابت کنسلی حواله فروش', null, $note, StockMovement::TRANSACTION_SALES_HAVALEH_ADJUSTMENT . '_cancel');
                }
            }

            $this->ledgerService->voidInvoiceDebit($invoice, $note);

            $invoice->update([
                'status' => SalesHavalehStatusService::NOT_SHIPPED,
                'status_changed_at' => now(),
                'status_changed_by' => $userId,
            ]);

            $this->historyService->log($invoice, 'cancelled', 'status', $oldStatus, SalesHavalehStatusService::NOT_SHIPPED, $note ?: 'کنسلی فاکتور و برگشت موجودی', $userId);
            $this->markLinkedPreinvoiceCancelled($invoice, $note, $userId);

            $order = $invoice->preinvoiceOrder()
                ->lockForUpdate()
                ->first();

            if ($order && (string) $order->status !== PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE) {
                $oldPreinvoiceStatus = (string) $order->status;

                $order->update([
                    'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                    'warehouse_reject_reason' => $note ?: 'کنسلی فاکتور و برگشت موجودی',
                    'stock_frozen_until' => null,
                    'stock_released_at' => $order->stock_released_at ?: now(),
                ]);

                $order->reviews()->create([
                    'user_id' => $userId,
                    'action' => 'invoice_cancelled',
                    'reason' => $note ?: 'کنسلی فاکتور و برگشت موجودی',
                    'before_items' => $order->items()->get()->toArray(),
                    'after_items' => $order->items()->get()->toArray(),
                ]);

                ActivityLogger::log('invoice_cancelled_preinvoice_cancelled', $order->fresh(), 'پیش‌فاکتور به دلیل کنسلی فاکتور مرتبط لغو شد.', [
                    'invoice_id' => $invoice->id,
                    'invoice_uuid' => $invoice->uuid,
                    'old_status' => $oldPreinvoiceStatus,
                    'new_status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                    'reason' => $note,
                ]);
            }

            return $invoice->fresh();
        });
    }


    private function hasCancellationStockReturn(Invoice $invoice): bool
    {
        return StockMovement::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', (int) $invoice->id)
            ->where('transaction_type', StockMovement::TRANSACTION_SALES_HAVALEH_ADJUSTMENT . '_cancel')
            ->exists();
    }

    public function undoCancelAndReserve(Invoice $invoice, ?string $note = null, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $note, $userId) {
            $invoice = Invoice::query()->with('items')->lockForUpdate()->findOrFail($invoice->id);
            if ((string) $invoice->status !== SalesHavalehStatusService::NOT_SHIPPED) {
                return $invoice;
            }

            foreach ($invoice->items as $item) {
                $this->adjustSaleItemStock($invoice, $item, -((int) $item->quantity), StockMovement::REASON_SALE, 'کسر مجدد موجودی بابت لغو کنسلی فاکتور', null, $note);
            }

            $invoice->update([
                'status' => SalesHavalehStatusService::COLLECTING,
                'status_changed_at' => now(),
                'status_changed_by' => $userId,
            ]);

            $this->historyService->log($invoice, 'cancel_reverted', 'status', SalesHavalehStatusService::NOT_SHIPPED, SalesHavalehStatusService::COLLECTING, $note ?: 'لغو کنسلی فاکتور توسط مالی', $userId);
            $this->restoreLinkedPreinvoiceAfterCancelUndo($invoice, $note, $userId);

            return $invoice->fresh();
        });
    }

    private function markLinkedPreinvoiceCancelled(Invoice $invoice, ?string $note, ?int $userId): void
    {
        $order = $invoice->preinvoiceOrder()
            ->lockForUpdate()
            ->first();

        if (! $order || (string) $order->status === PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE) {
            return;
        }

        $oldPreinvoiceStatus = (string) $order->status;
        $reason = $note ?: 'کنسلی فاکتور و برگشت موجودی';
        $itemsSnapshot = $order->items()->get()->toArray();

        $order->update([
            'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
            'warehouse_reject_reason' => $reason,
            'stock_frozen_until' => null,
            'stock_released_at' => $order->stock_released_at ?: now(),
        ]);

        $order->reviews()->create([
            'user_id' => $userId,
            'action' => 'invoice_cancelled',
            'reason' => $reason,
            'before_items' => $itemsSnapshot,
            'after_items' => $itemsSnapshot,
        ]);

        ActivityLogger::log('invoice_cancelled_preinvoice_cancelled', $order->fresh(), 'پیش‌فاکتور به دلیل کنسلی فاکتور مرتبط لغو شد.', [
            'invoice_id' => $invoice->id,
            'invoice_uuid' => $invoice->uuid,
            'old_status' => $oldPreinvoiceStatus,
            'new_status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
            'reason' => $note,
        ]);
    }

    private function restoreLinkedPreinvoiceAfterCancelUndo(Invoice $invoice, ?string $note, ?int $userId): void
    {
        $order = $invoice->preinvoiceOrder()
            ->lockForUpdate()
            ->first();

        if (! $order) {
            return;
        }

        $oldPreinvoiceStatus = (string) $order->status;
        $reason = $note ?: 'لغو کنسلی فاکتور توسط مالی';
        $itemsSnapshot = $order->items()->get()->toArray();

        $order->update([
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'warehouse_reject_reason' => null,
            'stock_frozen_until' => null,
            'stock_released_at' => now(),
        ]);

        $order->reviews()->create([
            'user_id' => $userId,
            'action' => 'invoice_cancel_reverted',
            'reason' => $reason,
            'before_items' => $itemsSnapshot,
            'after_items' => $itemsSnapshot,
        ]);

        ActivityLogger::log('invoice_cancel_reverted_preinvoice_restored', $order->fresh(), 'کنسلی پیش‌فاکتور به دلیل لغو کنسلی فاکتور مرتبط برگشت خورد.', [
            'invoice_id' => $invoice->id,
            'invoice_uuid' => $invoice->uuid,
            'old_status' => $oldPreinvoiceStatus,
            'new_status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'reason' => $note,
        ]);
    }
}
