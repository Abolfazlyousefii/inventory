<?php

namespace Tests\Feature;

use App\Models\PreinvoiceOrder;
use Tests\TestCase;

class WebsiteOrderInboundTest extends TestCase
{
    public function test_website_orders_target_preinvoice_not_invoice(): void
    {
        $this->assertSame(PreinvoiceOrder::STATUS_PENDING_FINANCE, 'pending_finance');
    }
}
