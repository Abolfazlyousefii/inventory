<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\Warehouse;
use App\Support\SalesDocumentTotals;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class SalesPrintDocumentService
{
    public function __construct(private readonly WarehouseMapService $warehouseMapService) {}

    public function invoiceData(Invoice $invoice, string $mode = 'warehouse'): array
    {
        $invoice->loadMissing(['items.product', 'items.variant.modelList', 'items.variant.color', 'payments', 'preinvoiceOrder', 'shippingMethod', 'customer']);

        $paid = $invoice->relationLoaded('payments') ? (int) $invoice->payments->sum('amount') : (int) $invoice->paid_amount;
        $totals = SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]);

        return [
            'documentType' => 'invoice',
            'title' => 'فاکتور فروش',
            'numberLabel' => 'شماره فاکتور',
            'number' => $invoice->uuid,
            'customerNumber' => $invoice->external_order_id ?: null,
            'registeredAt' => $invoice->display_document_date,
            'issuedAt' => $invoice->display_document_date,
            'status' => $this->statusLabel($invoice->status),
            'customer' => [
                'name' => $invoice->customer_name ?: $invoice->customer?->display_name,
                'mobile' => $invoice->customer_mobile ?: $invoice->customer?->mobile,
                'address' => $invoice->customer_address,
                'code' => $invoice->customer?->crm_customer_id ?: $invoice->customer_id,
            ],
            'shipping' => [
                'method' => $invoice->shippingMethod?->name ?? ($invoice->shipping_id ? 'روش ارسال #' . $invoice->shipping_id : null),
                'cost' => (int) $invoice->shipping_price,
                'description' => null,
            ],
            'items' => $this->items($invoice->items),
            'totals' => [
                'subtotal' => $totals['subtotal_before_discount'],
                'discount' => $totals['total_discount'],
                'itemsDiscount' => $totals['items_discount'],
                'invoiceDiscount' => $totals['invoice_discount'],
                'subtotalAfterDiscount' => $totals['subtotal_after_discount'],
                'shipping' => $totals['shipping'],
                'total' => $totals['grand_total'],
                'paid' => $paid,
                'remaining' => max($totals['grand_total'] - $paid, 0),
            ],
            'company' => config('company'),
            'logo' => asset('logo.png'),
            'mode' => $mode === 'customer' ? 'customer' : 'warehouse',
            'backUrl' => route('invoices.show', $invoice->uuid),
        ];
    }

    public function preinvoiceData(PreinvoiceOrder $order, string $mode = 'warehouse'): array
    {
        $order->loadMissing(['items.product', 'items.variant.modelList', 'items.variant.color', 'shippingMethod', 'customer', 'invoice']);
        $totals = SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price, ['discount_allocation_mode' => $order->discount_allocation_mode]);

        return [
            'documentType' => 'preinvoice',
            'title' => 'پیش‌فاکتور فروش',
            'numberLabel' => 'شماره پیش‌فاکتور',
            'number' => $order->uuid,
            'customerNumber' => $order->external_order_id ?: null,
            'registeredAt' => $order->display_document_date,
            'issuedAt' => null,
            'status' => $this->statusLabel($order->status),
            'customer' => [
                'name' => $order->customer_name,
                'mobile' => $order->customer_mobile,
                'address' => $order->customer_address,
                'code' => $order->customer?->crm_customer_id ?: $order->customer_id,
            ],
            'shipping' => [
                'method' => $order->shippingMethod?->name ?? ($order->shipping_id ? 'روش ارسال #' . $order->shipping_id : null),
                'cost' => (int) $order->shipping_price,
                'description' => $order->description,
            ],
            'items' => $this->items($order->items),
            'totals' => [
                'subtotal' => $totals['subtotal_before_discount'],
                'discount' => $totals['total_discount'],
                'itemsDiscount' => $totals['items_discount'],
                'invoiceDiscount' => $totals['invoice_discount'],
                'subtotalAfterDiscount' => $totals['subtotal_after_discount'],
                'shipping' => $totals['shipping'],
                'total' => $totals['grand_total'],
                'paid' => null,
                'remaining' => null,
            ],
            'company' => config('company'),
            'logo' => asset('logo.png'),
            'mode' => $mode === 'customer' ? 'customer' : 'warehouse',
            'backUrl' => route('archive.preinvoices.show', $order->uuid),
        ];
    }

    private function items(EloquentCollection|Collection $items): Collection
    {
        $warehouseId = (int) (Warehouse::query()->where('type', 'central')->value('id') ?: Warehouse::query()->value('id'));

        return $items->values()->map(function ($item) use ($warehouseId) {
            $variant = $item->variant;
            $product = $item->product;

            return [
                'description' => $this->formatInvoiceItemDescription($item),
                'inventoryCode' => $this->inventoryCode($variant, $product),
                'warehouseMap' => $variant ? $this->warehouseMap((int) $variant->id, $warehouseId) : 'بدون نقشه',
                'quantity' => (int) $item->quantity,
                'unitPrice' => (int) $item->price,
                'lineDiscount' => (int) ($item->line_discount_amount ?? 0),
                'netUnitPrice' => max((int) $item->price - (int) floor(((int) ($item->line_discount_amount ?? 0)) / max((int) $item->quantity, 1)), 0),
                'lineTotal' => SalesDocumentTotals::lineTotal($item),
            ];
        });
    }

    public function formatInvoiceItemDescription($item): string
    {
        $variant = $item->variant;
        $product = $item->product;

        $parts = collect([
            $product?->name,
            $variant?->modelList?->model_name,
            $variant?->variant_name,
            $variant?->variety_name,
            $variant?->color?->name,
        ])
            ->map(fn ($value) => $this->cleanText($value))
            ->filter()
            ->reduce(function (Collection $carry, string $part) {
                $normalizedPart = $this->normalizeText($part);

                if ($carry->contains(fn (string $existing) => $this->normalizeText($existing) === $normalizedPart)) {
                    return $carry;
                }

                if ($carry->contains(fn (string $existing) => str_contains($this->normalizeText($existing), $normalizedPart))) {
                    return $carry;
                }

                return $carry->push($part);
            }, collect());

        return $parts->isNotEmpty() ? $parts->implode(' | ') : ('#' . $item->product_id);
    }

    private function inventoryCode($variant, $product): string
    {
        return collect([
            $variant?->barcode,
            $variant?->sku,
            $variant?->variant_code,
            $variant?->variety_code,
            $variant?->unique_key,
            $product?->barcode,
            $product?->sku,
            $product?->code,
            $product?->short_barcode,
        ])->first(fn ($value) => filled($value)) ?: '—';
    }

    private function statusLabel(?string $status): string
    {
        if (! filled($status)) {
            return '—';
        }

        $labels = [
            'pending_warehouse_approval' => 'در انتظار تایید انبار',
            'warehouse_approved' => 'تایید شده توسط انبار',
            'pending_finance_approval' => 'در انتظار تایید مالی',
            'finance_approved' => 'تایید شده توسط مالی',
            'sent' => 'ارسال شده',
            'shipped' => 'ارسال شده',
            'collecting' => 'در حال جمع‌آوری',
            'draft' => 'پیش‌نویس',
            'rejected' => 'رد شده',
            'reserved_waiting_warehouse' => 'در انتظار تایید انبار',
            'warehouse_reviewing' => 'در حال بررسی توسط انبار',
            'warehouse_approved_waiting_finance' => 'تایید انبار و در انتظار مالی',
            'finance_reviewing' => 'در حال بررسی توسط مالی',
            'converted_to_invoice' => 'تبدیل‌شده به فاکتور',
            'cancelled_by_warehouse' => 'لغوشده توسط انبار',
            'cancelled_by_finance' => 'لغوشده توسط مالی',
            'returned_to_warehouse' => 'برگشت‌خورده از مالی به انبار',
            'checking_discrepancy' => 'در حال مغایرت و بررسی',
            'final_check' => 'در حال چک نهایی',
            'packing' => 'در حال بسته‌بندی',
            'not_shipped' => 'کنسل شده',
        ];

        return $labels[$status] ?? str_replace('_', ' ', $status);
    }

    private function cleanText($value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s\-_|()]+/u', '', $value));
    }

    private function warehouseMap(int $variantId, int $warehouseId): string
    {
        if ($warehouseId <= 0) {
            return 'بدون نقشه';
        }

        $locations = $this->warehouseMapService->locationsForVariant($variantId, $warehouseId)
            ->filter(fn ($stock) => (int) $stock->quantity > 0 && $stock->location);

        if ($locations->isEmpty()) {
            return 'بدون نقشه';
        }

        return $locations->map(fn ($stock) => $stock->location->code . ((int) $stock->quantity > 0 ? ' (' . number_format((int) $stock->quantity) . ')' : ''))
            ->implode(' / ');
    }
}
