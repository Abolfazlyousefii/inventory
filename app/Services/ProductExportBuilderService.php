<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductExportBuilderService
{
    public function __construct(
        private readonly ProductCatalogGroupingService $grouping,
        private readonly ProductExportService $exports,
    ) {}

    private function products(bool $inStock = false): Builder
    {
        return Product::query()
            ->select(['id', 'name', 'code', 'sku', 'image_path', 'price', 'stock', 'category_id'])
            ->with('category:id,name')
            ->withSum(['warehouseStocks as central_available_stock' => fn ($query) => $query
                ->whereNull('product_variant_id')->whereHas('warehouse', fn ($warehouse) => $warehouse->where('type', 'central'))], 'quantity')
            ->with(['catalogVariants' => function ($query) use ($inStock) {
                $query->where('is_active', true)
                    ->withSum(['warehouseStocks as central_available_stock' => fn ($stock) => $stock
                        ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('type', 'central'))], 'quantity')
                    ->with(['modelList:id,brand,model_name,code', 'color:id,name,code,hex_code']);
                if ($inStock) {
                    $this->onlyAvailableVariants($query);
                }
            }])
            ->where(fn (Builder $query) => $query->whereDoesntHave('variants')
                ->orWhereHas('catalogVariants', fn (Builder $variants) => $variants->where('is_active', true)));
    }

    public function page(array $filters): array
    {
        $inStock = (bool) ($filters['in_stock'] ?? true);
        $query = $this->products($inStock);
        if (! empty($filters['category_id'])) {
            $ids = Category::selfAndDescendantIds((int) $filters['category_id']);
            $query->whereIn('category_id', $ids);
        }
        if (! empty($filters['brand'])) {
            $query->whereHas('catalogVariants', fn (Builder $variants) => $variants
                ->where('is_active', true)
                ->whereHas('modelList', fn (Builder $models) => $models->where('brand', $filters['brand'])));
        }
        if ($inStock) {
            $query->where(fn (Builder $products) => $products
                ->where(fn (Builder $simple) => $simple->whereDoesntHave('variants')
                    ->whereHas('warehouseStocks', fn (Builder $stock) => $stock->whereNull('product_variant_id')
                        ->where('quantity', '>', 0)->whereHas('warehouse', fn ($warehouse) => $warehouse->where('type', 'central'))))
                ->orWhereHas('catalogVariants', fn (Builder $variants) => $this->onlyAvailableVariants($variants->where('is_active', true))));
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $tokens = array_slice(preg_split('/\s+/u', str_replace(['ي', 'ك'], ['ی', 'ک'], $term), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
            foreach ($tokens as $token) {
                $query->where(function (Builder $products) use ($token) {
                    foreach (array_unique([$token, strtr($token, ['ی' => 'ي', 'ک' => 'ك'])]) as $variant) {
                        $like = '%'.addcslashes($variant, '%_\\').'%';
                        $products->orWhere('name', 'like', $like)
                            ->orWhere('code', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhereHas('catalogVariants', fn (Builder $variants) => $variants
                                ->where('is_active', true)
                                ->where(fn (Builder $match) => $match->where('variant_name', 'like', $like)
                                    ->orWhereHas('modelList', fn (Builder $models) => $models->where('model_name', 'like', $like))));
                    }
                });
            }
        }
        match ($filters['sort'] ?? 'relevant') {
            'name' => $query->orderBy('name'),
            'low' => $query->orderBy('price')->orderBy('name'),
            'high' => $query->orderByDesc('price')->orderBy('name'),
            default => $query->orderByDesc('updated_at'),
        };
        $page = $query->orderBy('id')->paginate(18);

        return [
            'items' => $page->getCollection()->map(fn (Product $product) => $this->card($product))->values(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    private function card(Product $product): array
    {
        $this->useCentralAvailability($product);
        $models = $product->catalogVariants->isEmpty()
            ? collect([[
                'name' => 'مدل عمومی', 'id' => 0, 'stock' => max(0, (int) $product->stock),
                'price' => (int) $product->price,
                'price_max' => (int) $product->price,
            ]])
            : $product->catalogVariants->groupBy(fn ($variant) => $this->grouping->modelName($variant))
                ->map(fn (Collection $variants, $name) => [
                    'name' => $name,
                    'id' => (int) $variants->first()->id,
                    'stock' => (int) $variants->sum('stock'),
                    'price' => $variants->map(fn ($variant) => $this->grouping->effectivePrice($variant, $product))->filter()->min(),
                    'price_max' => $variants->map(fn ($variant) => $this->grouping->effectivePrice($variant, $product))->filter()->max(),
                ])->values();
        $prices = $models->pluck('price')->filter(fn ($price) => $price > 0);

        return [
            'id' => (int) $product->id,
            'name' => trim(strip_tags((string) $product->name)),
            'code' => (string) ($product->code ?: $product->sku ?: ''),
            'category' => (string) ($product->category?->name ?: 'بدون دسته‌بندی'),
            'image' => $this->exports->imagePath($product),
            'price' => $prices->min(),
            'stock' => (int) $models->sum('stock'),
            'models' => $models->values()->all(),
        ];
    }

    private function onlyAvailableVariants($query): void
    {
        $query->whereHas('warehouseStocks', fn (Builder $stock) => $stock->where('quantity', '>', 0)
            ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('type', 'central')));
    }

    private function useCentralAvailability(Product $product): void
    {
        // These are display attributes only; export never writes inventory.
        $product->stock = max(0, (int) $product->central_available_stock);
        foreach ($product->catalogVariants as $variant) {
            $variant->stock = max(0, (int) $variant->central_available_stock);
        }
    }

    public function selected(array $selection, bool $inStock = true): Collection
    {
        $ids = array_map('intval', array_keys($selection));
        $products = $this->products()->whereIn('id', $ids)->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['selection' => 'یکی از کالاها دیگر برای خروجی در دسترس نیست.']);
        }

        return collect($selection)->map(function ($chosen, $id) use ($products, $inStock) {
            $product = $products->get((int) $id);
            $this->useCentralAvailability($product);
            $chosen = array_map('intval', $chosen);
            $all = $product->catalogVariants;
            if ($all->isEmpty()) {
                if ($chosen !== [0] || $product->variants()->exists()) {
                    throw ValidationException::withMessages(['selection' => 'مدل انتخاب‌شده برای کالا معتبر نیست.']);
                }
            } else {
                $byId = $all->keyBy('id');
                if (array_diff($chosen, $byId->keys()->map(fn ($id) => (int) $id)->all()) !== [] || count($chosen) !== count(array_unique($chosen))) {
                    throw ValidationException::withMessages(['selection' => 'یکی از مدل‌های انتخاب‌شده دیگر فعال نیست.']);
                }
                $modelNames = collect($chosen)->map(fn (int $variantId) => $this->grouping->modelName($byId->get($variantId)))->unique()->all();
                $product->setRelation('catalogVariants', $all->filter(fn ($variant) => in_array($this->grouping->modelName($variant), $modelNames, true))->values());
            }
            if ($inStock) {
                $product->setRelation('catalogVariants', $product->catalogVariants->filter(fn ($variant) => (int) $variant->stock > 0)->values());
                if (($all->isNotEmpty() && $product->catalogVariants->isEmpty()) || ($all->isEmpty() && (int) $product->stock <= 0)) {
                    throw ValidationException::withMessages(['selection' => "مدل‌های انتخاب‌شدهٔ «{$product->name}» دیگر موجود نیستند. انتخاب‌ها را به‌روز کنید یا گزینهٔ فقط موجود را بردارید."]);
                }
            }
            $mapped = $this->exports->mapProduct($product, []);
            $mapped['code'] = (string) ($product->code ?: $product->sku ?: '');
            $mapped['total_stock'] = $all->isEmpty() ? max(0, (int) $product->stock) : (int) $product->catalogVariants->sum('stock');
            $selectedVariants = $product->catalogVariants;
            $prices = $selectedVariants->isEmpty()
                ? collect([(int) $product->price > 0 ? (int) $product->price : null])
                : $selectedVariants->map(fn ($variant) => $this->grouping->effectivePrice($variant, $product));
            $prices = $prices->filter(fn ($price) => $price !== null && $price > 0);
            $mapped['approximate_price'] = $prices->isEmpty() ? null : (int) $prices->max();
            $mapped['approximate_price_label'] = $mapped['approximate_price'] === null
                ? 'قیمت ثبت نشده' : number_format($mapped['approximate_price']).' ریال';
            $mapped['selected_models'] = $selectedVariants->isEmpty()
                ? ['مدل عمومی']
                : $selectedVariants->map(fn ($variant) => $this->grouping->modelName($variant))
                    ->unique()->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values()->all();
            $mapped['selected_colors'] = $selectedVariants
                ->map(fn ($variant) => $this->grouping->colorData($variant))
                ->filter()->unique(fn (array $color) => mb_strtolower($color['name']))
                ->sort(fn ($a, $b) => strnatcasecmp($a['name'], $b['name']))->values()->all();

            return $mapped;
        })->values();
    }
}
