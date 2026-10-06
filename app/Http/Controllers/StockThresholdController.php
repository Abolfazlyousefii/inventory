<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockThresholdRule;
use App\Services\StockThresholdService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class StockThresholdController extends Controller
{
    public function index(Request $request, StockThresholdService $service)
    {
        $all = $service->alerts(false);
        $q = trim((string) $request->query('q', ''));
        $categoryIds = $request->integer('category_id') ? Category::selfAndDescendantIds($request->integer('category_id')) : [];
        $status = $request->query('status', 'all');
        $status = in_array($status, ['all', 'out', 'low', 'edge'], true) ? $status : 'all';
        $filtered = $all->filter(fn ($r) => (!$q || mb_stripos(($r['name'] ?? '').' '.($r['variant_name'] ?? '').' '.($r['code'] ?? ''), $q) !== false)
            && (!$categoryIds || in_array($r['category_id'], $categoryIds, true))
            && match ($status) {
                'out' => (int) $r['available'] === 0,
                'low' => (int) $r['available'] > 0 && (int) $r['available'] < (int) $r['minimum'],
                'edge' => (int) $r['available'] > 0 && (int) $r['available'] === (int) $r['minimum'],
                default => true,
            })->values();
        $page = max(1, $request->integer('page', 1));
        $alerts = new LengthAwarePaginator($filtered->forPage($page, 40)->values(), $filtered->count(), 40, $page,
            ['path' => $request->url(), 'query' => $request->query()]);
        $rules = StockThresholdRule::orderByDesc('id')->get();
        $categories = Category::orderBy('name')->get();
        $products = Product::whereIn('id', $rules->where('target_type', 'product')->pluck('target_id'))->pluck('name', 'id');
        $variants = ProductVariant::whereIn('id', $rules->where('target_type', 'variant')->pluck('target_id'))->with('product:id,name')->get()->keyBy('id');
        return view('stock-thresholds.index', compact('alerts', 'all', 'rules', 'categories', 'products', 'variants', 'q', 'status'));
    }

    public function search(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['product', 'variant'])], 'q' => ['required', 'string', 'min:2', 'max:100']]);
        $q = $data['q'];
        if ($data['type'] === 'product') {
            $rows = Product::where(fn ($b) => $b->where('name', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('sku', 'like', "%$q%"))
                ->orderBy('name')->limit(30)->get(['id', 'name', 'code'])->map(fn ($p) => ['id' => $p->id, 'name' => "$p->name ($p->code)"]);
        } else {
            $rows = ProductVariant::with('product:id,name')->where(fn ($b) => $b->where('variant_name', 'like', "%$q%")
                ->orWhere('variant_code', 'like', "%$q%")
                ->orWhere('variety_name', 'like', "%$q%")
                ->orWhere('variety_code', 'like', "%$q%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%$q%")))
                ->orderBy('id')->limit(30)->get()->map(fn ($v) => ['id' => $v->id, 'name' => ($v->product?->name ?? '').' / '.($v->variant_name ?: $v->variety_name).' ('.($v->variant_code ?: $v->variety_code).')']);
        }
        return response()->json(['items' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['target_type' => ['required', Rule::in(['category', 'product', 'variant'])],
            'target_id' => ['required', 'integer', 'min:1'], 'measure' => ['required', Rule::in(['product', 'variant'])],
            'minimum' => ['required', 'integer', 'min:0', 'max:100000000']]);
        $class = ['category' => Category::class, 'product' => Product::class, 'variant' => ProductVariant::class][$data['target_type']];
        abort_unless($class::whereKey($data['target_id'])->exists(), 422, 'کالا، تنوع یا دسته انتخاب‌شده معتبر نیست.');
        if ($data['target_type'] === 'variant') $data['measure'] = 'variant';
        StockThresholdRule::updateOrCreate(array_intersect_key($data, array_flip(['target_type', 'target_id', 'measure'])), ['minimum' => $data['minimum']]);
        Cache::forget(StockThresholdService::CACHE_KEY);
        return redirect()->route('stock-thresholds.index')->with('success', 'آستانه موجودی ذخیره شد.');
    }

    public function destroy(StockThresholdRule $rule)
    {
        $rule->delete();
        Cache::forget(StockThresholdService::CACHE_KEY);
        return redirect()->route('stock-thresholds.index')->with('success', 'آستانه حذف شد.');
    }

    public function daily(Request $request, StockThresholdService $service)
    {
        if (!Schema::hasTable('stock_threshold_alert_days')) return response()->json(['show' => false, 'count' => 0]);
        $today = now('Asia/Tehran')->toDateString();
        if (DB::table('stock_threshold_alert_days')->where('user_id', $request->user()->id)->where('alert_date', $today)->exists()) {
            return response()->json(['show' => false]);
        }
        $alerts = $service->alerts();
        $count = $alerts->pluck('product_id')->unique()->count();
        if (!$count) return response()->json(['show' => false, 'count' => 0]);
        $claimed = DB::table('stock_threshold_alert_days')->insertOrIgnore(['user_id' => $request->user()->id,
            'alert_date' => $today, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['show' => $claimed === 1, 'count' => $count, 'rows' => $alerts->count(), 'url' => route('stock-thresholds.index')]);
    }

    public function summary(StockThresholdService $service)
    {
        $alerts = $service->alerts();
        return response()->json(['count' => $alerts->pluck('product_id')->unique()->count(), 'rows' => $alerts->count()]);
    }
}
