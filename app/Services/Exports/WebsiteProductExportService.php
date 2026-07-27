<?php

namespace App\Services\Exports;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class WebsiteProductExportService
{
    public const STOCK_SOURCE = 'warehouse_stocks.quantity for the single warehouses.type=central warehouse';

    public const PRICE_SOURCE_RULE = 'product_variants.sell_price when positive; products.price is diagnostic only and is never a sales fallback';

    public const HEADERS = [
        'row_number',
        'product_sync_key',
        'variant_sync_key',
        'product_id',
        'variant_id',
        'product_name',
        'product_slug',
        'product_code',
        'product_sku',
        'product_barcode',
        'product_short_barcode',
        'product_status',
        'product_is_sellable',
        'category_id',
        'category_name',
        'parent_category_id',
        'parent_category_name',
        'brand_id',
        'brand_name',
        'unit_id',
        'unit_name',
        'short_description',
        'description',
        'variant_name',
        'variant_code',
        'variant_sku',
        'variant_barcode',
        'model_list_id',
        'model_name',
        'color_id',
        'color_name',
        'variant_is_active',
        'sales_enabled',
        'effective_sell_price_rial',
        'variant_sell_price_rial',
        'product_sell_price_rial',
        'price_source',
        'has_valid_price',
        'free_stock',
        'reserved_stock',
        'physical_stock',
        'is_in_stock',
        'image_path',
        'image_filename',
        'image_public_url',
        'has_image',
        'product_created_at',
        'product_updated_at',
        'variant_created_at',
        'variant_updated_at',
        'exported_at',
    ];

    private array $imageMetadataCache = [];

    /**
     * @param  array{
     *     include_zero_stock?: bool,
     *     exclude_zero_price?: bool,
     *     output?: string|null,
     *     chunk?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function export(array $options = [], ?callable $progress = null): array
    {
        $startedAt = microtime(true);
        $exportedAt = now();
        $chunkSize = min(5000, max(1, (int) ($options['chunk'] ?? 500)));
        $includeZeroStock = (bool) ($options['include_zero_stock'] ?? false);
        $excludeZeroPrice = (bool) ($options['exclude_zero_price'] ?? false);
        $centralWarehouse = $this->centralWarehouse();
        $outputPath = $this->uniqueOutputPath($options['output'] ?? null, $exportedAt);
        $summaryPath = $this->summaryPath($outputPath);
        $temporaryCsvPath = $this->temporaryPath($outputPath);
        $temporarySummaryPath = $this->temporaryPath($summaryPath);
        $handle = null;
        $csvPublished = false;
        $summaryPublished = false;
        $this->imageMetadataCache = [];

        $statistics = [
            'products' => [],
            'products_without_category' => [],
            'variants_count' => 0,
            'in_stock_count' => 0,
            'zero_stock_count' => 0,
            'zero_price_count' => 0,
            'missing_image_count' => 0,
            'excluded_zero_price_count' => 0,
            'processed_candidates_count' => 0,
            'errors_count' => 0,
            'errors' => [],
            'stock_cache_mismatch_count' => 0,
        ];

        try {
            $this->ensureOutputDirectory($outputPath);

            $handle = @fopen($temporaryCsvPath, 'xb');
            if ($handle === false) {
                throw new RuntimeException("Unable to create temporary CSV file: {$temporaryCsvPath}");
            }

            if (fwrite($handle, "\xEF\xBB\xBF") !== 3) {
                throw new RuntimeException('Unable to write the UTF-8 BOM to the CSV file.');
            }

            $this->writeCsvRow($handle, self::HEADERS);

            $query = $this->variantQuery(
                centralWarehouseId: (int) $centralWarehouse->id,
                includeZeroStock: $includeZeroStock,
            );
            $totalCandidates = (clone $query)->count();
            if ($progress !== null) {
                $progress(0, $totalCandidates);
            }

            $query->chunkById($chunkSize, function ($variants) use (
                &$statistics,
                $handle,
                $exportedAt,
                $excludeZeroPrice,
                $progress,
                $totalCandidates,
            ): void {
                foreach ($variants as $variant) {
                    $statistics['processed_candidates_count']++;

                    try {
                        $product = $variant->product;
                        if (! $product instanceof Product) {
                            throw new RuntimeException('The parent product could not be loaded.');
                        }

                        $price = $this->priceData($variant, $product);
                        if ($excludeZeroPrice && ! $price['has_valid_price']) {
                            $statistics['excluded_zero_price_count']++;

                            continue;
                        }

                        $stock = $this->stockData($variant);
                        $image = $this->imageData($product);
                        $rowNumber = $statistics['variants_count'] + 1;
                        $row = $this->row(
                            rowNumber: $rowNumber,
                            variant: $variant,
                            product: $product,
                            price: $price,
                            stock: $stock,
                            image: $image,
                            exportedAt: $exportedAt,
                        );
                    } catch (Throwable $exception) {
                        $this->recordError($statistics, (int) $variant->id, $exception->getMessage());

                        continue;
                    }

                    // I/O failures must abort the whole export so the outer
                    // cleanup removes every partial file.
                    $this->writeCsvRow($handle, $row);
                    $productId = (int) $product->id;
                    $statistics['products'][$productId] = true;
                    if ($product->category_id === null) {
                        $statistics['products_without_category'][$productId] = true;
                    }
                    $statistics['variants_count']++;
                    $statistics[$stock['is_in_stock'] ? 'in_stock_count' : 'zero_stock_count']++;
                    if (! $price['has_valid_price']) {
                        $statistics['zero_price_count']++;
                    }
                    if (! $image['has_image']) {
                        $statistics['missing_image_count']++;
                    }
                    if ($stock['cache_mismatch']) {
                        $statistics['stock_cache_mismatch_count']++;
                        $this->recordError(
                            $statistics,
                            (int) $variant->id,
                            sprintf(
                                'Stock cache mismatch: product_variants.stock=%d, central warehouse_stocks.quantity=%d.',
                                $stock['cached_free_stock'],
                                $stock['central_warehouse_stock'],
                            ),
                        );
                    }
                }

                if ($progress !== null) {
                    $progress($statistics['processed_candidates_count'], $totalCandidates);
                }
            });

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the CSV file to disk.');
            }
            if (! fclose($handle)) {
                throw new RuntimeException('Unable to close the CSV file.');
            }
            $handle = null;

            $summary = $this->summary(
                outputPath: $outputPath,
                exportedAt: $exportedAt,
                centralWarehouse: $centralWarehouse,
                statistics: $statistics,
                includeZeroStock: $includeZeroStock,
                excludeZeroPrice: $excludeZeroPrice,
                startedAt: $startedAt,
            );

            $summaryJson = json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;

            if (@file_put_contents($temporarySummaryPath, $summaryJson, LOCK_EX) === false) {
                throw new RuntimeException("Unable to write temporary summary file: {$temporarySummaryPath}");
            }

            $this->publishFile($temporaryCsvPath, $outputPath);
            $csvPublished = true;
            $this->publishFile($temporarySummaryPath, $summaryPath);
            $summaryPublished = true;

            clearstatcache(true, $outputPath);
            $summary['summary_file'] = $summaryPath;
            $summary['file_size_bytes'] = (int) (@filesize($outputPath) ?: 0);
            $summary['peak_memory_bytes'] = memory_get_peak_usage(true);

            return $summary;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->deleteIfFile($temporaryCsvPath);
            $this->deleteIfFile($temporarySummaryPath);
            if ($csvPublished) {
                $this->deleteIfFile($outputPath);
            }
            if ($summaryPublished) {
                $this->deleteIfFile($summaryPath);
            }

            throw $exception;
        }
    }

    public function headers(): array
    {
        return self::HEADERS;
    }

    /**
     * Central warehouse quantity is already free stock. Reservation workflows
     * decrement it before increasing variant.reserved.
     *
     * @return array{
     *     free_stock: int,
     *     reserved_stock: int,
     *     physical_stock: int,
     *     is_in_stock: bool,
     *     central_warehouse_stock: int,
     *     cached_free_stock: int,
     *     cache_mismatch: bool
     * }
     */
    public function stockData(ProductVariant $variant): array
    {
        $cachedFreeStock = max(0, (int) ($variant->stock ?? 0));
        $reservedStock = max(0, (int) ($variant->reserved ?? 0));
        $centralWarehouseStock = max(0, (int) ($variant->central_warehouse_stock ?? 0));

        return [
            'free_stock' => $centralWarehouseStock,
            'reserved_stock' => $reservedStock,
            'physical_stock' => $centralWarehouseStock + $reservedStock,
            'is_in_stock' => $centralWarehouseStock > 0,
            'central_warehouse_stock' => $centralWarehouseStock,
            'cached_free_stock' => $cachedFreeStock,
            'cache_mismatch' => $cachedFreeStock !== $centralWarehouseStock,
        ];
    }

    /**
     * @return array{
     *     effective_sell_price_rial: int,
     *     variant_sell_price_rial: int,
     *     product_sell_price_rial: int,
     *     price_source: string,
     *     has_valid_price: bool
     * }
     */
    public function priceData(ProductVariant $variant, Product $product): array
    {
        $variantPrice = max(0, (int) ($variant->sell_price ?? 0));
        $productPrice = max(0, (int) ($product->price ?? 0));
        // SalePriceGuard and preinvoice persistence require the variant price;
        // products.price is only a denormalized catalogue summary.
        $effectivePrice = $variantPrice;
        $source = $variantPrice > 0 ? 'variant' : 'none';

        return [
            'effective_sell_price_rial' => $effectivePrice,
            'variant_sell_price_rial' => $variantPrice,
            'product_sell_price_rial' => $productPrice,
            'price_source' => $source,
            'has_valid_price' => $effectivePrice > 0,
        ];
    }

    protected function writeCsvRow($handle, array $row): void
    {
        $safeRow = array_map(fn (mixed $value) => $this->safeCsvValue($value), $row);

        if (fputcsv($handle, $safeRow, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write a row to the CSV file.');
        }
    }

    private function variantQuery(int $centralWarehouseId, bool $includeZeroStock): Builder
    {
        return ProductVariant::query()
            ->select([
                'id',
                'product_id',
                'model_list_id',
                'color_id',
                'is_active',
                'sales_enabled',
                'variant_name',
                'variety_name',
                'variety_code',
                'variant_code',
                'sell_price',
                'stock',
                'reserved',
                'created_at',
                'updated_at',
            ])
            ->withSum([
                'warehouseStocks as central_warehouse_stock' => fn ($query) => $query
                    ->where('warehouse_id', $centralWarehouseId)
                    ->whereColumn('warehouse_stocks.product_id', 'product_variants.product_id'),
            ], 'quantity')
            ->with([
                'product:id,category_id,name,sku,image_path,code,short_barcode,barcode,stock,reserved,unit,price,is_sellable,created_at,updated_at',
                'product.category:id,name,parent_id',
                'product.category.parent:id,name',
                'modelList:id,brand,model_name',
                'color:id,name',
            ])
            ->where('is_active', true)
            ->where('sales_enabled', true)
            ->whereHas('product', fn (Builder $query) => $query->where('is_sellable', true))
            ->when(! $includeZeroStock, fn (Builder $query) => $query->whereHas(
                'warehouseStocks',
                fn (Builder $stockQuery) => $stockQuery
                    ->where('warehouse_id', $centralWarehouseId)
                    ->whereColumn('warehouse_stocks.product_id', 'product_variants.product_id')
                    ->where('quantity', '>', 0),
            ))
            ->orderBy('id');
    }

    private function row(
        int $rowNumber,
        ProductVariant $variant,
        Product $product,
        array $price,
        array $stock,
        array $image,
        DateTimeInterface $exportedAt,
    ): array {
        $category = $product->category;
        $parentCategory = $category?->parent;
        $model = $variant->modelList;
        $color = $variant->color;

        return [
            $rowNumber,
            'product-'.$product->id,
            'variant-'.$variant->id,
            (int) $product->id,
            (int) $variant->id,
            (string) $product->name,
            '',
            (string) ($product->code ?? ''),
            (string) ($product->sku ?? ''),
            (string) ($product->barcode ?? ''),
            (string) ($product->short_barcode ?? ''),
            '',
            (int) ((bool) $product->is_sellable),
            $category?->id !== null ? (int) $category->id : '',
            (string) ($category?->name ?? ''),
            $parentCategory?->id !== null ? (int) $parentCategory->id : '',
            (string) ($parentCategory?->name ?? ''),
            '',
            (string) ($model?->brand ?? ''),
            '',
            (string) ($product->unit ?? ''),
            '',
            '',
            (string) ($variant->variant_name ?? ''),
            (string) ($variant->variant_code ?? ''),
            (string) ($variant->sku ?? ''),
            (string) ($variant->barcode ?? ''),
            $model?->id !== null ? (int) $model->id : '',
            (string) ($model?->model_name ?? ''),
            $color?->id !== null ? (int) $color->id : '',
            (string) ($color?->name ?? ''),
            (int) ((bool) $variant->is_active),
            (int) ((bool) $variant->sales_enabled),
            $price['effective_sell_price_rial'],
            $price['variant_sell_price_rial'],
            $price['product_sell_price_rial'],
            $price['price_source'],
            (int) $price['has_valid_price'],
            $stock['free_stock'],
            $stock['reserved_stock'],
            $stock['physical_stock'],
            (int) $stock['is_in_stock'],
            $image['image_path'],
            $image['image_filename'],
            $image['image_public_url'],
            (int) $image['has_image'],
            $this->formatDate($product->created_at),
            $this->formatDate($product->updated_at),
            $this->formatDate($variant->created_at),
            $this->formatDate($variant->updated_at),
            $exportedAt->format(DateTimeInterface::ATOM),
        ];
    }

    private function imageData(Product $product): array
    {
        $productId = (int) $product->id;
        if (isset($this->imageMetadataCache[$productId])) {
            return $this->imageMetadataCache[$productId];
        }

        $rawPath = trim((string) ($product->image_path ?? ''));
        $filename = $rawPath === '' ? '' : basename((string) parse_url($rawPath, PHP_URL_PATH));
        $publicUrl = '';
        $hasImage = false;

        if ($this->isPublicHttpUrl($rawPath)) {
            $publicUrl = $rawPath;
            $hasImage = true;
        } elseif ($rawPath !== '') {
            $publicDiskPath = $this->publicDiskImagePath($rawPath);
            if ($publicDiskPath !== null) {
                $hasImage = true;
                if ($this->publicStorageIsWebAccessible()) {
                    $publicUrl = Storage::disk('public')->url($publicDiskPath);
                }
            }
        }

        return $this->imageMetadataCache[$productId] = [
            'image_path' => $rawPath,
            'image_filename' => $filename,
            'image_public_url' => $publicUrl,
            'has_image' => $hasImage,
        ];
    }

    private function publicDiskImagePath(string $path): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            ltrim(str_replace('\\', '/', $path), '/'),
            preg_replace('#^/?storage/#', '', str_replace('\\', '/', $path)),
            preg_replace('#^/?public/#', '', str_replace('\\', '/', $path)),
            basename($path) !== '' ? 'products/'.basename($path) : null,
        ])));

        foreach ($candidates as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isPublicHttpUrl(string $value): bool
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function publicStorageIsWebAccessible(): bool
    {
        $publicStorage = public_path('storage');

        return is_link($publicStorage) || is_dir($publicStorage);
    }

    private function summary(
        string $outputPath,
        DateTimeInterface $exportedAt,
        Warehouse $centralWarehouse,
        array $statistics,
        bool $includeZeroStock,
        bool $excludeZeroPrice,
        float $startedAt,
    ): array {
        return [
            'exported_at' => $exportedAt->format(DateTimeInterface::ATOM),
            'products_count' => count($statistics['products']),
            'variants_count' => $statistics['variants_count'],
            'in_stock_count' => $statistics['in_stock_count'],
            'zero_stock_count' => $statistics['zero_stock_count'],
            'zero_price_count' => $statistics['zero_price_count'],
            'missing_image_count' => $statistics['missing_image_count'],
            'products_without_category_count' => count($statistics['products_without_category']),
            'excluded_zero_price_count' => $statistics['excluded_zero_price_count'],
            'processed_candidates_count' => $statistics['processed_candidates_count'],
            'errors_count' => $statistics['errors_count'],
            'errors' => $statistics['errors'],
            'stock_cache_mismatch_count' => $statistics['stock_cache_mismatch_count'],
            'output_file' => $outputPath,
            'stock_source' => self::STOCK_SOURCE,
            'stock_formula' => 'free_stock = SUM(central warehouse_stocks.quantity); reserved_stock = product_variants.reserved; physical_stock = free_stock + reserved_stock',
            'price_source_rule' => self::PRICE_SOURCE_RULE,
            'central_warehouse_id' => (int) $centralWarehouse->id,
            'central_warehouse_name' => (string) $centralWarehouse->name,
            'include_zero_stock' => $includeZeroStock,
            'exclude_zero_price' => $excludeZeroPrice,
            'duration_seconds' => round(microtime(true) - $startedAt, 4),
            'data_changed' => false,
        ];
    }

    private function recordError(array &$statistics, int $variantId, string $message): void
    {
        $statistics['errors_count']++;

        if (count($statistics['errors']) < 100) {
            $statistics['errors'][] = [
                'variant_id' => $variantId,
                'message' => $message,
            ];
        }
    }

    private function centralWarehouse(): Warehouse
    {
        $warehouses = Warehouse::query()
            ->where('type', 'central')
            ->orderBy('id')
            ->limit(2)
            ->get(['id', 'name', 'type', 'is_active']);

        if ($warehouses->count() !== 1) {
            throw new RuntimeException(
                'Exactly one central warehouse is required. The export was stopped without creating database records.',
            );
        }

        return $warehouses->first();
    }

    private function uniqueOutputPath(?string $requestedPath, DateTimeInterface $exportedAt): string
    {
        $requestedPath = trim((string) $requestedPath);
        $path = $requestedPath !== ''
            ? $this->absoluteStoragePath($requestedPath)
            : storage_path(
                'app/exports/website-products/ariya-products-in-stock-'.$exportedAt->format('Ymd-His').'.csv',
            );

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('The output file must use the .csv extension.');
        }

        $directory = dirname($path);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $candidate = $path;
        $suffix = 1;

        while (file_exists($candidate) || file_exists($this->summaryPath($candidate))) {
            $candidate = $directory.DIRECTORY_SEPARATOR.$filename.'-'.$suffix.'.'.$extension;
            $suffix++;
        }

        return $candidate;
    }

    private function absoluteStoragePath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('The output path contains an invalid null byte.');
        }

        $isAbsolute = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1;
        if (str_starts_with($path, '\\\\')) {
            throw new RuntimeException('UNC output paths are not allowed.');
        }

        $absolute = $this->normalizePath($isAbsolute ? $path : base_path($path));
        $storageRoot = $this->normalizePath(storage_path('app'));
        $comparisonPath = strtolower($absolute);
        $comparisonRoot = strtolower(rtrim($storageRoot, '\\/'));

        if ($comparisonPath !== $comparisonRoot
            && ! str_starts_with($comparisonPath, $comparisonRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The output path must be inside storage/app.');
        }

        return $absolute;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'/', $path) === 1) {
            $prefix = substr($path, 0, 2).DIRECTORY_SEPARATOR;
            $path = substr($path, 3);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $prefix.implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function ensureOutputDirectory(string $outputPath): void
    {
        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create export directory: {$directory}");
        }

        $realDirectory = realpath($directory);
        $realStorageRoot = realpath(storage_path('app'));
        if ($realDirectory === false || $realStorageRoot === false) {
            throw new RuntimeException('Unable to verify the export directory.');
        }

        $directoryComparison = strtolower($this->normalizePath($realDirectory));
        $rootComparison = strtolower(rtrim($this->normalizePath($realStorageRoot), '\\/'));
        if ($directoryComparison !== $rootComparison
            && ! str_starts_with($directoryComparison, $rootComparison.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The resolved export directory is outside storage/app.');
        }
    }

    private function summaryPath(string $csvPath): string
    {
        return substr($csvPath, 0, -4).'-summary.json';
    }

    private function temporaryPath(string $finalPath): string
    {
        return $finalPath.'.part-'.bin2hex(random_bytes(8));
    }

    private function publishFile(string $temporaryPath, string $finalPath): void
    {
        if (file_exists($finalPath)) {
            throw new RuntimeException("Refusing to overwrite an existing export file: {$finalPath}");
        }

        if (! @rename($temporaryPath, $finalPath)) {
            throw new RuntimeException("Unable to publish export file: {$finalPath}");
        }
    }

    private function deleteIfFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function safeCsvValue(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $value = str_replace("\0", '', (string) ($value ?? ''));

        // Prevent spreadsheet formula execution while keeping the visible text.
        if (preg_match('/^[\s]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function formatDate(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : '';
    }
}
