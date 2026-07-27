<?php

namespace Tests\Unit;

use App\Services\ProductPriceListPdfService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductPriceListVazirFontTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_vazir_has_priority_when_it_is_available(): void
    {
        $service = $this->fontResolver([
            'vazir' => $this->fontConfig('vazir', 'Vazir-Regular.ttf'),
            'vazirmatn' => $this->fontConfig('vazirmatn', 'Vazirmatn-Regular.ttf'),
        ]);

        $config = $service->resolveFontConfig();

        $this->assertSame('vazir', $config['family']);
        $this->assertSame('vazir', $config['source']);
        $this->assertSame('Vazir-Regular.ttf', $config['data']['R']);
        $this->assertSame('Vazir-Regular.ttf', $config['data']['M']);
        $this->assertSame('Vazir-Regular.ttf', $config['data']['B']);
    }

    public function test_vazirmatn_is_selected_without_exception_when_vazir_is_missing(): void
    {
        $service = $this->fontResolver([
            'vazirmatn' => $this->fontConfig('vazirmatn', 'Vazirmatn-Regular.ttf'),
        ]);

        $config = $service->resolveFontConfig();

        $this->assertSame('vazirmatn', $config['family']);
        $this->assertSame('vazirmatn', $config['source']);
        $this->assertStringEndsWith('Vazirmatn-Regular.ttf', $service->fontSource());
    }

    public function test_dejavu_is_selected_without_exception_when_project_fonts_are_missing(): void
    {
        $service = $this->fontResolver([]);

        $config = $service->resolveFontConfig();

        $this->assertSame('dejavusans', $config['family']);
        $this->assertSame('mpdf-default', $config['source']);
        $this->assertNull($config['dir']);
        $this->assertNull($config['data']);
        $this->assertSame('DejaVuSans', $service->fontSource());
    }

    public function test_font_fallback_writes_warning_to_laravel_log(): void
    {
        Log::spy();
        $service = $this->fontResolver([
            'vazirmatn' => $this->fontConfig('vazirmatn', 'Vazirmatn-Regular.ttf'),
        ]);

        $service->resolveFontConfig();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('فونت Vazir پیدا نشد و PDF با Vazirmatn ساخته شد.');
    }

    public function test_body_header_footer_and_default_font_use_resolved_family(): void
    {
        $serviceSource = file_get_contents(app_path('Services/ProductPriceListPdfService.php'));
        $body = view('product-exports.price-list-pdf', [
            'products' => [],
            'meta' => $this->meta(),
            'fontFamily' => 'vazirmatn',
        ])->render();
        $header = view('product-exports.partials.pdf-header', [
            'meta' => $this->meta(),
            'fontFamily' => 'vazirmatn',
        ])->render();

        $this->assertStringContainsString("'default_font' => \$fontFamily", $serviceSource);
        $this->assertStringContainsString('font-family:vazirmatn,dejavusans,sans-serif', $body);
        $this->assertStringContainsString('font-family:vazirmatn,dejavusans,sans-serif', $header);
        $this->assertStringContainsString('font-family:\'.$fontFamily.\',dejavusans,sans-serif', $serviceSource);
        $this->assertStringNotContainsString('MissingVazirFontException', $serviceSource);
    }

    public function test_current_project_can_render_persian_and_latin_pdf_without_font_exception(): void
    {
        $service = app(ProductPriceListPdfService::class);
        $pdf = $service->render([[
            'name' => 'ایرپاد AirPods 13',
            'has_real_image' => false,
            'image_path' => null,
            'category_name' => 'لوازم جانبی',
            'model_count' => 1,
            'color_count' => 0,
            'variant_count' => 1,
            'price_summary' => '1,250,000 ریال',
            'groups' => [[
                'models' => ['AirPods 13'],
                'colors' => [],
                'price_label' => '1,250,000 ریال',
            ]],
        ]], $this->meta());

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertContains($service->fontFamily(), ['vazir', 'vazirmatn', 'dejavusans']);
    }

    private function fontResolver(array $available): ProductPriceListPdfService
    {
        return new class($available) extends ProductPriceListPdfService {
            public function __construct(private readonly array $available) {}

            protected function findFont(
                string $family,
                string $source,
                array $directories,
                array $regularFiles,
                array $mediumFiles,
                array $boldFiles,
            ): ?array {
                return $this->available[$family] ?? null;
            }
        };
    }

    private function fontConfig(string $family, string $regular): array
    {
        return [
            'family' => $family,
            'source' => $family,
            'dir' => 'C:\\fonts\\'.$family,
            'data' => [
                'R' => $regular,
                'M' => $regular,
                'B' => $regular,
                'useOTL' => 0xFF,
            ],
        ];
    }

    private function meta(): array
    {
        return [
            'generated_at' => '1405/05/05 12:00',
            'root_category' => 'همه دسته‌ها',
            'subcategory' => 'همه زیردسته‌ها',
            'model_brand' => 'همه انواع مدل',
            'model_lists' => 'همه مدل‌ها',
            'selected_products' => 'همه محصولات',
            'products_count' => 1,
            'stock_status' => 'همه',
        ];
    }
}
