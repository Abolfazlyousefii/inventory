<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCatalogPrintLayoutTest extends TestCase
{
    public function test_legacy_print_layout_suite_is_replaced_by_pdf_download(): void
    {
        $this->markTestSkipped('Legacy browser print layout was replaced by direct mPDF download.');
    }
}
