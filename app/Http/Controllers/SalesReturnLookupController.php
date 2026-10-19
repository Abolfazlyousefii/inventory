<?php
namespace App\Http\Controllers;

use App\Models\{Category,Customer,Invoice,Product,ProductVariant,SalesReturnDocument,WarehouseStock};
use App\Services\SalesReturnCalculationService;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;

class SalesReturnLookupController extends Controller
{
    public function __construct(private SalesReturnCalculationService $calculator) {}

    public function customers(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $rows = Customer::query()
            ->when(mb_strlen($q) >= 2, function ($customerQuery) use ($q) {
                $customerQuery->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) like ?", ["%{$q}%"])
                    ->orWhere('mobile', 'like', "%{$q}%")
                    ->orWhere('crm_customer_id', 'like', "%{$q}%");
                if (ctype_digit($q)) $query->orWhere('id', (int) $q);
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'mobile', 'crm_customer_id']);

        return ['data' => $rows->map(fn ($c) => [
            'id' => $c->id,
            'text' => trim($c->first_name.' '.$c->last_name),
            'name' => trim($c->first_name.' '.$c->last_name),
            'mobile' => $c->mobile,
            'customer_code' => $c->crm_customer_id ?: (string) $c->id,
        ])->values()];
    }

    public function customerInvoices(Customer $customer, Request $request)
    {
        $canOverride = $request->user()?->can('sales_returns.override_invoice_status') ?? false;
        $query = Invoice::withCount('items')
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [Invoice::STATUS_NOT_SHIPPED, 'draft', 'cancelled', 'canceled']);
        if (! $canOverride) $query->where('status', Invoice::STATUS_SHIPPED);

        return ['invoices' => $query->latest('id')->limit(50)->get()->map(fn ($invoice) => [
            'id' => $invoice->id,
            'number' => $invoice->uuid,
            'uuid' => $invoice->uuid,
            'date' => $invoice->created_at?->format('Y-m-d'),
            'date_fa' => $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d') : null,
            'invoice_date' => $invoice->created_at?->format('Y-m-d'),
            'invoice_date_fa' => $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d') : null,
            'amount' => (int) $invoice->total,
            'amount_fa' => $invoice->total !== null ? strtr(number_format((int) $invoice->total), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']) : null,
            'total' => (int) $invoice->total,
            'items_count' => $invoice->items_count,
            'status' => $invoice->status,
            'status_label' => Invoice::statusLabels()[$invoice->status] ?? $invoice->status,
        ])->values()];
    }

    public function invoiceItems(Invoice $invoice, Request $request)
    {
        $documentId = $request->query('document_id');
        if ($documentId !== null && $documentId !== ''
            && (! is_scalar($documentId) || filter_var($documentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            return response()->json(['message' => 'شناسه سند برگشت نامعتبر است.'], 422);
        }

        if ($request->filled('customer_id') && (int) $request->customer_id !== (int) $invoice->customer_id) {
            return response()->json(['message' => 'فاکتور متعلق به مشتری انتخاب‌شده نیست.'], 422);
        }
        $invoice->load('items.product', 'items.variant');
        $ids = $invoice->items->pluck('id')->all();
        $excludeId = null;
        if ($documentId !== null && $documentId !== '') {
            $editing = \App\Models\SalesReturnDocument::whereKey((int) $documentId)->where('invoice_id', $invoice->id)->firstOrFail();
            $editRoute = $editing->isApplied() ? 'vouchers.return-from-sale.applied.edit' : 'vouchers.return-from-sale.edit';
            abort_unless(($editing->isDraft() || $editing->isPendingWarehouse() || $editing->isApplied()) && $request->user() && \App\Support\PageAccessCatalog::userCanRoute($request->user(), $editRoute), 403);
            $excludeId = (int) $editing->id;
        }
        $prev = $this->calculator->previouslyReturnedQuantities($ids, $excludeId);
        $prevAmounts = $this->calculator->previouslyReturnedAmounts($ids, $excludeId);
        $breakdowns = $this->calculator->invoiceItemBreakdowns($invoice);

        return ['items' => $invoice->items->map(function ($item) use ($prev, $prevAmounts, $breakdowns) {
            $sold = (int) $item->quantity;
            $returned = (int) ($prev[$item->id] ?? 0);
            $returnable = max($sold - $returned, 0);
            $breakdown = $breakdowns[$item->id];
            return [
                'invoice_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'variant_id' => $item->variant_id,
                'variant_name' => $item->variant?->variant_name,
                'sku' => $item->variant?->variant_code,
                'barcode' => $item->variant?->barcode,
                'quantity' => $sold,
                'previously_returned' => $returned,
                'previously_returned_amount' => (int) ($prevAmounts[$item->id] ?? 0),
                'returnable' => $returnable,
                'unit_price' => $breakdown['net_refund_unit_price'],
                'historical_unit_price' => $breakdown['historical_unit_price'],
                'gross_amount' => $breakdown['gross_amount'],
                'line_discount' => $breakdown['line_discount_total'],
                'line_discount_unit' => $breakdown['line_discount_unit'],
                'allocated_invoice_discount' => $breakdown['allocated_invoice_discount_total'],
                'allocated_invoice_discount_unit' => $breakdown['allocated_invoice_discount_unit'],
                'net_refund_total' => $breakdown['net_refund_total'],
                'net_refund_unit_price' => $breakdown['net_refund_unit_price'],
                'disabled' => $returnable <= 0,
            ];
        })->values()];
    }

    public function categories(Request $request)
    {
        $parentId = $request->query('parent_id');
        $query = Category::query()->withCount('products')->orderBy('name');
        $parentId === null || $parentId === '' ? $query->whereNull('parent_id') : $query->where('parent_id', (int) $parentId);
        return ['data' => $query->limit(100)->get(['id', 'name', 'code', 'parent_id'])->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'code' => $category->code,
            'parent_id' => $category->parent_id,
            'products_count' => $category->products_count,
        ])->values()];
    }

    public function categoryProducts(Category $category, Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $products = Product::query()->with(['category:id,name', 'variants:id,product_id,variant_name,variant_code,is_active,sales_enabled,buy_price,sell_price,stock,reserved,model_list_id,variety_name,variety_code'])
            ->withCount('variants')
            ->where('category_id', $category->id)
            ->when($q !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%")))
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        return ['data' => $products->map(fn ($product) => [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code ?: $product->sku,
            'category' => $product->category?->name,
            'is_active' => (bool) ($product->is_active ?? true),
            'sales_enabled' => (bool) ($product->is_sellable ?? true),
            'variants_count' => $product->variants_count,
        ])->values()];
    }

    public function products(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $q = \App\Support\ProductFinderSearchNormalizer::normalize($request->query('q'));
        $compact = (string) preg_replace('/[\s\-_]+/u', '', $q);
        if (mb_strlen($compact) < 2) {
            return ['results' => [], 'meta' => ['page' => 1, 'has_more' => false]];
        }

        $page = max(1, (int) $request->integer('page', 1));
        $like = '%'.$q.'%';
        $compactLike = '%'.$compact.'%';

        // An external sales return must find even zero-stock or inactive variants.
        // Model-list matching is essential when the variant name only contains a color.
        $variants = ProductVariant::query()
            ->select('product_variants.*')
            ->leftJoin('model_lists as return_models', 'return_models.id', '=', 'product_variants.model_list_id')
            ->with(['product.category:id,name', 'modelList:id,brand,model_name,code'])
            ->where(function ($query) use ($like, $compactLike) {
                $query->where('product_variants.variant_name', 'like', $like)
                    ->orWhere('product_variants.variety_name', 'like', $like)
                    ->orWhere('product_variants.variant_code', 'like', $like)
                    ->orWhere('product_variants.variety_code', 'like', $like)
                    ->orWhere('return_models.model_name', 'like', $like)
                    ->orWhere('return_models.brand', 'like', $like)
                    ->orWhere('return_models.code', 'like', $like)
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(COALESCE(return_models.model_name,''), ' ', ''), '-', ''), '_', '')) LIKE ?", [$compactLike])
                    ->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(COALESCE(product_variants.variant_name,''), ' ', ''), '-', ''), '_', '')) LIKE ?", [$compactLike])
                    ->orWhereHas('product', function ($product) use ($like, $compactLike) {
                        $product->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('barcode', 'like', $like)
                            ->orWhere('short_barcode', 'like', $like)
                            ->orWhereRaw("LOWER(REPLACE(REPLACE(REPLACE(COALESCE(products.name,''), ' ', ''), '-', ''), '_', '')) LIKE ?", [$compactLike]);
                    });
            })
            ->orderByRaw("CASE
                WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(return_models.model_name,''), ' ', ''), '-', ''), '_', '')) = ? THEN 0
                WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(return_models.model_name,''), ' ', ''), '-', ''), '_', '')) LIKE ? THEN 1
                WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(product_variants.variant_name,''), ' ', ''), '-', ''), '_', '')) LIKE ? THEN 2
                ELSE 3 END", [$compact, $compactLike, $compactLike])
            ->orderBy('product_variants.id')
            ->simplePaginate(40, ['product_variants.*'], 'page', $page);

        return [
            'results' => collect($variants->items())->map(fn (ProductVariant $v) => [
                'id' => (int) $v->product_id,
                'variant_id' => (int) $v->id,
                'text' => trim(($v->product?->name ?: '—').' / '.($v->variant_name ?: 'تنوع اصلی')),
                'product_name' => $v->product?->name,
                'variant_name' => $v->variant_name ?: $v->variety_name,
                'category' => $v->product?->category?->name,
                'model_list' => trim(($v->modelList?->brand ? $v->modelList->brand.' ' : '').($v->modelList?->model_name ?? '')),
                'sku' => $v->variant_code ?: $v->variety_code,
                'barcode' => $v->barcode,
                // This is display-only central available stock, not a return eligibility check.
                'stock' => (int) $v->stock,
                'is_active' => (bool) $v->is_active,
                'sales_enabled' => (bool) $v->sales_enabled,
                'buy_price' => (int) $v->buy_price,
                'sell_price' => (int) $v->sell_price,
            ])->values()->all(),
            'meta' => [
                'page' => $variants->currentPage(),
                'has_more' => $variants->hasMorePages(),
            ],
        ];
    }

    public function variants(\App\Models\Product $product)
    {
        $product->load('variants.warehouseStocks.warehouse');
        return ['product' => ['id' => $product->id, 'name' => $product->name, 'code' => $product->code ?: $product->sku], 'variants' => $product->variants->map(fn ($v) => ['id' => $v->id, 'name' => $v->variant_name, 'variety_name' => $v->variety_name, 'variety_code' => $v->variety_code, 'sku' => $v->variant_code, 'barcode' => $v->barcode, 'stock' => (int) $v->stock, 'reserved' => (int) $v->reserved, 'buy_price' => (int) $v->buy_price, 'sell_price' => (int) $v->sell_price, 'is_active' => (bool) $v->is_active, 'sales_enabled' => (bool) $v->sales_enabled, 'stocks' => $v->warehouseStocks->map(fn ($s) => ['warehouse' => $s->warehouse?->name, 'quantity' => (int) $s->quantity])])];
    }

    public function preview(Request $request)
    {
        if ($request->input('source_type') === SalesReturnDocument::SOURCE_INTERNAL_INVOICE) {
            $invoice = Invoice::with('items')->findOrFail((int) $request->input('invoice_id'));
            return ['items' => $this->calculator->calculateInternalPreview($invoice, $request->input('items', []))];
        }
        return ['items' => $this->calculator->calculateSazehPreview($request->input('items', []))];
    }
}
