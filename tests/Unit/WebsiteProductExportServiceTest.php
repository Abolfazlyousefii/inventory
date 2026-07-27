<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Exports\WebsiteProductExportService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WebsiteProductExportServiceTest extends TestCase
{
    public function test_headers_are_exact_stable_and_unique(): void
    {
        $expectedHeaders = [
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

        $headers = (new WebsiteProductExportService)->headers();

        $this->assertSame($expectedHeaders, $headers);
        $this->assertCount(count(array_unique($headers)), $headers);
    }

    public function test_price_data_uses_the_positive_variant_price(): void
    {
        $variant = new ProductVariant(['sell_price' => 12_500_000]);
        $product = new Product(['price' => 99_000_000]);

        $this->assertSame([
            'effective_sell_price_rial' => 12_500_000,
            'variant_sell_price_rial' => 12_500_000,
            'product_sell_price_rial' => 99_000_000,
            'price_source' => 'variant',
            'has_valid_price' => true,
        ], (new WebsiteProductExportService)->priceData($variant, $product));
    }

    public function test_price_data_never_falls_back_to_the_product_price(): void
    {
        $variant = new ProductVariant(['sell_price' => 0]);
        $product = new Product(['price' => 99_000_000]);

        $this->assertSame([
            'effective_sell_price_rial' => 0,
            'variant_sell_price_rial' => 0,
            'product_sell_price_rial' => 99_000_000,
            'price_source' => 'none',
            'has_valid_price' => false,
        ], (new WebsiteProductExportService)->priceData($variant, $product));
    }

    public function test_stock_data_uses_the_central_alias_without_subtracting_reserved_twice(): void
    {
        $variant = new ProductVariant([
            'stock' => 91,
            'reserved' => 9,
        ]);
        $variant->setAttribute('central_warehouse_stock', 40);

        $this->assertSame([
            'free_stock' => 40,
            'reserved_stock' => 9,
            'physical_stock' => 49,
            'is_in_stock' => true,
            'central_warehouse_stock' => 40,
            'cached_free_stock' => 91,
            'cache_mismatch' => true,
        ], (new WebsiteProductExportService)->stockData($variant));
    }

    public function test_csv_writer_neutralizes_spreadsheet_formulas(): void
    {
        $service = new class extends WebsiteProductExportService
        {
            public function csvLine(array $values): string
            {
                $handle = fopen('php://temp', 'w+b');

                if ($handle === false) {
                    throw new RuntimeException('Unable to open an in-memory stream.');
                }

                $this->writeCsvRow($handle, $values);
                rewind($handle);
                $line = stream_get_contents($handle);
                fclose($handle);

                if ($line === false) {
                    throw new RuntimeException('Unable to read the in-memory stream.');
                }

                return $line;
            }
        };

        $csv = $service->csvLine([
            '=2+2',
            ' +SUM(A1:A2)',
            "\t-CMD",
            '@hidden',
            'ordinary text',
            123,
            true,
            null,
        ]);

        $this->assertSame([
            "'=2+2",
            "' +SUM(A1:A2)",
            "'\t-CMD",
            "'@hidden",
            'ordinary text',
            '123',
            '1',
            '',
        ], str_getcsv($csv, ',', '"', ''));
    }
}
