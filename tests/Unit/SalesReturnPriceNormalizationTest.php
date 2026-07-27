<?php

namespace Tests\Unit;

use App\Http\Requests\StoreSalesReturnRequest;
use PHPUnit\Framework\TestCase;

class SalesReturnPriceNormalizationTest extends TestCase
{
    public function test_persian_arabic_and_english_money_inputs_become_integer_rials(): void
    {
        $request = TestableStoreSalesReturnRequest::create('/', 'POST', [
            'default_destination_warehouse_id' => 1,
            'items' => [
                ['refund_unit_price' => '۱۲٬۳۴۵ ریال'],
                ['refund_unit_price' => '١٢,٣٤٥'],
                ['refund_unit_price' => '12,345'],
            ],
        ]);

        $request->runPriceNormalization();

        $this->assertSame(12_345, $request->input('items.0.refund_unit_price'));
        $this->assertSame(12_345, $request->input('items.1.refund_unit_price'));
        $this->assertSame(12_345, $request->input('items.2.refund_unit_price'));
    }

    public function test_external_invoice_datetime_is_normalized_to_date_only(): void
    {
        $request = TestableStoreSalesReturnRequest::create('/', 'POST', [
            'external_invoice_date' => '2026-07-26 14:30:00',
            'items' => [],
        ]);

        $request->runPriceNormalization();

        $this->assertSame('2026-07-26', $request->input('external_invoice_date'));
    }

    public function test_external_invoice_date_remains_y_m_d(): void
    {
        $request = TestableStoreSalesReturnRequest::create('/', 'POST', [
            'external_invoice_date' => '2026-07-26',
            'items' => [],
        ]);

        $request->runPriceNormalization();

        $this->assertSame('2026-07-26', $request->input('external_invoice_date'));
    }
}

class TestableStoreSalesReturnRequest extends StoreSalesReturnRequest
{
    public function runPriceNormalization(): void
    {
        $this->prepareForValidation();
    }
}
