<?php

namespace Tests\Unit;

use App\Http\Requests\FinanceUpdatePreinvoiceRequest;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Services\PreinvoiceDiscountService;
use App\Services\ProductDiscountAllocator;
use App\Support\FinanceDocumentVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscountWorkflowGuardsTest extends TestCase
{
    private function order(): PreinvoiceOrder
    {
        $order = new PreinvoiceOrder(['uuid' => 'TEST', 'customer_name' => 'Test', 'invoice_discount_type' => 'percent', 'invoice_discount_value' => '2.55']);
        $item = new PreinvoiceOrderItem(['product_id' => 1, 'variant_id' => 2, 'quantity' => 2, 'price' => 1000, 'line_discount_amount' => 50]);
        $item->id = 10;

        return $order->setRelation('items', collect([$item]));
    }

    public function test_unchanged_document_version_is_accepted_without_database_writes(): void
    {
        $order = $this->order();
        FinanceDocumentVersion::assertCurrent($order, FinanceDocumentVersion::token($order));
        $this->assertTrue(true);
    }

    public function test_stale_finance_document_is_rejected_even_with_same_item_ids(): void
    {
        $order = $this->order();
        $token = FinanceDocumentVersion::token($order);
        $order->items->first()->price = 2000;
        $this->expectException(ValidationException::class);
        FinanceDocumentVersion::assertCurrent($order, $token);
    }

    public function test_missing_version_cannot_bypass_finance_guard(): void
    {
        $this->expectException(ValidationException::class);
        FinanceDocumentVersion::assertCurrent($this->order(), null);
    }

    public function test_snapshot_detects_discount_customer_and_quantity_changes_but_ignores_stock(): void
    {
        $order = $this->order();
        $token = FinanceDocumentVersion::token($order);
        $order->setAttribute('stock', 900);
        $this->assertSame($token, FinanceDocumentVersion::token($order));
        foreach (['customer_id' => 18, 'shipping_price' => 500, 'invoice_discount_value' => '3.25', 'discount_breakdown' => ['groups' => []]] as $field => $value) {
            $copy = clone $order;
            $copy->setAttribute($field, $value);
            $this->assertNotSame($token, FinanceDocumentVersion::token($copy));
        }
        $order->items->first()->quantity++;
        $this->assertNotSame($token, FinanceDocumentVersion::token($order));
    }

    public function test_missing_optional_finance_groups_stay_absent_instead_of_becoming_empty(): void
    {
        $request = FinanceUpdatePreinvoiceRequest::create('/test', 'PUT', ['invoice_discount_type' => 'percent']);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $this->assertFalse($request->exists('product_discounts'));
        $this->assertFalse($request->exists('invoice_discount_value'));
    }

    public function test_duplicate_finance_rows_are_rejected_before_keying_by_id(): void
    {
        $request = new FinanceUpdatePreinvoiceRequest;
        $validator = Validator::make(['items' => [['id' => 1, 'quantity' => 1, 'price' => 100], ['id' => 1, 'quantity' => 2, 'price' => 100]], 'invoice_discount_type' => 'none', 'edit_reason' => 'test'], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('items.0.id'));
    }

    public function test_duplicate_product_discount_is_not_silently_overwritten(): void
    {
        $this->expectException(ValidationException::class);
        (new ProductDiscountAllocator)->allocate($this->order()->items, [['product_id' => 1, 'type' => 'amount', 'value' => 10], ['product_id' => 1, 'type' => 'amount', 'value' => 20]]);
    }

    public function test_malformed_discount_breakdown_is_rejected_instead_of_becoming_empty(): void
    {
        $this->expectException(ValidationException::class);
        $service = app(PreinvoiceDiscountService::class);
        (new \ReflectionMethod($service, 'decodeBreakdown'))->invoke($service, '{broken');
    }

    public function test_incomplete_discount_group_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $service = app(PreinvoiceDiscountService::class);
        (new \ReflectionMethod($service, 'decodeBreakdown'))->invoke($service, ['groups' => [['product_id' => 1, 'discount_type' => 'percent']]]);
    }
}
