<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ProductPriceListPdfService
{
    public function render(array $products, array $meta): string
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];
        $font = $this->fontConfig();

        if ($font) {
            $fontDirs[] = $font['dir'];
            $fontData = $fontData + ['ariafont' => $font['data']];
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
            'default_font' => $font ? 'ariafont' : 'dejavusans',
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('لیست قیمت محصولات آریا گستر');
        $mpdf->SetHTMLHeader(view('product-exports.partials.pdf-header', compact('meta'))->render());
        $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7.5pt;color:#667784;border-top:1px solid #D8E3E9;padding-top:4px"><tr><td width="33%" align="right">مجموعه آریا گستر</td><td width="34%" align="center">قیمت‌ها براساس آخرین اطلاعات ثبت‌شده در سامانه هستند.</td><td width="33%" align="left">صفحه {PAGENO} از {nbpg}</td></tr></table>');
        $mpdf->WriteHTML(view('product-exports.price-list-pdf', compact('products', 'meta'))->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function fontSource(): string
    {
        $font = $this->fontConfig();
        return $font ? $font['dir'].'/'.$font['data']['R'] : 'DejaVuSans';
    }

    private function fontConfig(): ?array
    {
        foreach ([public_path('fonts/vazirmatn'), public_path('css/fonts/vazirmatn')] as $dir) {
            foreach (['Vazirmatn-Regular.ttf', 'Vazirmatn-Regular.otf'] as $regular) {
                if (is_file($dir.'/'.$regular)) {
                    $bold = is_file($dir.'/Vazirmatn-SemiBold.ttf') ? 'Vazirmatn-SemiBold.ttf' : $regular;
                    return ['dir' => $dir, 'data' => ['R' => $regular, 'B' => $bold]];
                }
            }
        }
        return null;
    }
}
