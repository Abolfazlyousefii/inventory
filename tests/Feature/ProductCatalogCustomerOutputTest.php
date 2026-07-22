<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCatalogCustomerOutputTest extends TestCase
{
    public function test_legacy_customer_catalog_suite_is_replaced_by_unified_price_list(): void
    {
        $this->markTestSkipped('Legacy customer catalog output was replaced by the unified corporate price list PDF.');
    }
}
