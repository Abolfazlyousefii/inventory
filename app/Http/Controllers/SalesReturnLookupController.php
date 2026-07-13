<?php
namespace App\Http\Controllers;

use App\Models\{Category,Customer,Invoice,Product,ProductVariant,SalesReturnDocument,WarehouseStock};
use App\Services\SalesReturnCalculationService;
use Illuminate\Http\Request;

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
            'total' => (int) $invoice->total,
            'items_count' => $invoice->items_count,
            'status' => $invoice->status,
            'status_label' => Invoice::statusLabels()[$invoice->status] ?? $invoice->status,
        ])->values()];
    }

    public function invoiceItems(Invoice $invoice, Request $request)
    {
        if ($request->filled('customer_id') && (int) $request->customer_id !== (int) $invoice->customer_id) {
            return response()->json(['message' => 'فاکتور متعلق به مشتری انتخاب‌شده نیست.'], 422);
        }
        $invoice->load('items.product', 'items.variant');
        $ids = $invoice->items->pluck('id')->all();
        $prev = $this->calculator->previouslyReturnedQuantities($ids);
        $alloc = $this->calculator->allocateInvoiceDiscount($invoice);

        return ['items' => $invoice->items->map(function ($item) use ($prev, $alloc) {
            $sold = (int) $item->quantity;
            $returned = (int) ($prev[$item->id] ?? 0);
            $returnable = max($sold - $returned, 0);
            $gross = $sold * (int) $item->price;
            $net = max($gross - (int) ($item->line_discount_amount ?? 0) - (int) ($alloc[$item->id] ?? 0), 0);
            $unit = $sold > 0 ? (int) floor($net / $sold) : 0;
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
                'returnable' => $returnable,
                'unit_price' => $unit,
                'line_discount' => (int) ($item->line_discount_amount ?? 0),
                'allocated_invoice_discount' => (int) ($alloc[$item->id] ?? 0),
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
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) return ['results' => []];

        $variants = ProductVariant::query()
            ->with(['product.category:id,name', 'modelList:id,brand,model_name,code'])
            ->where(function ($query) use ($q) {
                $query->where('variant_name', 'like', "%{$q}%")
                    ->orWhere('variant_code', 'like', "%{$q}%")
                    ->orWhere('variety_name', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")->orWhere('barcode', 'like', "%{$q}%"));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return ['results' => $variants->map(function ($v) {
            $stock = (int) WarehouseStock::where('product_variant_id', $v->id)->sum('quantity');
            return [
                'id' => $v->product_id,
                'variant_id' => $v->id,
                'text' => trim(($v->product?->name ?: '—').' / '.($v->variant_name ?: 'تنوع اصلی')),
                'product_name' => $v->product?->name,
                'variant_name' => $v->variant_name,
                'category' => $v->product?->category?->name,
                'model_list' => trim(($v->modelList?->brand ? $v->modelList->brand.' ' : '').($v->modelList?->model_name ?? '')),
                'sku' => $v->variant_code,
                'barcode' => $v->barcode,
                'stock' => $stock,
                'is_active' => (bool) $v->is_active,
                'sales_enabled' => (bool) $v->sales_enabled,
                'buy_price' => (int) $v->buy_price,
                'sell_price' => (int) $v->sell_price,
            ];
        })->values()];
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
