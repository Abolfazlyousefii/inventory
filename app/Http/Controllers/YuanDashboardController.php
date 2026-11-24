<?php

namespace App\Http\Controllers;

use App\Models\{CnyExchangeRate,Product,ProductCnyPricing};
use App\Services\{CnyPriceCalculator,CnyPricingService};
use App\Services\ExchangeRates\{NavasanRateFetcher,NobitexRateFetcher};
use App\Support\PermissionCatalog;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

final class YuanDashboardController extends Controller
{
    public function __construct(private readonly CnyPricingService $pricing, private readonly CnyPriceCalculator $calculator) {}

    public function index(): View
    {
        $rate = $this->pricing->previewRate();
        $stats = [
            'total' => ProductCnyPricing::count(),
            'active' => ProductCnyPricing::where('enabled', true)->count(),
            'paused' => ProductCnyPricing::where('enabled', false)->count(),
        ];
        return view('products.yuan.index', compact('rate', 'stats'));
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:120'],
            'status' => ['nullable','in:all,active,paused'],
            'sort' => ['nullable','in:latest,name,price'],
            'page' => ['nullable','integer','min:1','max:100000'],
        ]);
        $query = ProductCnyPricing::query()->with(['product:id,name,sku,code,image_path,category_id,price', 'product.category:id,name', 'appliedRate:id,rate_rial,quoted_at']);
        if (($filters['status'] ?? 'all') === 'active') $query->where('enabled', true);
        if (($filters['status'] ?? 'all') === 'paused') $query->where('enabled', false);
        if ($term = trim((string)($filters['q'] ?? ''))) {
            $query->whereHas('product', fn ($q) => $q->where(function ($p) use ($term) {
                $p->where('name','like','%'.$term.'%')->orWhere('sku','like','%'.$term.'%')->orWhere('code','like','%'.$term.'%');
            }));
        }
        $sort = $filters['sort'] ?? 'latest';
        if ($sort === 'name') $query->join('products as ordered_products','ordered_products.id','=','product_cny_pricings.product_id')
            ->select('product_cny_pricings.*')->orderBy('ordered_products.name')->orderBy('product_cny_pricings.id');
        elseif ($sort === 'price') $query->join('products as ordered_products','ordered_products.id','=','product_cny_pricings.product_id')
            ->select('product_cny_pricings.*')->orderByDesc('ordered_products.price')->orderBy('product_cny_pricings.id');
        else $query->orderByDesc('product_cny_pricings.updated_at')->orderByDesc('product_cny_pricings.id');
        $canEditPurchases = PermissionCatalog::userHasPermission($request->user(), 'stock_in.edit');
        if ($canEditPurchases) {
            // Use the newest Yuan purchase containing this product; never link to an unrelated or domestic document.
            $query->addSelect(['edit_purchase_id' => DB::table('purchase_items')
                ->select('purchase_items.purchase_id')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->whereColumn('purchase_items.product_id', 'product_cny_pricings.product_id')
                ->whereNotNull('purchase_items.buy_cny_units')
                ->where('purchase_items.buy_cny_units', '>', 0)
                ->where('purchases.purchase_origin', 'china')
                ->orderByDesc('purchases.purchased_at')->orderByDesc('purchases.id')->limit(1)]);
        }
        $page = $query->paginate(50);
        $rate = $this->pricing->previewRate();
        $rows = $page->getCollection()->filter(fn ($policy) => $policy->product !== null)->map(function ($policy) use ($rate, $canEditPurchases) {
            $product = $policy->product;
            $applied = $policy->appliedRate;
            $quoted = $applied ? $this->calculator->calculate($this->calculator->amount($policy->buy_cny_units), (int)$applied->rate_rial) : null;
            // A paused policy keeps its original quote for historical context, not for applying changes.
            $proposal = $rate && $policy->enabled
                ? $this->calculator->calculate($this->calculator->amount($policy->buy_cny_units),(int)$rate['rate_rial']) : null;
            return [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku ?: $product->code,
                'category' => $product->category?->name ?? '—',
                'image' => $product->image_path ? asset('storage/'.ltrim($product->image_path,'/')) : null,
                'buy_cny' => $this->calculator->amount($policy->buy_cny_units),
                'cost_toman' => $quoted ? intdiv($quoted['buy_rial'],10) : null,
                'applied_rate_toman' => $applied ? intdiv((int)$applied->rate_rial,10) : null,
                'sale_toman' => intdiv((int)$product->price,10),
                'profit_percent' => $quoted && $quoted['buy_rial'] > 0
                    ? round(100 * ((int)$product->price - $quoted['buy_rial']) / max(1,(int)$product->price),1) : null,
                'proposed_toman' => $proposal ? intdiv($proposal['sell_rial'],10) : null,
                'enabled' => (bool)$policy->enabled, 'updated_at' => $policy->updated_at?->toIso8601String(),
                'purchase_edit_url' => $canEditPurchases && $policy->edit_purchase_id
                    ? route('purchases.edit', ['purchase' => $policy->edit_purchase_id, 'product_id' => $product->id]) : null,
            ];
        })->values();
        return response()->json(['rows' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'rate' => $rate]);
    }

    public function history(Product $product, Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable','integer','in:7,30,90']]);
        $days = (int)($validated['days'] ?? 30);
        $policy = ProductCnyPricing::where('product_id',$product->id)->with('appliedRate')->firstOrFail();
        $since = now()->subDays($days);
        $revisions = DB::table('cny_price_revisions as rev')->join('cny_exchange_rates as rates','rates.id','=','rev.rate_id')
            ->where('rev.product_id',$product->id)->where('rev.created_at','>=',$since)
            ->orderBy('rev.created_at')->orderBy('rev.id')
            ->get(['rev.created_at','rev.old_price','rev.new_price','rates.rate_rial','rates.provider']);
        // Include the price immediately preceding the chosen window; otherwise a flat-price
        // period incorrectly appears to have no price history.
        $baseline = DB::table('cny_price_revisions')->where('product_id',$product->id)
            ->where('created_at','<',$since)->latest('created_at')->first(['new_price']);
        $rateRows = CnyExchangeRate::query()->where('quoted_at','>=',$since)
            ->when(config('nobitex.enabled'),fn ($q) => $q->where('provider','nobitex_usdt_proxy'))
            ->when(!config('nobitex.enabled') && config('navasan.enabled'),fn ($q) => $q->where('provider','navasan'))
            ->orderByDesc('quoted_at')->orderByDesc('id')->limit(1000)->get(['rate_rial','quoted_at','provider'])->reverse()->values();
        $pricePoints = [];
        if ($baseline || $revisions->isNotEmpty()) $pricePoints[] = ['time' => $since->toIso8601String(), 'value' => intdiv((int)($baseline?->new_price ?? $revisions->first()->old_price),10)];
        foreach ($revisions as $revision) $pricePoints[] = [
            'time' => \Carbon\Carbon::parse($revision->created_at)->toIso8601String(),
            'value' => intdiv((int)$revision->new_price,10),
        ];
        $pricePoints[] = ['time' => now()->toIso8601String(), 'value' => intdiv((int)$product->price,10)];
        return response()->json([
            'product' => ['id'=>$product->id, 'name'=>$product->name, 'price_toman'=>intdiv((int)$product->price,10),
                'buy_cny'=>$this->calculator->amount($policy->buy_cny_units), 'enabled'=>(bool)$policy->enabled],
            'prices' => $pricePoints,
            'rates' => $rateRows->map(fn ($rate) => ['time'=>$rate->quoted_at->toIso8601String(),'value'=>intdiv((int)$rate->rate_rial,10)])->values(),
            'price_changes' => $revisions->count(),
        ]);
    }

    public function fetchRate(): JsonResponse
    {
        try {
            $fetcher = config('nobitex.enabled') ? app(NobitexRateFetcher::class) : app(NavasanRateFetcher::class);
            $result = $fetcher->fetch(); // Existing validator, quota guard and provider lock.
            return response()->json(['message'=>'نرخ جدید دریافت شد. قیمت کالاها هنوز تغییر نکرده است.',
                'rate_toman'=>intdiv((int)$result['rate']->rate_rial,10), 'rate_id'=>$result['rate']->id]);
        } catch (Throwable $error) {
            report($error);
            return response()->json(['message'=>'دریافت نرخ انجام نشد. اتصال یا پیکربندی منبع نرخ را بررسی کنید.'],422);
        }
    }

    public function preview(): JsonResponse
    {
        return response()->json($this->pricing->previewReprice());
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rate_id' => ['required','integer','min:1'],
            'confirmed' => ['required','accepted'],
        ]);
        $this->pricing->currentRate((int)$validated['rate_id']);
        $count = $this->pricing->reprice();
        return response()->json(['changed'=>$count, 'message'=>'به‌روزرسانی قیمت‌ها تکمیل شد.']);
    }
}
