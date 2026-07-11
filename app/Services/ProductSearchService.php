<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSearchService
{
    public function categoriesTree(): array
    {
        $cats = Category::query()->orderBy('name')->get(['id','name','parent_id']);
        return $cats->map(fn ($c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
            'descendant_ids' => Category::selfAndDescendantIds((int) $c->id),
        ])->values()->all();
    }

    public function products(Request $request)
    {
        $limit = min(100, max(1, (int) $request->integer('limit', 50)));
        $variantAgg = DB::table('product_variants')
            ->select('product_id')
            ->selectRaw('COUNT(*) variants_count')
            ->selectRaw('COALESCE(SUM(stock),0) total_stock')
            ->selectRaw('COALESCE(SUM(reserved),0) reserved_total')
            ->selectRaw('MIN(NULLIF(sell_price,0)) min_price')
            ->selectRaw('MAX(NULLIF(sell_price,0)) max_price')
            ->selectRaw('SUM(CASE WHEN sales_enabled = 0 OR is_active = 0 THEN 1 ELSE 0 END) disabled_variants_count')
            ->groupBy('product_id');

        $query = Product::query()
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($variantAgg, 'variant_summary', fn ($join) => $join->on('variant_summary.product_id', '=', 'products.id'))
            ->select([
                'products.id','products.name','products.short_barcode','products.code','products.sku','products.barcode',
                'products.image_path','products.category_id','products.stock','products.reserved','products.price','products.is_sellable','products.updated_at',
                'categories.name as category_name',
            ])
            ->selectRaw('COALESCE(variant_summary.variants_count,0) as variants_count')
            ->selectRaw('COALESCE(variant_summary.total_stock, products.stock, 0) as total_stock')
            ->selectRaw('COALESCE(variant_summary.reserved_total, products.reserved, 0) as reserved_total')
            ->selectRaw('COALESCE(variant_summary.min_price, NULLIF(products.price,0), 0) as min_price')
            ->selectRaw('COALESCE(variant_summary.max_price, NULLIF(products.price,0), 0) as max_price');

        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        return $query->cursorPaginate($limit)->withQueryString();
    }

    public function variants(Product $product, Request $request)
    {
        $limit = min(100, max(1, (int) $request->integer('limit', 50)));
        $query = $product->variants()
            ->leftJoin('model_lists', 'model_lists.id', '=', 'product_variants.model_list_id')
            ->select(['product_variants.id','product_variants.product_id','product_variants.variant_name','product_variants.variety_name','product_variants.variant_code','product_variants.variety_code','product_variants.stock','product_variants.reserved','product_variants.sell_price','product_variants.is_active','product_variants.sales_enabled','model_lists.model_name'])
            ->orderBy('product_variants.id');
        $q = self::normalize((string) $request->query('q', ''));
        if ($q !== '') $this->applyVariantSearch($query, $q);
        if ($request->query('stock_status') === 'in_stock') $query->where('product_variants.stock', '>', 0);
        if ($request->query('stock_status') === 'out_of_stock') $query->where('product_variants.stock', '<=', 0);
        if ($request->query('sellable_status') === 'sellable') $query->where('product_variants.sales_enabled', true)->where('product_variants.is_active', true);
        if ($request->query('sellable_status') === 'unsellable') $query->where(fn ($q) => $q->where('product_variants.sales_enabled', false)->orWhere('product_variants.is_active', false));
        return $query->cursorPaginate($limit)->withQueryString();
    }

    public static function normalize(string $value): string
    {
        $value = strtr($value, ['ي'=>'ی','ك'=>'ک','ۀ'=>'ه','‌'=>' ', '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value = mb_strtolower($value, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $categoryId = (int) ($request->query('subcategory_id') ?: $request->query('category_id'));
        if ($categoryId > 0) $query->whereIn('products.category_id', Category::selfAndDescendantIds($categoryId));
        $q = self::normalize((string) $request->query('q', ''));
        if ($q !== '') $this->applyProductSearch($query, $q);
        if ($request->query('stock_status') === 'in_stock') $query->whereRaw('COALESCE(variant_summary.total_stock, products.stock, 0) > 0');
        if ($request->query('stock_status') === 'out_of_stock') $query->whereRaw('COALESCE(variant_summary.total_stock, products.stock, 0) <= 0');
        if ($request->query('stock_status') === 'reserved') $query->whereRaw('COALESCE(variant_summary.reserved_total, products.reserved, 0) > 0');
        if ($request->query('sellable_status') === 'sellable') $query->where('products.is_sellable', true);
        if ($request->query('sellable_status') === 'unsellable') $query->where('products.is_sellable', false);
        if ($request->query('has_variants') === '1') $query->whereRaw('COALESCE(variant_summary.variants_count,0) > 0');
        if ($request->query('without_price') === '1') $query->whereRaw('COALESCE(variant_summary.max_price, products.price, 0) = 0');
        if ($request->filled('min_price')) $query->whereRaw('COALESCE(variant_summary.max_price, products.price, 0) >= ?', [(int) preg_replace('/\D+/', '', $request->query('min_price'))]);
        if ($request->filled('max_price')) $query->whereRaw('COALESCE(variant_summary.min_price, products.price, 0) <= ?', [(int) preg_replace('/\D+/', '', $request->query('max_price'))]);
    }

    private function applyProductSearch(Builder $query, string $q): void
    {
        $tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $query->where(function ($outer) use ($tokens) {
            foreach ($tokens as $token) {
                $like = '%' . addcslashes($token, '%_\\') . '%';
                $outer->where(function ($w) use ($like) {
                    foreach (['products.name','products.sku','products.barcode','products.short_barcode','products.code','categories.name'] as $col) $w->orWhere($col, 'like', $like);
                    $w->orWhereExists(function ($v) use ($like) {
                        $v->selectRaw('1')->from('product_variants')->leftJoin('model_lists','model_lists.id','=','product_variants.model_list_id')
                          ->whereColumn('product_variants.product_id','products.id')
                          ->where(fn ($vv) => $vv->where('product_variants.variant_name','like',$like)->orWhere('product_variants.variety_name','like',$like)->orWhere('product_variants.variant_code','like',$like)->orWhere('product_variants.variety_code','like',$like)->orWhere('model_lists.model_name','like',$like)->orWhere('model_lists.code','like',$like));
                    });
                });
            }
        });
    }

    private function applyVariantSearch(Builder $query, string $q): void
    {
        foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $like = '%' . addcslashes($token, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('product_variants.variant_name','like',$like)->orWhere('product_variants.variety_name','like',$like)->orWhere('product_variants.variant_code','like',$like)->orWhere('product_variants.variety_code','like',$like)->orWhere('model_lists.model_name','like',$like)->orWhere('model_lists.code','like',$like));
        }
    }

    private function applySort(Builder $query, Request $request): void
    {
        $direction = strtolower((string) $request->query('direction', $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $sorts = ['name'=>'products.name','short_barcode'=>'products.short_barcode','stock'=>'total_stock','price'=>'min_price','updated_at'=>'products.updated_at','id'=>'products.id'];
        $sort = $sorts[(string) $request->query('sort', 'id')] ?? 'products.id';
        $query->orderBy($sort, $direction)->orderBy('products.id', $direction);
    }

    public function productPayload($product): array
    {
        return [
            'id'=>(int)$product->id,'name'=>(string)$product->name,'short_code'=>$product->short_barcode ?: $product->code,
            'category'=>$product->category_name ?: 'بدون دسته‌بندی','category_path'=>$product->category_name ?: 'بدون دسته‌بندی',
            'variants_count'=>(int)$product->variants_count,'total_stock'=>(int)$product->total_stock,'reserved'=>(int)$product->reserved_total,
            'min_price'=>(int)$product->min_price,'max_price'=>(int)$product->max_price,'sales_enabled'=>(bool)$product->is_sellable,
            'image_url'=>$product->image_path ? route('products.image', $product->id) : null,
            'updated_at'=>optional($product->updated_at)->toISOString(),
            'routes'=>['edit'=>route('products.edit', ['product'=>$product->id]),'stock'=>route('products.warehouse-stock',$product->id),'sales_ledger'=>route('products.sales-ledger',$product->id),'purchase_ledger'=>route('products.purchase-ledger',$product->id),'deactivate'=>route('product-deactivation-documents.create',['product_id'=>$product->id])],
        ];
    }

    public function variantPayload($variant): array
    {
        return ['id'=>(int)$variant->id,'name'=>$variant->variant_name ?: $variant->variety_name,'sku'=>$variant->variant_code ?: $variant->variety_code,'barcode'=>$variant->variant_code,'model'=>$variant->model_name,'stock'=>(int)$variant->stock,'reserved'=>(int)$variant->reserved,'sell_price'=>(int)$variant->sell_price,'sales_enabled'=>(bool)$variant->sales_enabled && (bool)$variant->is_active,'routes'=>['edit'=>route('products.edit', $variant->product_id),'stock'=>route('products.warehouse-stock', ['product'=>$variant->product_id,'variant_id'=>$variant->id]),'sales_ledger'=>route('products.sales-ledger', ['product'=>$variant->product_id,'variant_id'=>$variant->id]),'purchase_ledger'=>route('products.purchase-ledger', ['product'=>$variant->product_id,'variant_id'=>$variant->id]),'deactivate'=>route('product-deactivation-documents.create',['product_id'=>$variant->product_id,'variant_id'=>$variant->id])]];
    }
}
