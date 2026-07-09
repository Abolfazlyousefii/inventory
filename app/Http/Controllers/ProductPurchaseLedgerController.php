<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProductPurchaseLedgerController extends Controller
{
    public function purchaseLedger(Product $product, Request $request)
    {
        $variantId = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
        $selectedVariant = null;

        if ($variantId) {
            $selectedVariant = $product->variants()->whereKey($variantId)->firstOrFail();
        }

        $query = PurchaseItem::query()
            ->select('purchase_items.*')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where(function ($productQuery) use ($product) {
                $productQuery->where('purchase_items.product_id', $product->id)
                    ->orWhere('purchase_items.product_name', $product->name);
            })
            ->with([
                'purchase:id,supplier_id,user_id,purchased_at,created_at',
                'purchase.supplier:id,name',
                'purchase.user:id,name',
                'variant:id,product_id,variant_name,variant_code',
            ]);

        if ($selectedVariant) {
            $query->where('purchase_items.product_variant_id', $selectedVariant->id);
        }

        if ($request->filled('date_from')) {
            $query->where('purchases.purchased_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('purchases.purchased_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($q) {
                $builder->where('purchases.id', 'like', "%{$q}%")
                    ->orWhere('purchase_items.product_name', 'like', "%{$q}%")
                    ->orWhere('purchase_items.variant_name', 'like', "%{$q}%")
                    ->orWhere('purchase_items.product_code', 'like', "%{$q}%")
                    ->orWhere('purchases.note', 'like', "%{$q}%");
            });
        }

        $summaryQuery = PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where(function ($productQuery) use ($product) {
                $productQuery->where('purchase_items.product_id', $product->id)
                    ->orWhere('purchase_items.product_name', $product->name);
            });

        if ($selectedVariant) {
            $summaryQuery->where('purchase_items.product_variant_id', $selectedVariant->id);
        }
        if ($request->filled('date_from')) {
            $summaryQuery->where('purchases.purchased_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $summaryQuery->where('purchases.purchased_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $summaryQuery->where(function ($builder) use ($q) {
                $builder->where('purchases.id', 'like', "%{$q}%")
                    ->orWhere('purchase_items.product_name', 'like', "%{$q}%")
                    ->orWhere('purchase_items.variant_name', 'like', "%{$q}%")
                    ->orWhere('purchase_items.product_code', 'like', "%{$q}%")
                    ->orWhere('purchases.note', 'like', "%{$q}%");
            });
        }

        $summary = $summaryQuery
            ->selectRaw('COALESCE(SUM(purchase_items.quantity), 0) as total_quantity')
            ->selectRaw('COALESCE(SUM(purchase_items.line_total), 0) as total_amount')
            ->selectRaw('COALESCE(AVG(purchase_items.buy_price), 0) as avg_buy_price')
            ->first();

        $lastItem = (clone $query)->orderByDesc('purchases.purchased_at')->orderByDesc('purchase_items.id')->first();

        $ledgerItems = $query->orderByDesc('purchases.purchased_at')->orderByDesc('purchase_items.id')->paginate(25)->withQueryString();
        $variants = $product->variants()->orderBy('variant_code')->get(['id', 'variant_name', 'variant_code', 'is_active']);

        return view('products.purchase-ledger', compact('product', 'selectedVariant', 'variants', 'ledgerItems', 'summary', 'lastItem'));
    }
}
