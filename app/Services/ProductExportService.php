<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

class ProductExportService
{
    public const LOW_STOCK_THRESHOLD = 5;

    /**
     * کوئری محصولات مطابق فیلترها
     */
    public function query(array $filters): Builder
    {
        $warehouseId = ! empty($filters['warehouse_id'])
            ? (int) $filters['warehouse_id']
            : null;
        $modelListIds = $this->modelListIds($filters);

        return Product::query()
            ->with('category')
            ->with([
                'variants' => function ($query) use ($warehouseId) {
                    $query
                        ->with(['modelList', 'color'])
                        ->with([
                            'warehouseStocks' => function ($stockQuery) use ($warehouseId) {
                                $stockQuery->with('warehouse');

                                if ($warehouseId !== null) {
                                    $stockQuery->where('warehouse_id', $warehouseId);
                                }
                            },
                        ])
                        ->orderBy('variant_name')
                        ->orderBy('variety_name')
                        ->orderBy('id');
                },
            ])
            ->withSum([
                'warehouseStocks as export_stock' => function ($query) use ($warehouseId) {
                    if ($warehouseId !== null) {
                        $query->where('warehouse_id', $warehouseId);
                    }
                },
            ], 'quantity')
            ->when(
                ! empty($filters['category_id']),
                fn (Builder $query) => $query->where(
                    'category_id',
                    (int) $filters['category_id']
                )
            )
            ->when(
                ! empty($modelListIds),
                fn (Builder $query) => $query->whereHas(
                    'variants',
                    fn (Builder $variantQuery) => $variantQuery->whereIn(
                        'model_list_id',
                        $modelListIds
                    )
                )
            )
            ->when(
                filled($filters['search'] ?? null),
                fn (Builder $query) => $this->applySearch(
                    $query,
                    trim((string) $filters['search'])
                )
            )
            ->orderBy('name');
    }

    /**
     * اعمال جستجو روی محصول و تنوع‌ها
     */
    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $productQuery) use ($search) {
            $productQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('barcode', 'like', "%{$search}%")
                ->orWhere('short_barcode', 'like', "%{$search}%")
                ->orWhereHas('variants', function (Builder $variantQuery) use ($search) {
                    $variantQuery
                        ->where('variant_name', 'like', "%{$search}%")
                        ->orWhere('variety_name', 'like', "%{$search}%")
                        ->orWhere('variant_code', 'like', "%{$search}%")
                        ->orWhere('variety_code', 'like', "%{$search}%");
                });
        });
    }

    /**
     * دریافت محصولات و اعمال فیلتر وضعیت موجودی
     */
    public function filteredProducts(array $filters): Collection
    {
        $warehouseFilterActive = ! empty($filters['warehouse_id']);
        $stockStatus = $filters['stock_status'] ?? 'all';

        return $this->query($filters)
            ->get()
            ->filter(function (Product $product) use (
                $stockStatus,
                $warehouseFilterActive
            ) {
                $stock = $this->stock(
                    $product,
                    $warehouseFilterActive
                );

                return $this->matchesStockStatus($stock, $stockStatus);
            })
            ->values();
    }

    public function exportStats(array $filters, ?int $stopAfterProducts = null, ?int $stopAfterVariants = null): array
    {
        $warehouseFilterActive = ! empty($filters['warehouse_id']);
        $stockStatus = $filters['stock_status'] ?? 'all';
        $productsCount = 0;
        $variantsCount = 0;

        $this->query($filters)
            ->reorder('products.id')
            ->chunkById(100, function (Collection $products) use (
                $filters,
                $stockStatus,
                $warehouseFilterActive,
                $stopAfterProducts,
                $stopAfterVariants,
                &$productsCount,
                &$variantsCount
            ) {
                foreach ($products as $product) {
                    $stock = $this->stock($product, $warehouseFilterActive);

                    if (! $this->matchesStockStatus($stock, $stockStatus)) {
                        continue;
                    }

                    $productsCount++;
                    $variantsCount += $this->displayVariants($product, $filters)->count();

                    if (
                        ($stopAfterProducts !== null && $productsCount > $stopAfterProducts)
                        || ($stopAfterVariants !== null && $variantsCount > $stopAfterVariants)
                    ) {
                        return false;
                    }
                }

                return true;
            }, 'products.id', 'id');

        return [
            'products_count' => $productsCount,
            'variants_count' => $variantsCount,
        ];
    }

    /**
     * پیمایش ردیف‌های خروجی به‌صورت تکه‌ای برای جلوگیری از پر شدن حافظه در PDFهای بزرگ.
     *
     * @param callable(array, int): void $callback
     */
    public function eachRow(array $filters, callable $callback, int $chunkSize = 100): int
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $warehouseFilterActive = ! empty($filters['warehouse_id']);
        $stockStatus = $filters['stock_status'] ?? 'all';
        $index = 0;

        $this->query($filters)
            ->reorder('products.id')
            ->chunkById($chunkSize, function (Collection $products) use (
                $callback,
                $filters,
                $stockStatus,
                $warehouseFilterActive,
                &$index
            ) {
                foreach ($products as $product) {
                    $stock = $this->stock($product, $warehouseFilterActive);

                    $matchesStockStatus = $this->matchesStockStatus($stock, $stockStatus);

                    if (! $matchesStockStatus) {
                        continue;
                    }

                    $index++;

                    $callback($this->row($product, $filters), $index);
                }
            }, 'products.id', 'id');

        return $index;
    }

    private function matchesStockStatus(int $stock, string $stockStatus): bool
    {
        return match ($stockStatus) {
            'in_stock' => $stock > 0,
            'out_of_stock' => $stock <= 0,
            'low_stock' => $stock > 0 && $stock <= self::LOW_STOCK_THRESHOLD,
            default => true,
        };
    }

    /**
     * ساخت ردیف‌های خروجی
     */
    public function rows(array $filters): array
    {
        return $this->filteredProducts($filters)
            ->map(
                fn (Product $product) => $this->row(
                    $product,
                    $filters
                )
            )
            ->all();
    }

    /**
     * ساخت اطلاعات خروجی هر محصول
     */
    public function row(Product $product, array $filters = []): array
    {
        $warehouseFilterActive = ! empty($filters['warehouse_id']);

        $stock = $this->stock(
            $product,
            $warehouseFilterActive
        );

        return [
            'id' => $product->id,

            'image_url' => $this->imageUrl($product),

            'pdf_image_src' => $this->pdfImageSrc($product),

            'has_image' => $this->hasPdfImage($product),

            'name' => $this->cleanText(
                $product->name,
                'محصول بدون نام'
            ),

            'display_code' => $this->productCode($product),

            'sku' => $this->productCode($product),

            'category' => $this->cleanText(
                $product->category?->name,
                'بدون دسته‌بندی'
            ),

            'stock' => $stock,

            'price' => $this->productSalePrice($product),

            'price_label' => $this->productPriceLabel($product),

            'stock_status' => $this->stockStatus($stock),

            'stock_status_class' => $this->stockStatusClass($stock),

            'updated_at' => optional($product->updated_at)
                ->format('Y/m/d H:i'),

            'unit' => $this->cleanText(
                $product->unit,
                'عدد'
            ),

            'barcode' => trim(
                (string) (
                    $product->barcode
                    ?: $product->short_barcode
                    ?: ''
                )
            ),

            'is_sellable' => (bool) ($product->is_sellable ?? true),

            'variants' => $this->displayVariants($product, $filters)
                ->map(
                    fn (ProductVariant $variant) => $this->variantRow(
                        $variant,
                        $product,
                        $warehouseFilterActive
                    )
                )
                ->values()
                ->all(),
        ];
    }

    private function displayVariants(Product $product, array $filters): Collection
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : collect();

        $modelListIds = $this->modelListIds($filters);

        if (empty($modelListIds)) {
            return $variants->values();
        }

        return $variants
            ->filter(fn (ProductVariant $variant) => in_array(
                (int) ($variant->model_list_id ?? 0),
                $modelListIds,
                true
            ))
            ->values();
    }

    private function modelListIds(array $filters): array
    {
        return collect($filters['model_list_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function modelListLabels(array $filters): array
    {
        $modelListIds = $this->modelListIds($filters);

        if (empty($modelListIds)) {
            return [];
        }

        return ModelList::query()
            ->whereIn('id', $modelListIds)
            ->orderBy('brand')
            ->orderBy('model_name')
            ->get(['brand', 'model_name'])
            ->map(function (ModelList $modelList) {
                return $this->cleanText(
                    trim(implode(' ', array_filter([
                        $modelList->brand,
                        $modelList->model_name,
                    ]))),
                    'مدل بدون نام'
                );
            })
            ->values()
            ->all();
    }

    /**
     * کد اصلی محصول
     */
    private function productCode(Product $product): string
    {
        return trim(
            (string) (
                $product->sku
                ?: $product->code
                ?: $product->barcode
                ?: $product->short_barcode
                ?: '—'
            )
        );
    }

    /**
     * اطلاعات بالای گزارش
     */
    public function meta(array $filters): array
    {
        $category = ! empty($filters['category_id'])
            ? Category::query()->find((int) $filters['category_id'])
            : null;

        $warehouse = ! empty($filters['warehouse_id'])
            ? Warehouse::query()->find((int) $filters['warehouse_id'])
            : null;

        return [
            'category' => $this->cleanText(
                $category?->name,
                'همه دسته‌بندی‌ها'
            ),

            'warehouse' => $this->cleanText(
                $warehouse?->name,
                'همه انبارها'
            ),

            'stock_status' => $this->stockStatusFilterLabel(
                $filters['stock_status'] ?? 'all'
            ),

            'search' => trim(
                (string) ($filters['search'] ?? '')
            ),

            'model_lists' => $this->modelListLabels($filters),

            'exported_at' => now()->format('Y/m/d H:i'),

            'store_name' => $this->cleanText(
                config('app.name'),
                'سامانه انبارداری'
            ),

            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
        ];
    }

    /**
     * آدرس تصویر برای نمایش داخل سایت
     */
    public function imageUrl(Product $product): string
    {
        $localPath = $this->localImagePath($product);

        if ($localPath !== null) {
            return route('products.image', $product);
        }

        if ($this->isRemoteImage($product->image_path)) {
            return trim((string) $product->image_path);
        }

        return $this->placeholderImage();
    }

    /**
     * بررسی وجود تصویر واقعی برای PDF
     */
public function hasPdfImage(Product $product): bool
{
    return $this->pdfImageSrc($product) !== '';
}

    /**
     * آماده‌سازی تصویر برای PDF
     *
     * تصاویر محلی به Base64 تبدیل می‌شوند تا mPDF
     * بدون مشکل دسترسی، آن‌ها را نمایش دهد.
     */
   public function pdfImageSrc(Product $product): string
{
    $path = $this->localImagePath($product);

    if ($path) {
        $absolutePath = Storage::disk('public')->path($path);

        if (is_file($absolutePath) && is_readable($absolutePath)) {
            /*
             * تبدیل بک‌اسلش ویندوز برای سازگاری با mPDF
             */
            return str_replace('\\', '/', $absolutePath);
        }
    }

    if ($this->isRemoteImage($product->image_path)) {
        return trim((string) $product->image_path);
    }

    return '';
}

    /**
     * تبدیل تصویر محلی به Base64
     */
    private function localImageAsBase64(string $path): ?string
    {
        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                return null;
            }

            $absolutePath = $disk->path($path);

            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                return null;
            }

            $content = file_get_contents($absolutePath);

            if ($content === false || $content === '') {
                return null;
            }

            $mimeType = mime_content_type($absolutePath);

            if (! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
                $mimeType = 'image/jpeg';
            }

            return sprintf(
                'data:%s;base64,%s',
                $mimeType,
                base64_encode($content)
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * پیدا کردن مسیر واقعی تصویر در public disk
     */
    private function localImagePath(Product $product): ?string
    {
        $imagePath = trim(
            (string) ($product->image_path ?? '')
        );

        if (
            $imagePath === ''
            || $this->isRemoteImage($imagePath)
        ) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $imagePath);

        $candidates = [
            ltrim($normalizedPath, '/'),

            preg_replace(
                '#^/?storage/#i',
                '',
                $normalizedPath
            ),

            preg_replace(
                '#^/?public/#i',
                '',
                $normalizedPath
            ),
        ];

        $fileName = basename($normalizedPath);

        if ($fileName !== '') {
            $candidates[] = 'products/' . $fileName;
        }

        $candidates = array_values(
            array_unique(
                array_filter(
                    $candidates,
                    fn ($path) => is_string($path) && $path !== ''
                )
            )
        );

        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * تشخیص تصویر اینترنتی
     */
    private function isRemoteImage(?string $path): bool
    {
        if (! is_string($path)) {
            return false;
        }

        $path = trim($path);

        return filter_var($path, FILTER_VALIDATE_URL) !== false
            && in_array(
                strtolower((string) parse_url($path, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            );
    }

    /**
     * تصویر جایگزین
     */
    private function placeholderImage(): string
    {
        return 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAFQAAABUCAYAAAAcaxDBAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAxUlEQVR4nO3QQQ3AIADAQMAJ/5yuwgOi8wKSpd1Z7z0AAAAAAAAAAAAAAAAAAAAAAAB8Z54H4GbEUYijEEchjkIchTgKcRTiKMTRiKMQRyGOQhyFOApxFOIoxNGIoxBHIY5CHIWE8c5zAF8rjiKMQhyFOApxFOIoxFGIoxBHIY5CHIWE8c5zAK8VjiKMQhyFOApxFOIoxFGIoxBHIY5CHIWE8c5zAG8VjiKMQhyFOApxFOIoxFGIoxBHIY5CHIWE8c5zAP8WAAAAAAAAAAAAAAAAAAAAAAAArB2lJwKTe+Ve3wAAAABJRU5ErkJggg==';
    }

    /**
     * ساخت اطلاعات هر تنوع
     */
    public function variantRow(
        ProductVariant $variant,
        Product $product,
        bool $warehouseFilterActive = false
    ): array {
        $stock = $this->variantStock(
            $variant,
            $warehouseFilterActive
        );

        $warehouseStocks = $variant->relationLoaded('warehouseStocks')
            ? $variant->warehouseStocks
                ->filter(
                    fn ($stockRow) => (int) ($stockRow->quantity ?? 0) !== 0
                )
            : collect();

        return [
            'id' => $variant->id,

            'name' => $this->variantName($variant),

            'code' => $this->variantCode($variant),

            'barcode' => trim(
                (string) ($variant->barcode ?? '')
            ),

            'stock' => $stock,

            'unit' => $this->cleanText(
                $product->unit,
                'عدد'
            ),

            'price' => $this->variantSalePrice(
                $variant,
                $product
            ),

            'status' => (bool) $variant->is_active
                ? 'فعال'
                : 'غیرفعال',

            'stock_status' => $this->stockStatus($stock),

            'stock_status_class' => $this->stockStatusClass($stock),

            'warehouse_stocks' => $warehouseStocks
                ->map(fn ($stockRow) => [
                    'warehouse' => $this->cleanText(
                        $stockRow->warehouse?->name,
                        'بدون انبار'
                    ),

                    'quantity' => max(
                        0,
                        (int) ($stockRow->quantity ?? 0)
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * نام تنوع
     */
    private function variantName(ProductVariant $variant): string
    {
        return $this->cleanText(
            $variant->variant_name
                ?: $variant->variety_name
                ?: $variant->color?->name
                ?: $variant->modelList?->model_name,
            'تنوع بدون نام'
        );
    }

    /**
     * کد تنوع
     */
    private function variantCode(ProductVariant $variant): string
    {
        return trim(
            (string) (
                $variant->variant_code
                ?: $variant->barcode
                ?: $variant->variety_code
                ?: $variant->sku
                ?: '—'
            )
        );
    }

    /**
     * موجودی تنوع
     */
    private function variantStock(
        ProductVariant $variant,
        bool $warehouseFilterActive = false
    ): int {
        if ($variant->relationLoaded('warehouseStocks')) {
            /*
             * وقتی فیلتر انبار فعال است، رابطه فقط موجودی همان
             * انبار را دریافت کرده است؛ پس جمع خالی برابر صفر است.
             */
            if ($warehouseFilterActive) {
                return max(
                    0,
                    (int) $variant->warehouseStocks->sum('quantity')
                );
            }

            if ($variant->warehouseStocks->isNotEmpty()) {
                return max(
                    0,
                    (int) $variant->warehouseStocks->sum('quantity')
                );
            }
        }

        return max(
            0,
            (int) ($variant->stock ?? 0)
        );
    }

    /**
     * قیمت فروش تنوع
     */
    private function variantSalePrice(
        ProductVariant $variant,
        Product $product
    ): int {
        return max(
            0,
            (int) (
                $variant->sell_price
                ?? $product->price
                ?? $product->sale_retail
                ?? 0
            )
        );
    }

    /**
     * قیمت فروش محصول
     */
    private function productSalePrice(Product $product): int
    {
        if ($product->relationLoaded('variants')) {
            $variantPrices = $product->variants
                ->pluck('sell_price')
                ->filter(
                    fn ($price) => is_numeric($price)
                        && (int) $price > 0
                );

            if ($variantPrices->isNotEmpty()) {
                return (int) $variantPrices->min();
            }
        }

        return max(
            0,
            (int) (
                $product->price
                ?? $product->sale_retail
                ?? 0
            )
        );
    }

    /**
     * عنوان قیمت محصول
     */
    private function productPriceLabel(Product $product): string
    {
        if (! $product->relationLoaded('variants')) {
            return 'قیمت فروش';
        }

        $variantPrices = $product->variants
            ->pluck('sell_price')
            ->filter(
                fn ($price) => is_numeric($price)
                    && (int) $price > 0
            )
            ->map(fn ($price) => (int) $price)
            ->unique()
            ->values();

        return $variantPrices->count() > 1
            ? 'قیمت فروش از'
            : 'قیمت فروش';
    }

    /**
     * موجودی کل محصول
     */
    public function stock(
        Product $product,
        bool $warehouseFilterActive = false
    ): int {
        if (
            $product->relationLoaded('variants')
            && $product->variants->isNotEmpty()
        ) {
            return max(
                0,
                (int) $product->variants->sum(
                    fn (ProductVariant $variant) => $this->variantStock(
                        $variant,
                        $warehouseFilterActive
                    )
                )
            );
        }

        /*
         * وقتی انبار انتخاب شده، export_stock فقط موجودی همان
         * انبار است و حتی اگر null باشد باید صفر در نظر گرفته شود.
         */
        if ($warehouseFilterActive) {
            return max(
                0,
                (int) ($product->export_stock ?? 0)
            );
        }

        return max(
            0,
            (int) (
                $product->export_stock
                ?? $product->stock
                ?? 0
            )
        );
    }

    /**
     * عنوان وضعیت موجودی
     */
    public function stockStatus(int $stock): string
    {
        return match (true) {
            $stock <= 0 => 'ناموجود',

            $stock <= self::LOW_STOCK_THRESHOLD => 'کم‌موجودی',

            default => 'موجود',
        };
    }

    /**
     * کلاس نمایشی وضعیت موجودی
     */
    public function stockStatusClass(int $stock): string
    {
        return match (true) {
            $stock <= 0 => 'danger',

            $stock <= self::LOW_STOCK_THRESHOLD => 'warning',

            default => 'success',
        };
    }

    /**
     * عنوان فیلتر وضعیت موجودی
     */
    private function stockStatusFilterLabel(string $status): string
    {
        return match ($status) {
            'in_stock' => 'فقط کالاهای موجود',

            'out_of_stock' => 'فقط کالاهای ناموجود',

            'low_stock' => 'کالاهای کم‌موجودی',

            default => 'همه محصولات',
        };
    }

    /**
     * پاک‌سازی متن بدون دست‌کاری شکل حروف فارسی
     */
    private function cleanText(
        mixed $value,
        string $fallback = ''
    ): string {
        $text = trim(
            strip_tags((string) $value)
        );

        if ($text === '') {
            return $fallback;
        }

        /*
         * یکسان‌سازی حروف عربی با حروف فارسی
         */
        return str_replace(
            ['ي', 'ك', "\u{200F}", "\u{200E}"],
            ['ی', 'ک', '', ''],
            $text
        );
    }
}