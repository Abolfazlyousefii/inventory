<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Exports\WebsiteProductExportService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class WebsiteProductExportTest extends TestCase
{
    use RefreshDatabase;

    private string $exportDirectory;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportDirectory = storage_path('app/testing/website-product-export-'.bin2hex(random_bytes(6)));
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if (isset($this->exportDirectory) && is_dir($this->exportDirectory)) {
            File::deleteDirectory($this->exportDirectory);
        }

        parent::tearDown();
    }

    public function test_command_exports_only_active_sellable_in_stock_variants_as_utf8_csv_without_database_writes(): void
    {
        $central = $this->centralWarehouse();
        $root = $this->category('لوازم جانبی');
        $child = $this->category('قاب گوشی', $root);
        $model = ModelList::query()->create([
            'brand' => 'آریا',
            'model_name' => 'مدل ویژه',
            'code' => 'M001',
        ]);
        $color = Color::query()->create([
            'name' => 'آبی',
            'code' => '01',
            'hex_code' => '#0000ff',
        ]);
        Storage::disk('public')->put('products/sample.jpg', 'image-bytes');

        $product = $this->product([
            'name' => 'قاب فارسی',
            'category_id' => $child->id,
            'image_path' => 'products/sample.jpg',
            'price' => 99000,
            'unit' => 'عدد',
        ]);
        $included = $this->variant($product, [
            'model_list_id' => $model->id,
            'color_id' => $color->id,
            'variant_name' => 'قاب فارسی آبی',
            'variant_code' => 'VAR-0001',
            'sell_price' => 125000,
            'stock' => 4,
            'reserved' => 2,
        ]);
        $this->warehouseStock($central, $included, 4);

        $inactive = $this->variant($product, ['is_active' => false, 'stock' => 5]);
        $this->warehouseStock($central, $inactive, 5);
        $salesDisabled = $this->variant($product, ['sales_enabled' => false, 'stock' => 5]);
        $this->warehouseStock($central, $salesDisabled, 5);
        $zeroStock = $this->variant($product, ['stock' => 0]);
        $this->warehouseStock($central, $zeroStock, 0);

        $notSellable = $this->product(['is_sellable' => false, 'stock' => 5]);
        $notSellableVariant = $this->variant($notSellable, ['stock' => 5]);
        $this->warehouseStock($central, $notSellableVariant, 5);

        $writeQueries = [];
        DB::listen(function ($query) use (&$writeQueries): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop|create|rename)\b/i', $query->sql)) {
                $writeQueries[] = $query->sql;
            }
        });

        $output = $this->outputPath('main.csv');
        $this->artisan('products:export-for-website', [
            '--output' => $output,
            '--chunk' => 1,
        ])->assertSuccessful();

        $this->assertSame([], $writeQueries);
        $this->assertFileExists($output);
        $this->assertSame("\xEF\xBB\xBF", substr((string) file_get_contents($output), 0, 3));

        [$headers, $rows] = $this->readCsv($output);
        $this->assertSame(app(WebsiteProductExportService::class)->headers(), $headers);
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('قاب فارسی', $row['product_name']);
        $this->assertSame('product-'.$product->id, $row['product_sync_key']);
        $this->assertSame('variant-'.$included->id, $row['variant_sync_key']);
        $this->assertSame((string) $included->id, $row['variant_id']);
        $this->assertSame('4', $row['free_stock']);
        $this->assertSame('2', $row['reserved_stock']);
        $this->assertSame('6', $row['physical_stock']);
        $this->assertSame('1', $row['is_in_stock']);
        $this->assertSame('125000', $row['effective_sell_price_rial']);
        $this->assertSame('125000', $row['variant_sell_price_rial']);
        $this->assertSame('99000', $row['product_sell_price_rial']);
        $this->assertSame('variant', $row['price_source']);
        $this->assertSame('1', $row['has_valid_price']);
        $this->assertSame((string) $child->id, $row['category_id']);
        $this->assertSame('قاب گوشی', $row['category_name']);
        $this->assertSame((string) $root->id, $row['parent_category_id']);
        $this->assertSame('لوازم جانبی', $row['parent_category_name']);
        $this->assertSame('آریا', $row['brand_name']);
        $this->assertSame('عدد', $row['unit_name']);
        $this->assertSame('products/sample.jpg', $row['image_path']);
        $this->assertSame('sample.jpg', $row['image_filename']);
        $this->assertSame('', $row['image_public_url']);
        $this->assertSame('1', $row['has_image']);

        $summary = $this->readSummary($output);
        $this->assertSame(1, $summary['products_count']);
        $this->assertSame(1, $summary['variants_count']);
        $this->assertSame(1, $summary['in_stock_count']);
        $this->assertSame(0, $summary['zero_stock_count']);
        $this->assertSame(0, $summary['zero_price_count']);
        $this->assertSame(0, $summary['missing_image_count']);
        $this->assertFalse($summary['data_changed']);
    }

    public function test_zero_stock_and_zero_price_options_follow_the_real_sales_contract_without_product_price_fallback(): void
    {
        $central = $this->centralWarehouse();
        $product = $this->product(['price' => 80000, 'stock' => 0]);
        $variant = $this->variant($product, [
            'sell_price' => 0,
            'stock' => 0,
            'reserved' => 3,
        ]);
        $this->warehouseStock($central, $variant, 0);

        $defaultOutput = $this->outputPath('default.csv');
        $defaultResult = app(WebsiteProductExportService::class)->export([
            'output' => $defaultOutput,
        ]);
        $this->assertSame(0, $defaultResult['variants_count']);
        $this->assertCount(0, $this->readCsv($defaultOutput)[1]);

        $includedOutput = $this->outputPath('include-zero.csv');
        $includedResult = app(WebsiteProductExportService::class)->export([
            'output' => $includedOutput,
            'include_zero_stock' => true,
        ]);
        $row = $this->readCsv($includedOutput)[1][0];
        $this->assertSame('0', $row['free_stock']);
        $this->assertSame('3', $row['reserved_stock']);
        $this->assertSame('3', $row['physical_stock']);
        $this->assertSame('0', $row['is_in_stock']);
        $this->assertSame('0', $row['effective_sell_price_rial']);
        $this->assertSame('80000', $row['product_sell_price_rial']);
        $this->assertSame('none', $row['price_source']);
        $this->assertSame('0', $row['has_valid_price']);
        $this->assertSame(1, $includedResult['zero_stock_count']);
        $this->assertSame(1, $includedResult['zero_price_count']);

        $excludedOutput = $this->outputPath('exclude-zero-price.csv');
        $excludedResult = app(WebsiteProductExportService::class)->export([
            'output' => $excludedOutput,
            'include_zero_stock' => true,
            'exclude_zero_price' => true,
        ]);
        $this->assertSame(0, $excludedResult['variants_count']);
        $this->assertSame(1, $excludedResult['excluded_zero_price_count']);
        $this->assertSame(0, $excludedResult['zero_price_count']);
        $this->assertCount(0, $this->readCsv($excludedOutput)[1]);
    }

    public function test_only_central_warehouse_stock_is_exported_and_reserved_is_not_subtracted_twice(): void
    {
        $central = $this->centralWarehouse();
        $other = Warehouse::query()->create([
            'name' => 'انبار پرسنلی',
            'type' => 'personnel',
            'is_active' => true,
        ]);
        $product = $this->product(['stock' => 9]);

        $included = $this->variant($product, [
            'stock' => 9,
            'reserved' => 5,
            'sell_price' => 1000,
        ]);
        $this->warehouseStock($central, $included, 2);
        $this->warehouseStock($other, $included, 100);

        $nonCentralOnly = $this->variant($product, [
            'stock' => 7,
            'reserved' => 0,
            'sell_price' => 1000,
        ]);
        $this->warehouseStock($other, $nonCentralOnly, 7);

        $output = $this->outputPath('central-only.csv');
        $result = app(WebsiteProductExportService::class)->export(['output' => $output]);
        $rows = $this->readCsv($output)[1];

        $this->assertCount(1, $rows);
        $this->assertSame((string) $included->id, $rows[0]['variant_id']);
        $this->assertSame('2', $rows[0]['free_stock']);
        $this->assertSame('5', $rows[0]['reserved_stock']);
        $this->assertSame('7', $rows[0]['physical_stock']);
        $this->assertSame(1, $result['stock_cache_mismatch_count']);
        $this->assertSame(1, $result['errors_count']);
    }

    public function test_chunked_processing_keeps_product_sync_keys_stable_and_variant_keys_unique(): void
    {
        $central = $this->centralWarehouse();
        $product = $this->product(['stock' => 6]);
        $variants = collect(range(1, 3))->map(function (int $number) use ($central, $product) {
            $variant = $this->variant($product, [
                'variant_name' => 'تنوع '.$number,
                'stock' => $number,
                'sell_price' => 1000 + $number,
            ]);
            $this->warehouseStock($central, $variant, $number);

            return $variant;
        });
        $progress = [];
        $output = $this->outputPath('chunked.csv');

        app(WebsiteProductExportService::class)->export(
            ['output' => $output, 'chunk' => 1],
            function (int $processed, int $total) use (&$progress): void {
                $progress[] = [$processed, $total];
            },
        );

        $rows = $this->readCsv($output)[1];
        $this->assertCount(3, $rows);
        $this->assertSame(['product-'.$product->id], array_values(array_unique(array_column($rows, 'product_sync_key'))));
        $this->assertCount(3, array_unique(array_column($rows, 'variant_sync_key')));
        $this->assertSame(
            $variants->map(fn (ProductVariant $variant) => 'variant-'.$variant->id)->all(),
            array_column($rows, 'variant_sync_key'),
        );
        $this->assertSame([[0, 3], [1, 3], [2, 3], [3, 3]], $progress);
    }

    public function test_repeated_export_never_overwrites_an_existing_csv_or_summary(): void
    {
        $central = $this->centralWarehouse();
        $product = $this->product(['stock' => 1]);
        $variant = $this->variant($product, ['stock' => 1]);
        $this->warehouseStock($central, $variant, 1);
        $requested = $this->outputPath('repeat.csv');

        $first = app(WebsiteProductExportService::class)->export(['output' => $requested]);
        $second = app(WebsiteProductExportService::class)->export(['output' => $requested]);

        $this->assertNotSame($first['output_file'], $second['output_file']);
        $this->assertFileExists($first['output_file']);
        $this->assertFileExists($second['output_file']);
        $this->assertFileExists($first['summary_file']);
        $this->assertFileExists($second['summary_file']);
    }

    public function test_io_failure_removes_every_partial_and_final_file(): void
    {
        $central = $this->centralWarehouse();
        $product = $this->product(['stock' => 1]);
        $variant = $this->variant($product, ['stock' => 1]);
        $this->warehouseStock($central, $variant, 1);
        $output = $this->outputPath('must-not-remain.csv');

        $service = new class extends WebsiteProductExportService
        {
            private int $writeCount = 0;

            protected function writeCsvRow($handle, array $row): void
            {
                $this->writeCount++;
                if ($this->writeCount > 1) {
                    throw new RuntimeException('Simulated write failure.');
                }

                parent::writeCsvRow($handle, $row);
            }
        };

        try {
            $service->export(['output' => $output]);
            $this->fail('The simulated write failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated write failure.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($output);
        $this->assertFileDoesNotExist(substr($output, 0, -4).'-summary.json');
        $this->assertSame([], glob($output.'.part-*') ?: []);
        $this->assertSame([], glob(substr($output, 0, -4).'-summary.json.part-*') ?: []);
    }

    public function test_command_rejects_unsupported_format_invalid_chunk_and_output_outside_storage(): void
    {
        $this->artisan('products:export-for-website', ['--format' => 'json'])
            ->assertFailed();
        $this->artisan('products:export-for-website', ['--chunk' => 0])
            ->assertFailed();

        $this->centralWarehouse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inside storage/app');
        app(WebsiteProductExportService::class)->export([
            'output' => base_path('outside.csv'),
        ]);
    }

    public function test_admin_module_exposes_the_shared_export_with_the_existing_permission(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.product-exports.website-products.download');

        $this->assertNotNull($route);
        $this->assertContains('permission:products.export', $route->gatherMiddleware());
        $this->assertSame(
            'products.export',
            PermissionCatalog::routePermissions()['admin.product-exports.website-products.download'],
        );
    }

    private function centralWarehouse(): Warehouse
    {
        return Warehouse::query()->create([
            'name' => 'انبار مرکزی',
            'type' => 'central',
            'is_active' => true,
        ]);
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::withoutEvents(fn () => Category::query()->create([
            'name' => $name,
            'parent_id' => $parent?->id,
        ]));
    }

    private function product(array $attributes = []): Product
    {
        $this->sequence++;
        $categoryId = $attributes['category_id'] ?? $this->category('دسته '.$this->sequence)->id;

        return Product::withoutEvents(fn () => Product::query()->create(array_merge([
            'name' => 'محصول '.$this->sequence,
            'sku' => 'SKU-'.$this->sequence,
            'category_id' => $categoryId,
            'stock' => 0,
            'reserved' => 0,
            'price' => 0,
            'is_sellable' => true,
        ], $attributes)));
    }

    private function variant(Product $product, array $attributes = []): ProductVariant
    {
        $this->sequence++;

        return ProductVariant::withoutEvents(fn () => ProductVariant::query()->create(array_merge([
            'product_id' => $product->id,
            'variant_name' => 'تنوع '.$this->sequence,
            'variant_code' => 'V-'.str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT),
            'sell_price' => 1000,
            'stock' => 0,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ], $attributes)));
    }

    private function warehouseStock(Warehouse $warehouse, ProductVariant $variant, int $quantity): WarehouseStock
    {
        return WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);
    }

    private function outputPath(string $filename): string
    {
        return $this->exportDirectory.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertIsResource($handle);
        $this->assertSame("\xEF\xBB\xBF", fread($handle, 3));
        $headers = fgetcsv($handle, null, ',', '"', '');
        $this->assertIsArray($headers);
        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $this->assertCount(count($headers), $values);
            $rows[] = array_combine($headers, $values);
        }

        fclose($handle);

        return [$headers, $rows];
    }

    private function readSummary(string $csvPath): array
    {
        $path = substr($csvPath, 0, -4).'-summary.json';
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
