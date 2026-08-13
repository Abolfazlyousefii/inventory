<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ProductPriceListPdfService
{
    public function render(array $products, array $meta): string
    {
        $font = $this->resolveFontConfig();
        $fontFamily = $font['family'];
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        if ($font['dir'] !== null && $font['data'] !== null) {
            $fontDirs[] = $font['dir'];
            $fontData[$fontFamily] = $font['data'];
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'orientation' => 'L',
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 18,
            'margin_bottom' => 14,
            'margin_header' => 5,
            'margin_footer' => 5,
            'tempDir' => $tempDir,
            'fontDir' => $fontDirs,
            'fontdata' => $fontData,
            'default_font' => $fontFamily,
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
        ]);

        $visitMode = ($meta['output_mode'] ?? 'catalog') === 'visit';
        $view = $visitMode ? 'product-exports.visit-price-list-pdf' : 'product-exports.price-list-pdf';
        $title = $visitMode ? 'لیست قیمت و موجودی ویزیتوری آریا گستر' : 'لیست قیمت محصولات آریا گستر';
        $footerNote = $visitMode
            ? 'قیمت و موجودی براساس آخرین اطلاعات ثبت‌شده در سامانه هستند.'
            : 'قیمت‌ها براساس آخرین اطلاعات ثبت‌شده در سامانه هستند.';

        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($title);
        $mpdf->SetHTMLHeader(view('product-exports.partials.pdf-header', compact('meta', 'fontFamily'))->render());
        $mpdf->SetHTMLFooter('<table width="100%" style="font-family:'.$fontFamily.',dejavusans,sans-serif;font-size:6.8pt;font-weight:400;color:#778892;border-top:0.7px solid #D8E3E9;padding-top:3px"><tr><td width="33%" align="right">مجموعه آریا گستر</td><td width="34%" align="center">'.$footerNote.'</td><td width="33%" align="left">صفحه {PAGENO} از {nbpg}</td></tr></table>');
        $mpdf->WriteHTML(view($view, compact('products', 'meta', 'fontFamily'))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function fontSource(): string
    {
        $font = $this->resolveFontConfig();

        return $font['dir'] === null
            ? 'DejaVuSans'
            : $font['dir'].DIRECTORY_SEPARATOR.$font['data']['R'];
    }

    public function fontFamily(): string
    {
        return $this->resolveFontConfig()['family'];
    }

    public function resolveFontConfig(): array
    {
        $vazir = $this->findFont(
            family: 'vazir',
            source: 'vazir',
            directories: [
                public_path('fonts/vazir'),
                public_path('fonts/Vazir'),
                public_path('css/fonts/vazir'),
                resource_path('fonts/vazir'),
            ],
            regularFiles: ['Vazir-Regular.ttf', 'Vazir.ttf', 'Vazir-Regular.otf', 'Vazir-Medium.ttf'],
            mediumFiles: ['Vazir-Medium.ttf'],
            boldFiles: ['Vazir-Bold.ttf', 'Vazir-Bold.otf'],
        );

        if ($vazir !== null) {
            return $vazir;
        }

        $vazirmatn = $this->findFont(
            family: 'vazirmatn',
            source: 'vazirmatn',
            directories: [
                public_path('fonts/vazirmatn'),
                public_path('css/fonts/vazirmatn'),
            ],
            regularFiles: ['Vazirmatn-Regular.ttf', 'Vazirmatn-Regular.otf', 'Vazirmatn-Medium.ttf'],
            mediumFiles: ['Vazirmatn-Medium.ttf'],
            boldFiles: ['Vazirmatn-SemiBold.ttf', 'Vazirmatn-Bold.ttf'],
        );

        if ($vazirmatn !== null) {
            Log::warning('فونت Vazir پیدا نشد و PDF با Vazirmatn ساخته شد.');

            return $vazirmatn;
        }

        Log::warning('فونت‌های Vazir و Vazirmatn قابل استفاده برای mPDF پیدا نشدند و PDF با DejaVuSans ساخته شد.', [
            'note' => 'فایل‌های WOFF2 برای ثبت فونت سفارشی در mPDF قابل استفاده نیستند.',
        ]);

        return [
            'family' => 'dejavusans',
            'source' => 'mpdf-default',
            'dir' => null,
            'data' => null,
        ];
    }

    protected function findFont(
        string $family,
        string $source,
        array $directories,
        array $regularFiles,
        array $mediumFiles,
        array $boldFiles,
    ): ?array {
        foreach ($directories as $directory) {
            $regular = $this->firstExistingFile($directory, $regularFiles);
            if ($regular === null) {
                continue;
            }

            $medium = $this->firstExistingFile($directory, $mediumFiles) ?? $regular;
            $bold = $this->firstExistingFile($directory, $boldFiles) ?? $regular;

            return [
                'family' => $family,
                'source' => $source,
                'dir' => $directory,
                'data' => [
                    'R' => $regular,
                    'M' => $medium,
                    'B' => $bold,
                    'useOTL' => 0xFF,
                ],
            ];
        }

        return null;
    }

    private function firstExistingFile(string $directory, array $files): ?string
    {
        foreach ($files as $file) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                return $file;
            }
        }

        return null;
    }
}
