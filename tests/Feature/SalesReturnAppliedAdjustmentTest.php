<?php

namespace Tests\Feature;

use App\Models\SalesReturnDocument;
use App\Services\SalesReturnAppliedAdjustmentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnAppliedAdjustmentTest extends TestCase
{
    public function test_legacy_direct_applied_edit_does_not_change_received_document(): void
    {
        $doc = new SalesReturnDocument(['document_number' => '00001', 'status' => 'applied', 'total_quantity' => 2, 'total_refund_amount' => 200]);
        $before = $doc->getAttributes();
        try {
            app(SalesReturnAppliedAdjustmentService::class)->updateApplied($doc, ['items' => []], 1, 'اصلاح مستقیم');
            $this->fail('Direct edit allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $this->assertSame($before, $doc->getAttributes());
    }
}
