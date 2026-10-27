<?php

namespace App\Services;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\SalesDocumentTotals;
use App\Support\DiscountMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancePreinvoiceEditorService
{
    public function __construct(
        private readonly PreinvoiceReservationService $reservationService,
        private readonly ProductDiscountAllocator $productDiscountAllocator,
    ) {}

    /** Finance review changes discounts only; item corrections belong to sales. */
    public function updateDiscounts(PreinvoiceOrder $order, array $data, User $actor): PreinvoiceOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $lockedOrder = PreinvoiceOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $items = $lockedOrder->items()->lockForUpdate()->get()->keyBy('id');
            foreach ($data['items'] ?? [] as $row) {
                $item = $items->get((int) $row['id']);
                if (! $item || (int) $row['quantity'] !== (int) $item->quantity || (int) $row['price'] !== (int) $item->price) {
                    throw ValidationException::withMessages(['items' => 'در بررسی مالی فقط تخفیف‌ها قابل تغییرند. برای اصلاح کالا، تعداد یا قیمت واحد، پیش‌فاکتور را به فروشنده ارجاع دهید.']);
                }
            }

            // The existing editor also verifies the exact item set and document version.
            return $this->update($lockedOrder, $data, $actor);
        });
    }

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
            $lockedOrder->setRelation('items', $items->values());
            \App\Support\FinanceDocumentVersion::assertCurrent($lockedOrder, $data['finance_version'] ?? null);
            $payload = collect($data['items'] ?? [])->keyBy(fn ($row) => (int) $row['id']);
            if ($payload->count() !== count($data['items'] ?? [])) {
                throw ValidationException::withMessages(['items' => 'شناسه تکراری در اقلام ارسالی وجود دارد.']);
            }
            if ($items->keys()->sort()->values()->all() !== $payload->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'اقلام ارسالی باید دقیقاً با اقلام پیش‌فاکتور برابر باشند؛ افزودن یا حذف ردیف مجاز نیست.']);
            }

            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            foreach ($data['product_discounts'] ?? [] as $discount) {
                if (! in_array((int) ($discount['product_id'] ?? 0), $productIds, true)) {
                    throw ValidationException::withMessages(['product_discounts' => 'کالای تخفیف باید در اقلام همین پیش‌فاکتور باشد.']);
                }
            }
            // Compare against the persisted, hydrated discount contract under the same row lock.
            // A review without an actual discount change must neither require a reason
            // nor manufacture a finance_edited event or rewrite the version token.
            $discountState = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($lockedOrder);
            $hasDiscountChanges = $this->discountInputsChanged($discountState, $data);
            $reason = trim((string) ($data['edit_reason'] ?? ''));
            if ($hasDiscountChanges && mb_strlen($reason) < 3) {
                throw ValidationException::withMessages(['edit_reason' => 'لطفاً برای تغییر تخفیف، دلیل حداقل ۳ کاراکتری وارد کنید.']);
            }
            if (! $hasDiscountChanges) {
                return $lockedOrder->fresh(['items.product', 'items.variant', 'invoice']);
            }

            $beforeItems = $this->snapshot($lockedOrder->fresh(['items.product', 'items.variant']));
            $beforeInvoiceType = (string) ($discountState['invoice_discount']['type'] ?? 'none');
            $beforeInvoiceValue = $discountState['invoice_discount']['value'] ?? 0;
            $beforeProductGroups = array_values($discountState['product_groups'] ?? []);
            $beforeTotal = (int) $lockedOrder->total_price;
            $beforeInvoiceDiscount = (int) ($lockedOrder->invoice_discount_amount ?? 0);
            // Missing optional groups must preserve legacy discounts, not clear them.
            $productDiscountPayload = array_key_exists('product_discounts', $data)
                ? (array) $data['product_discounts']
                : $this->productDiscountInputsFromBreakdown($lockedOrder);

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
            $invoiceDiscountValue = DiscountMath::value($invoiceDiscountType, $data['invoice_discount_value'] ?? $lockedOrder->invoice_discount_value ?? 0);
            if ($invoiceDiscountType === 'none') {
                $invoiceDiscountValue = 0;
            }

            $discountableBase = max((int) $lockedOrder->items->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item)) - $itemsDiscount, 0);
            $invoiceDiscountAmount = DiscountMath::amount($discountableBase, $invoiceDiscountType, $invoiceDiscountValue);

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
                'reason' => $reason,
                'before_items' => $beforeItems,
                'after_items' => $afterItems,
            ]);
            ActivityLogger::log('finance_edited', $lockedOrder->fresh(), 'ویرایش مالی پیش‌فاکتور ثبت شد.', [
                'user_id' => $actor->id,
                'preinvoice_id' => $lockedOrder->id,
                'reason' => $reason,
                'old_invoice_discount_type' => $beforeInvoiceType,
                'old_invoice_discount_value' => $beforeInvoiceValue,
                'new_invoice_discount_type' => $invoiceDiscountType,
                'new_invoice_discount_value' => $invoiceDiscountValue,
                'old_product_discount_groups' => $beforeProductGroups,
                'new_product_discount_groups' => array_values($productDiscounts['groups']),
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


    /**
     * Compare the original editor inputs, not only the resulting total:
     * changing discount type also requires an explanation even if rounding
     * currently produces the same monetary discount.
     */
    private function discountInputsChanged(array $original, array $data): bool
    {
        $submitted = array_key_exists('product_discounts', $data)
            ? collect((array) $data['product_discounts'])->keyBy(fn ($row) => (int) ($row['product_id'] ?? 0))
            : null;

        foreach (($original['product_groups'] ?? []) as $productId => $group) {
            $previousType = (string) ($group['discount_type'] ?? 'amount');
            $previousValue = DiscountMath::value($previousType, $group['discount_value'] ?? 0);
            $incoming = $submitted === null
                ? ['type' => $previousType, 'value' => $previousValue]
                : ($submitted->get((int) $productId) ?? ['type' => 'amount', 'value' => 0]);
            $newType = (string) ($incoming['type'] ?? 'amount');
            $newValue = DiscountMath::value($newType, $incoming['value'] ?? 0);

            if ($previousType !== $newType || (string) $previousValue !== (string) $newValue) {
                return true;
            }
        }

        $originalInvoice = (array) ($original['invoice_discount'] ?? []);
        $previousType = (string) ($originalInvoice['type'] ?? 'none');
        $newType = (string) ($data['invoice_discount_type'] ?? $previousType);
        if ($previousType !== $newType) {
            return true;
        }

        $previousValue = $previousType === 'none' ? 0 : DiscountMath::value($previousType, $originalInvoice['value'] ?? 0);
        $newValue = $newType === 'none'
            ? 0
            : DiscountMath::value($newType, $data['invoice_discount_value'] ?? $originalInvoice['value'] ?? 0);

        return (string) $previousValue !== (string) $newValue;
    }

    private function productDiscountInputsFromBreakdown(PreinvoiceOrder $order): array
    {
        return collect(app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order)['product_groups'])->map(function ($group) {
            return [
                'product_id' => (int) ($group['product_id'] ?? 0),
                'type' => (string) ($group['discount_type'] ?? 'amount'),
                'value' => $group['discount_value'] ?? 0,
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
