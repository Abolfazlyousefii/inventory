<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesReturnDocument;
use App\Services\SalesReturnCalculationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesReturnLookupController extends Controller
{
    public function __construct(private SalesReturnCalculationService $calculator) {}

    public function customers(Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        $q = trim((string) $request->query('q', ''));
        $customers = Customer::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('mobile', 'like', "%{$q}%")
                        ->orWhere('crm_customer_id', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'first_name', 'last_name', 'mobile', 'crm_customer_id']);

        return response()->json(['data' => $customers->map(fn ($c) => [
            'id' => $c->id,
            'text' => trim($c->first_name . ' ' . $c->last_name) ?: $c->mobile,
            'mobile' => $c->mobile,
            'code' => $c->crm_customer_id,
        ])]);
    }

    public function customerInvoices(Customer $customer, Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        $allowOverride = $request->user()?->hasPermission('sales_returns.override_invoice_status');
        $query = Invoice::query()->withCount('items')->where('customer_id', $customer->id)->whereHas('items');
        if (!$allowOverride) {
            $query->where('status', Invoice::STATUS_SHIPPED);
        }

        return response()->json(['data' => $query->latest('id')->limit(50)->get(['id', 'uuid', 'customer_id', 'created_at', 'total', 'status'])->map(fn ($invoice) => [
            'id' => $invoice->id,
            'uuid' => $invoice->uuid,
            'created_at' => optional($invoice->created_at)->format('Y-m-d'),
            'total' => (int) $invoice->total,
            'status' => $invoice->status,
            'status_label' => Invoice::statusLabels()[$invoice->status] ?? $invoice->status,
            'items_count' => (int) ($invoice->items_count ?? 0),
        ])]);
    }

    public function invoiceItems(Invoice $invoice, Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        $invoice->load(['items.product', 'items.variant']);
        $previous = $this->calculator->previouslyReturnedQuantities($invoice->items->pluck('id')->all());
        $allocations = $this->calculator->allocateInvoiceDiscount($invoice);

        return response()->json(['data' => $invoice->items->map(function ($item) use ($previous, $allocations) {
            $prev = (int) ($previous[$item->id] ?? 0);
            return [
                'invoice_item_id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
                'product_name' => $item->product?->name ?? 'کالای حذف‌شده',
                'variant_name' => $item->variant?->variant_name ?: $item->variant?->variety_name,
                'sku' => $item->variant?->variant_code ?: $item->product?->sku ?: $item->product?->barcode,
                'sold_quantity' => (int) $item->quantity,
                'previously_returned_quantity' => $prev,
                'returnable_quantity' => max((int) $item->quantity - $prev, 0),
                'price' => (int) $item->price,
                'line_discount' => (int) ($item->line_discount_amount ?? 0),
                'allocated_invoice_discount' => (int) ($allocations[$item->id] ?? 0),
            ];
        })->values()]);
    }

    public function products(Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        $q = trim((string) $request->query('q', ''));
        $products = Product::query()
            ->when($q !== '', fn ($query) => $query->search($q))
            ->with(['variants' => fn ($query) => $query->where('is_active', true)->limit(20)])
            ->limit(30)
            ->get(['id', 'name', 'sku', 'barcode', 'code', 'price']);

        return response()->json(['data' => $products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku ?: $product->barcode ?: $product->code,
            'price' => (int) $product->price,
        ])]);
    }

    public function variants(Product $product, Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        return response()->json(['data' => $product->variants()->where('is_active', true)->orderBy('variant_name')->limit(100)->get()->map(fn ($variant) => [
            'id' => $variant->id,
            'name' => $variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی',
            'sku' => $variant->variant_code,
            'sell_price' => (int) $variant->sell_price,
            'buy_price' => (int) $variant->buy_price,
            'stock' => (int) $variant->stock,
        ])]);
    }

    public function preview(Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.create'), 403);
        $data = $request->validate([
            'source_type' => ['required', Rule::in([SalesReturnDocument::SOURCE_INTERNAL_INVOICE, SalesReturnDocument::SOURCE_SAZEH_HESAB])],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.return_quantity' => ['required', 'integer', 'min:1'],
            'items.*.invoice_item_id' => ['nullable', 'integer', 'exists:invoice_items,id'],
            'items.*.refund_unit_price' => ['nullable', 'integer', 'min:1'],
            'items.*.item_condition' => ['required', Rule::in(['healthy', 'damaged'])],
        ]);

        if ($data['source_type'] === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
            $invoice = Invoice::query()->with(['items.product', 'items.variant'])->findOrFail((int) $data['invoice_id']);
            return response()->json($this->calculator->calculateInternalInvoicePreview($invoice, $data['items']));
        }

        return response()->json($this->calculator->calculateSazehPreview($data['items']));
    }
}
