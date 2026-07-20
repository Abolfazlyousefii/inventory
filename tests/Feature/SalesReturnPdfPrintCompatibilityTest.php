<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnPdfPrintCompatibilityTest extends TestCase
{
    public function test_show_page_no_longer_has_pdf_button_but_keeps_print(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/show.blade.php'));
        $this->assertStringNotContainsString('vouchers.return-from-sale.pdf', $blade);
        $this->assertStringNotContainsString('PDF', $blade);
        $this->assertStringContainsString('vouchers.return-from-sale.print', $blade);
        $this->assertStringContainsString('چاپ', $blade);
    }

    public function test_legacy_pdf_route_redirects_to_print_without_mpdf(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));
        $this->assertStringContainsString("public function pdf(SalesReturnDocument $document){ return redirect()->route('vouchers.return-from-sale.print', $document); }", $controller);
        $this->assertStringNotContainsString('Mpdf\\Mpdf', $controller);
        $this->assertStringNotContainsString('pdfResponse', $controller);
    }
}
