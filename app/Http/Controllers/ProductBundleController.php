<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\ProductBundleService;
use App\Services\WarehouseStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\BundleAssembly;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Bundle management belongs to the existing Inventory products screen. */
class ProductBundleController extends Controller
{
    /** Search actual existing variants; no manually entered IDs or new catalog objects. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'exclude_variant_id' => ['nullable', 'integer', 'min:1'],
            'complete_products' => ['sometimes', 'boolean'],
        ]);
        $term = trim($data['q']);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $warehouseId = WarehouseStockService::centralWarehouseId();
        $eligible = ProductVariant::query()
            ->with('product:id,name,code,sku,is_bundle')
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_bundle', false)->where('is_sellable', true))
            ->when($data['exclude_variant_id'] ?? null, fn ($query, $id) => $query->where('id', '!=', $id))
            ->whereIn('id', function ($query) use ($warehouseId) {
                $query->select('product_variant_id')
                    ->from('warehouse_stocks')
                    ->where('warehouse_id', $warehouseId)
                    ->groupBy('product_variant_id')
                    ->havingRaw('SUM(quantity) > 0');
            });
        $matching = (clone $eligible)->where(function ($query) use ($like) {
                $query->where('variant_name', 'like', $like)
                    ->orWhere('variant_code', 'like', $like)
                    ->orWhere('variety_name', 'like', $like)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('sku', 'like', $like));
            });
        if ($request->boolean('complete_products')) {
            // Limit products, not variants: a product checkbox must represent its complete eligible group.
            $productIds = (clone $matching)->select('product_id')->distinct()
                ->orderBy('product_id')->limit(20)->pluck('product_id');
            $variants = $eligible->whereIn('product_id', $productIds)
                ->orderBy('product_id')->orderBy('id')->get();
        } else {
            $variants = $matching->orderBy('product_id')->orderBy('id')->limit(100)->get();
        }

        $stocks = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(quantity) as available')
            ->pluck('available', 'product_variant_id');

        return response()->json(['results' => $variants->map(fn (ProductVariant $variant) => [
            'id' => (int) $variant->id,
            'product_id' => (int) $variant->product_id,
            'sell_price' => \App\Services\BundleDiscountService::effectiveComponentPrice($variant),
            'name' => (string) ($variant->product?->name ?? '—'),
            'variant' => (string) ($variant->variant_name ?: $variant->variety_name ?: 'تنوع اصلی'),
            'code' => (string) ($variant->variant_code ?: $variant->product?->code ?: ''),
            'stock' => (int) ($stocks[$variant->id] ?? 0),
        ])->values()]);
    }

    public function recipe(Request $request, Product $product, ProductBundleService $service): RedirectResponse
    {
        \App\Support\BundleFormPayload::merge($request, ['components']);
        // Optional empty editor rows are not recipe components.
        $request->merge(['components' => array_values(array_filter(
            (array) $request->input('components', []),
            fn ($row) => is_array($row) && (($row['variant_id'] ?? '') !== '' || ($row['quantity'] ?? '') !== '')
        ))]);
        $payload = $request->validate([
            'bundle_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'components' => ['required', 'array', 'min:1', 'max:'.\App\Support\BundleRecipe::MAX_COMPONENTS],
            'components.*.variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'components.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'components.*.discount_value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $this->ensureVariantBelongsToProduct($product, (int) $payload['bundle_variant_id']);
        // Changing units per pack changes the original total: validate the saved discount first.
        if ($product->is_bundle && $product->bundle_stock_mode === 'automatic'
            && $product->bundle_discount_type !== null && $product->bundle_discount_value !== null) {
            $discount = app(\App\Services\BundleDiscountService::class);
            $discount->quoteForComponents($payload['components'], (string) $product->bundle_discount_type, (int) $product->bundle_discount_value);
        }
        $service->defineRecipe((int) $payload['bundle_variant_id'], $payload['components']);
        if ($product->is_bundle && $product->bundle_stock_mode === 'automatic'
            && $product->bundle_discount_type !== null && $product->bundle_discount_value !== null) {
            $quote = app(\App\Services\BundleDiscountService::class)->quoteForBundle(
                (int) $payload['bundle_variant_id'], (string) $product->bundle_discount_type, (int) $product->bundle_discount_value
            );
            $product->forceFill(['price' => $quote['final']])->save();
            ProductVariant::query()->whereKey((int) $payload['bundle_variant_id'])->update(['sell_price' => $quote['final']]);
        }
        $this->syncBundleToSite($product->id);

        return back()->with('success', $product->bundle_stock_mode === 'automatic' ? 'ترکیب بسته خودکار ذخیره شد؛ موجودی قابل فروش بر اساس سهمیه اختصاصی قطعات محاسبه می‌شود.' : 'ترکیب بسته ذخیره شد؛ اکنون می‌توانید بسته آماده مونتاژ کنید.');
    }

    public function assemble(Request $request, Product $product, ProductBundleService $service): RedirectResponse
    {
        $payload = $this->validateMovement($request);
        $this->ensureVariantBelongsToProduct($product, (int) $payload['bundle_variant_id']);
        $service->assemble((int) $payload['bundle_variant_id'], (int) $payload['quantity'],
            WarehouseStockService::centralWarehouseId(), (int) $request->user()->id);
        $this->syncBundleToSite((int) $product->id);

        return back()->with('success', 'موجودی قطعات به بسته‌های آماده تبدیل شد.');
    }

    public function disassemble(Request $request, Product $product, ProductBundleService $service): RedirectResponse
    {
        $payload = $this->validateMovement($request);
        $this->ensureVariantBelongsToProduct($product, (int) $payload['bundle_variant_id']);
        $service->disassemble((int) $payload['bundle_variant_id'], (int) $payload['quantity'],
            WarehouseStockService::centralWarehouseId(), (int) $request->user()->id);
        $this->syncBundleToSite((int) $product->id);

        return back()->with('success', 'بسته‌های آماده با ثبت سند به قطعات اصلی بازگردانده شدند.');
    }

    public function mode(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['bundle_stock_mode' => ['required', 'in:automatic,physical']]);
        if (!$product->is_bundle || $product->variants()->count() !== 1) {
            throw ValidationException::withMessages(['bundle_stock_mode' => 'ابتدا یک بسته با یک تنوع تعریف کنید.']);
        }
        $variantId = (int) $product->variants()->value('id');
        if ($product->bundle_stock_mode === $data['bundle_stock_mode']) return back();
        if (DB::table('bundle_component_allocations')->where('bundle_variant_id', $variantId)->where('remaining', '>', 0)->exists()
            || BundleAssembly::query()->where('bundle_variant_id', $variantId)->exists()
            || WarehouseStock::query()->where('product_variant_id', $variantId)->where('quantity', '>', 0)->exists()
            || DB::table('invoice_items')->where('variant_id', $variantId)->exists()
            || DB::connection('site')->table('order_items')->where('price_id', $variantId)->exists()) {
            throw ValidationException::withMessages(['bundle_stock_mode' => 'حالت بسته‌ای که موجودی آماده یا سابقه فروش/مونتاژ دارد قابل تغییر نیست.']);
        }
        if (DB::connection('site')->table('products')->where('id', $product->id)->where('published', 1)->exists()) {
            throw ValidationException::withMessages(['bundle_stock_mode' => 'ابتدا بسته را در سایت از حالت انتشار خارج کنید، سپس دوباره تلاش کنید.']);
        }
        DB::transaction(function () use ($product, $data) {
            Product::query()->whereKey($product->id)->lockForUpdate()->update(['bundle_stock_mode' => $data['bundle_stock_mode']]);
        });
        $this->syncBundleToSite((int) $product->id);
        return back()->with('success', 'روش موجودی بسته تغییر کرد؛ اطلاعات سایت نیز برای همگام‌سازی ارسال شد.');
    }

    /** Publication for automatic bundles is controlled in Inventory, not Site Admin. */
    public function sitePublication(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        if (!$product->is_bundle || $product->bundle_stock_mode !== 'automatic'
            || $product->variants()->count() !== 1) {
            throw ValidationException::withMessages(['enabled' => 'مدیریت انتشار از این بخش فقط برای بسته خودکار است.']);
        }
        $enable = (bool) $data['enabled'];
        if ($enable) {
            $variantId = (int) $product->variants()->value('id');
            if (!DB::table('product_bundle_components')->where('bundle_variant_id', $variantId)->exists()
                || (int) $product->variants()->value('sell_price') <= 0) {
                throw ValidationException::withMessages(['enabled' => 'برای انتشار ابتدا ترکیب و قیمت معتبر بسته را ذخیره کنید.']);
            }
        } else {
            // Hide on the Site before enabling recipe edits; never expose unprotected stock.
            DB::connection('site')->table('products')->where('id', $product->id)->update([
                'published' => 0, 'publish_date' => null,
            ]);
        }
        $product->forceFill(['bundle_site_auto_publish' => $enable])->save();
        $synced = $this->syncBundleToSite((int) $product->id);
        return back()->with($synced ? 'success' : 'warning', $enable
            ? ($synced ? 'بسته در سایت منتشر شد. دیگر نیازی به تأیید جداگانه در سایت نیست.' : 'درخواست انتشار ثبت شد، اما ارتباط با سایت برقرار نشد؛ همگام‌سازی دوره‌ای دوباره تلاش می‌کند.')
            : 'نمایش بسته در سایت متوقف شد. برای تغییر ترکیب، ابتدا سهمیه قطعات را صفر کنید.');
    }

    /** An edit is saved in Inventory first; sync failures must never encourage duplicate submission. */
    private function syncBundleToSite(int $productId): bool
    {
        try {
            app(\App\Services\Sync\SyncProductsToSite::class)->syncProduct($productId);
            return true;
        } catch (\Throwable $e) {
            Log::error('Immediate bundle Site synchronization failed', [
                'product_id' => $productId, 'error' => $e->getMessage(),
            ]);
            session()->flash('warning', 'اطلاعات در انبار ذخیره شد اما ارسال فوری به سایت انجام نشد؛ سرویس همگام‌سازی دوره‌ای را بررسی کنید.');
            return false;
        }
    }

    /** Save bundle discount from Inventory only. Website price is a synced read-only projection. */
    public function pricing(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'bundle_discount_type' => ['required', 'in:percent,final'],
            'bundle_discount_value' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        if (!$product->is_bundle || $product->bundle_stock_mode !== 'automatic' || $product->variants()->count() !== 1
            || ($product->bundle_quantity_mode ?? 'shared') !== 'shared') {
            throw ValidationException::withMessages(['bundle_discount_value' => 'این تنظیم فقط برای بسته خودکار با موجودی یکسان مجاز است.']);
        }
        DB::transaction(function () use ($product, $data) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::query()->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $quote = app(\App\Services\BundleDiscountService::class)->quoteForBundle(
                (int) $variant->id, $data['bundle_discount_type'], (int) $data['bundle_discount_value']
            );
            $locked->forceFill([
                'bundle_discount_type' => $data['bundle_discount_type'],
                'bundle_discount_value' => (int) $data['bundle_discount_value'],
                'price' => $quote['final'],
            ])->save();
            $variant->forceFill(['sell_price' => $quote['final']])->save();
        }, 3);
        $this->syncBundleToSite($product->id);
        return back()->with('success', 'تخفیف بسته ذخیره شد. قیمت و تخفیف سایت پس از همگام‌سازی بعدی به‌روز می‌شود.');
    }

    /** Allocate real, protected stock to the bundle. Not a virtual sales cap. */
    public function policy(Request $request, Product $product): RedirectResponse
    {
        \App\Support\BundleFormPayload::merge($request, ['allocations', 'expected_allocations']);
        $data = $request->validate([
            'bundle_quantity_mode' => ['required', 'in:shared'],
            'bundle_shared_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'allocations' => ['nullable', 'array', 'max:'.\App\Support\BundleRecipe::MAX_COMPONENTS],
            'allocations.*' => ['required', 'integer', 'min:0', 'max:1000000'],
            'expected_allocations' => ['required', 'array', 'max:'.\App\Support\BundleRecipe::MAX_COMPONENTS],
            'expected_allocations.*' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        if (!$product->is_bundle || $product->bundle_stock_mode !== 'automatic' || $product->variants()->count() !== 1) {
            throw ValidationException::withMessages(['bundle_quantity_mode' => 'این گزینه فقط برای بسته خودکار است.']);
        }
        $bundleVariantId = (int) $product->variants()->value('id');
        $shared = $data['bundle_quantity_mode'] === 'shared';
        if ($shared && !isset($data['bundle_shared_quantity'])) {
            throw ValidationException::withMessages(['bundle_shared_quantity' => 'یک مقدار برای سهم هر قطعه وارد کنید.']);
        }
        DB::transaction(function () use ($product, $bundleVariantId, $shared, $data, $request) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->bundle_stock_mode !== 'automatic') {
                throw ValidationException::withMessages(['bundle_quantity_mode' => 'روش موجودی تغییر کرده است؛ صفحه را بازخوانی کنید.']);
            }
            $switching = ($locked->bundle_quantity_mode ?? 'custom') !== $data['bundle_quantity_mode'];
            if ($switching && (DB::table('invoice_items')->where('variant_id', $bundleVariantId)->exists()
                || DB::connection('site')->table('order_items')->where('price_id', $bundleVariantId)->exists()
                || DB::connection('site')->table('products')->where('id', $product->id)->where('published', 1)->exists())) {
                throw ValidationException::withMessages(['bundle_quantity_mode' => 'برای تغییر روش اختصاص قطعه ابتدا بسته را از انتشار خارج کنید؛ سابقه فروش نیز باید بررسی شود.']);
            }
            $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $bundleVariantId)
                ->pluck('quantity', 'component_variant_id');
            if ($recipe->isEmpty()) {
                throw ValidationException::withMessages(['allocations' => 'ابتدا قطعات بسته را تعیین کنید.']);
            }
            $current = DB::table('bundle_component_allocations')
                ->where('bundle_variant_id', $bundleVariantId)->pluck('remaining', 'component_variant_id');
            foreach ($recipe as $variantId => $perBundle) {
                $expected = $data['expected_allocations'][$variantId] ?? null;
                if ($expected === null || (int) $expected !== (int) ($current[$variantId] ?? 0)) {
                    throw ValidationException::withMessages(['allocations' => 'موجودی این بسته تغییر کرده است. صفحه را تازه کنید و دوباره ثبت کنید.']);
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('site_stock_holds') && DB::table('site_stock_holds')
                ->whereIn('status', ['held','payment_confirmed'])
                ->whereRaw('JSON_CONTAINS_PATH(bundle_allocated_lines, \'one\', ?) = 1', ['$.' . '"' . $bundleVariantId . '"'])
                ->exists()) {
                throw ValidationException::withMessages(['allocations' => 'این بسته سفارش رزروشده در جریان دارد؛ پس از تکمیل/آزادشدن سفارش، سهمیه را تغییر دهید.']);
            }
            $desired = [];
            foreach ($recipe as $variantId => $perBundle) {
                if ($shared) {
                    $desired[(int) $variantId] = (int) $data['bundle_shared_quantity'] * (int) $perBundle;
                    if ($desired[(int) $variantId] > 1000000) {
                        throw ValidationException::withMessages(['bundle_shared_quantity' => 'سهم این تنوع بیشتر از حد مجاز است؛ تعداد بسته یا تعداد قطعه را کاهش دهید.']);
                    }
                } else {
                    if (!array_key_exists((string) $variantId, $data['allocations'] ?? [])
                        && !array_key_exists((int) $variantId, $data['allocations'] ?? [])) {
                        throw ValidationException::withMessages(['allocations' => 'برای تمام قطعات، موجودی اختصاصی تعیین کنید.']);
                    }
                    $desired[(int) $variantId] = (int) ($data['allocations'][$variantId]);
                }
            }
            if (collect($desired)->contains(fn ($qty, $id) => (int) $qty !== (int) ($current[$id] ?? 0))
                && !\App\Support\PermissionCatalog::userHasPermission($request->user(), 'inventory.adjust')) {
                throw ValidationException::withMessages(['allocations' => 'برای تغییر سهم موجودی انبار، دسترسی اصلاح موجودی لازم است.']);
            }
            app(\App\Services\BundleComponentAllocationService::class)
                ->setAllocations($bundleVariantId, $desired);
            $locked->update(['bundle_quantity_mode' => $data['bundle_quantity_mode'], 'bundle_shared_remaining' => null]);
        }, 3);
        $this->syncBundleToSite($product->id);
        return back()->with('success', 'موجودی قطعات به بسته اختصاص یافت و از فروش آزاد جدا شد. موجودی سایت با همگام‌سازی بعدی به‌روز می‌شود.');
    }

    private function validateMovement(Request $request): array
    {
        return $request->validate([
            'bundle_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    private function ensureVariantBelongsToProduct(Product $product, int $variantId): void
    {
        if (!$product->variants()->whereKey($variantId)->exists()) {
            throw ValidationException::withMessages(['bundle_variant_id' => 'تنوع بسته متعلق به این کالا نیست.']);
        }
    }
}
