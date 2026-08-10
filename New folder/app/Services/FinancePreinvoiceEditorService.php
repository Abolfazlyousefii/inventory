<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePreinvoiceEditorService
{
    public function __construct(
        private readonly PreinvoiceReservationService $reservationService,
        private readonly ProductDiscountAllocator $productDiscountAllocator,
    ) {}

    public function update(PreinvoiceOrder $order, array $data, User $actor): PreinvoiceOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $lockedOrder = PreinvoiceOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedOrder->status;

            if ($lockedOrder->invoice()->exists() || $lockedOrder->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE) {
                throw ValidationException::withMessages(['preinvoice' => 'این پیش‌فاکتور قبلاً فاکتور دارد و از مسیر ویرایش اولیه مالی قابل تغییر نیست.']);
            }

            if (! in_array($lockedOrder->status, [PreinvoiceOrder::STATUS_PENDING_FINANCE, PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE, PreinvoiceOrder::STATUS_FINANCE_REVIEWING], true)) {
                throw ValidationException::withMessages(['preinvoice' => 'این پیش‌فاکتور در وضعیت مجاز برای ویرایش مالی نیست.']);
            }

            $items = $lockedOrder->items()->with(['product', 'variant'])->lockForUpdate()->get()->keyBy('id');
            $payload = collect($data['items'] ?? [])->keyBy(fn ($row) => (int) $row['id']);
            if ($items->keys()->sort()->values()->all() !== $payload->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'اقلام ارسالی باید دقیقاً با اقلام پیش‌فاکتور برابر باشند؛ افزودن یا حذف ردیف مجاز نیست.']);
            }

            $beforeItems = $this->snapshot($lockedOrder->fresh(['items.product', 'items.variant']));
            $beforeTotal = (int) $lockedOrder->total_price;
            $beforeInvoiceDiscount = (int) ($lockedOrder->invoice_discount_amount ?? 0);

            foreach ($items as $itemId => $item) {
                $row = $payload[$itemId];
                $oldQty = (int) $item->quantity;
                $newQty = (int) $row['quantity'];
                $delta = $newQty - $oldQty;
                if ($delta !== 0) {
                    $this->reservationService->adjustOfficialReservationDelta($lockedOrder, $item, $delta, $actor);
                }

                $price = (int) $row['price'];
                $item->forceFill([
                    'quantity' => $newQty,
                    'price' => $price,
                    'line_total' => $newQty * $price,
                ])->save();
            }

            $lockedOrder->load('items.product', 'items.variant');
            $productDiscountPayload = array_key_exists('product_discounts', $data)
                ? (array) $data['product_discounts']
                : $this->productDiscountInputsFromBreakdown($lockedOrder);
            $productDiscounts = $this->productDiscountAllocator->allocate($lockedOrder->items, $productDiscountPayload);
            foreach ($lockedOrder->items as $item) {
                $lineDiscount = (int) ($productDiscounts['lines'][$item->id] ?? $item->line_discount_amount ?? 0);
                $item->forceFill([
                    'line_discount_amount' => $lineDiscount,
                    'line_total' => max(((int) $item->quantity * (int) $item->price) - $lineDiscount, 0),
                ])->save();
            }

            $lockedOrder->load('items.product', 'items.variant');
            $itemsDiscount = (int) $lockedOrder->items->sum(fn ($item) => SalesDocumentTotals::lineDiscount($item));
            $invoiceDiscountType = (string) ($data['invoice_discount_type'] ?? $lockedOrder->invoice_discount_type ?? 'none');
            if (! in_array($invoiceDiscountType, ['none', 'amount', 'percent'], true)) {
                $invoiceDiscountType = 'none';
            }
            $invoiceDiscountValue = (int) ($data['invoice_discount_value'] ?? $lockedOrder->invoice_discount_value ?? 0);
            if ($invoiceDiscountType === 'none') {
                $invoiceDiscountValue = 0;
            }

            $discountableBase = max((int) $lockedOrder->items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item)) - $itemsDiscount, 0);
            $invoiceDiscountAmount = match ($invoiceDiscountType) {
                'amount' => min($invoiceDiscountValue, $discountableBase),
                'percent' => (int) floor($discountableBase * min(max($invoiceDiscountValue, 0), 100) / 100),
                default => 0,
            };

            $totals = SalesDocumentTotals::calculate($lockedOrder->items, $invoiceDiscountAmount, (int) $lockedOrder->shipping_price, ['discount_allocation_mode' => 'product_lines']);
            $breakdown = [
                'subtotal' => (int) $totals['subtotal_before_discount'],
                'product_discount_amount' => (int) $totals['items_discount'],
                'invoice_discount_type' => $invoiceDiscountType,
                'invoice_discount_value' => $invoiceDiscountValue,
                'invoice_discount_amount' => (int) $totals['invoice_discount'],
                'total_discount_amount' => (int) $totals['total_discount'],
                'subtotal_after_product_discount' => $discountableBase,
                'grand_total' => (int) $totals['grand_total'],
                'shipping' => (int) $totals['shipping'],
                'groups' => array_values($productDiscounts['groups']),
            ];

            $lockedOrder->forceFill([
                'status' => $oldStatus,
                'discount_amount' => (int) $totals['total_discount'],
                'invoice_discount_type' => $invoiceDiscountType === 'none' ? null : $invoiceDiscountType,
                'invoice_discount_value' => $invoiceDiscountValue,
                'invoice_discount_amount' => (int) $totals['invoice_discount'],
                'product_discount_amount' => (int) $totals['items_discount'],
                'discount_allocation_mode' => 'product_lines',
                'discount_breakdown' => $breakdown,
                'total_price' => (int) $totals['grand_total'],
                'items_updated_at' => now(),
                'items_updated_by' => $actor->id,
            ])->save();

            $afterItems = $this->snapshot($lockedOrder->fresh(['items.product', 'items.variant']));
            $lockedOrder->reviews()->create([
                'user_id' => $actor->id,
                'action' => 'finance_edited',
                'reason' => $data['edit_reason'],
                'before_items' => $beforeItems,
                'after_items' => $afterItems,
            ]);
            ActivityLogger::log('finance_edited', $lockedOrder->fresh(), 'ویرایش مالی پیش‌فاکتور ثبت شد.', [
                'user_id' => $actor->id,
                'preinvoice_id' => $lockedOrder->id,
                'old_total' => $beforeTotal,
                'new_total' => (int) $totals['grand_total'],
                'old_invoice_discount' => $beforeInvoiceDiscount,
                'new_invoice_discount' => (int) $totals['invoice_discount'],
                'before_items' => $beforeItems,
                'after_items' => $afterItems,
            ]);

            return $lockedOrder->fresh(['items.product', 'items.variant', 'invoice']);
        });
    }


    private function productDiscountInputsFromBreakdown(PreinvoiceOrder $order): array
    {
        return collect($order->discount_breakdown['groups'] ?? [])->map(function ($group) {
            return [
                'product_id' => (int) ($group['product_id'] ?? 0),
                'type' => (string) ($group['discount_type'] ?? 'amount'),
                'value' => (int) ($group['discount_value'] ?? 0),
            ];
        })->filter(fn ($group) => (int) $group['product_id'] > 0)->values()->all();
    }

    private function snapshot(PreinvoiceOrder $order): array
    {
        return $order->items->map(fn ($item) => [
            'item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
            'price' => (int) $item->price,
            'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
            'gross_line' => SalesDocumentTotals::lineSubtotal($item),
            'net_line' => SalesDocumentTotals::lineTotal($item),
        ])->values()->all();
    }
}
