<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductSelectionPdfService
{
    public function __construct(private readonly ProductExportService $exports) {}

    public function render(array $productIds): string
    {
        $products = $this->buildRows($productIds);

        $fontDir = storage_path('fonts');
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $fontDirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];
        $fontFamily = 'dejavusans';
        if (is_file($fontDir.'/Vazirmatn-Regular.ttf')) {
            $fontDirs[] = $fontDir;
            $fontData['vazirmatn'] = [
                'R' => 'Vazirmatn-Regular.ttf',
                'B' => 'Vazirmatn-Bold.ttf',
                'useOTL' => 0xFF,
            ];
            $fontFamily = 'vazirmatn';
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 9,
            'margin_right' => 9,
            'margin_top' => 10,
            'margin_bottom' => 12,
            'tempDir' => $tempDir,
            'fontDir' => $fontDirs,
            'fontdata' => $fontData,
            'default_font' => $fontFamily,
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('لیست کالاهای انتخاب‌شده آریا گستر');
        $mpdf->WriteHTML(view('products.selected-catalog-pdf', compact('products', 'fontFamily'))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function buildRows(array $productIds): array
    {
        $productsById = Product::query()
            ->whereIn('id', $productIds)
            ->withCount('variants')
            ->with([
                'category:id,name',
                'catalogVariants' => fn ($query) => $query->where('is_active', true)
                    ->with(['modelList:id,brand,model_name,code', 'color:id,name,code,hex_code'])
                    ->orderBy('model_list_id')->orderBy('variant_name')->orderBy('variety_name')->orderBy('id'),
            ])
            ->get()->keyBy('id');

        if ($productsById->count() !== count($productIds)) {
            throw ValidationException::withMessages(['product_ids' => 'یکی از کالاهای انتخابی دیگر موجود نیست.']);
        }

        return collect($productIds)->map(function (int $id) use ($productsById) {
            $product = $productsById->get($id);
            $row = $this->exports->mapVisitProduct($product, ['include_without_price' => true]);
            $row['image_data'] = $this->imageData($product->image_path);

            return $row;
        })->all();
    }

    private function imageData(?string $imagePath): ?string
    {
        $path = trim((string) $imagePath);
        if ($path === '') {
            return null;
        }

        try {
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                $host = strtolower((string) parse_url($path, PHP_URL_HOST));
                $allowedHosts = array_filter([
                    parse_url((string) config('filesystems.disks.arvan.url'), PHP_URL_HOST),
                    parse_url((string) config('filesystems.disks.arvan.endpoint'), PHP_URL_HOST),
                    parse_url((string) config('services.ariya_crm.base_url'), PHP_URL_HOST),
                ]);
                $trusted = $host === 'ariyajanebi.ir' || str_ends_with($host, '.ariyajanebi.ir')
                    || in_array($host, $allowedHosts, true);
                if (parse_url($path, PHP_URL_SCHEME) !== 'https' || ! $trusted) {
                    return null;
                }
                $response = Http::timeout(5)->withOptions(['allow_redirects' => false])->get($path);
                if (! $response->successful()) {
                    return null;
                }
                $bytes = $response->body();
            } else {
                $path = ltrim($path, '/');
                $bytes = null;
                foreach (['public', 'arvan'] as $disk) {
                    if (! Storage::disk($disk)->exists($path)) {
                        continue;
                    }
                    $stream = Storage::disk($disk)->readStream($path);
                    if (is_resource($stream)) {
                        $bytes = stream_get_contents($stream, 4_000_001);
                        fclose($stream);
                        break;
                    }
                }
            }

            if (! is_string($bytes) || strlen($bytes) > 4_000_000) {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        } catch (Throwable) {
            return null;
        }
    }
}
