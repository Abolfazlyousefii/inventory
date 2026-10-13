<?php

namespace Tests\Feature;

use App\Models\SalesReturnDocument;
use App\Services\SalesReturnAppliedAdjustmentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnAppliedVoidInventoryTest extends TestCase
{
    public function test_direct_void_cannot_reverse_received_stock_or_customer_credit(): void
    {
        $doc = new SalesReturnDocument(['document_number' => '00001', 'status' => 'applied', 'total_quantity' => 2, 'total_refund_amount' => 200]);
        $before = $doc->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            try {
                app(SalesReturnAppliedAdjustmentService::class)->voidApplied($doc, 1, 'ابطال مستقیم');
                $this->fail('Direct void allowed');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document', $e->errors());
            }
        }
        $this->assertSame($before, $doc->getAttributes());
    }
}
