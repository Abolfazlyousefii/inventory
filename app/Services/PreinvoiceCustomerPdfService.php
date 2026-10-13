<?php

namespace App\Services;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class PreinvoiceCustomerPdfService
{
    public function render(array $printData): string
    {
        // Reuse the customer print data and template, without changing other prints.
        $printData['mode'] = 'customer';
        $printData['logo'] = is_file(public_path('logo.png')) ? public_path('logo.png') : '';
        $font = app(ProductPriceListPdfService::class)->resolveFontConfig();
        $dirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $data = (new FontVariables())->getDefaults()['fontdata'];
        if ($font['dir'] !== null && $font['data'] !== null) {
            $dirs[] = $font['dir'];
            $data[$font['family']] = $font['data'];
        }
        $temp = storage_path('app/mpdf');
        if (! is_dir($temp)) mkdir($temp, 0755, true);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp,
            'fontDir' => $dirs, 'fontdata' => $data, 'default_font' => $font['family'],
            'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 12]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetTitle($printData['title'].' '.$printData['number']);
        $pdf->SetHTMLFooter('<div style="text-align:center;font-size:8pt">{PAGENO} / {nbpg}</div>');
        $html = view('prints.preinvoice-customer-pdf', ['printData' => $printData, 'fontFamily' => $font['family']])->render();
        // mPDF does not reliably apply descendant image sizing from the browser template.
        $html = str_replace('<img src=', '<img width="40" height="40" src=', $html);
        $pdf->WriteHTML($html);
        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
