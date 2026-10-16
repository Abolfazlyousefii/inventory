<?php

namespace Tests\Unit;

use App\Http\Requests\FinanceUpdatePreinvoiceRequest;
use App\Services\ProductDiscountAllocator;
use App\Support\DiscountMath;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiscountPhaseOneTest extends TestCase
{
    public function test_rounding_remainder_never_disappears_on_a_small_last_variant(): void
    {
        $rows = collect(range(1, 100))->map(fn ($id) => (object) ['id' => $id, 'product_id' => 1, 'quantity' => 1, 'price' => 1]);
        $out = (new ProductDiscountAllocator)->allocate($rows, [['product_id' => 1, 'discount_type' => 'amount', 'discount_value' => 50]]);
        $this->assertSame(50, array_sum($out['lines']));
        $this->assertSame(50, $out['groups'][0]['discount_amount']);
        foreach ($out['lines'] as $value) {
            $this->assertTrue($value >= 0 && $value <= 1);
        }
    }

    public function test_product_percentage_keeps_two_decimal_places(): void
    {
        $rows = [(object) ['id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => 1000000]];
        $out = (new ProductDiscountAllocator)->allocate($rows, [['product_id' => 1, 'discount_type' => 'percent', 'discount_value' => '2.55']]);
        $this->assertSame(25500, $out['lines'][1]);
        $this->assertEquals(2.55, $out['groups'][0]['discount_value']);
    }

    public function test_finance_normalizes_percentage_without_erasing_the_decimal_separator(): void
    {
        $request = FinanceUpdatePreinvoiceRequest::create('/test', 'POST', ['invoice_discount_type' => 'percent', 'invoice_discount_value' => '۲٫۵', 'product_discounts' => [['product_id' => 1, 'type' => 'percent', 'value' => '2.5']]]);
        (new \ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        $this->assertEquals(2.5, $request->input('invoice_discount_value'));
        $this->assertEquals(2.5, $request->input('product_discounts.0.value'));
    }

    public function test_calculations_use_integer_rials_and_exact_percentage_at_large_amounts(): void
    {
        $this->assertSame(25000, DiscountMath::amount(1000000, 'percent', '2.5'));
        $this->assertSame(25500000000000000, DiscountMath::amount(1000000000000000000, 'percent', '2.55'));
        $this->assertSame(0, DiscountMath::amount(1, 'percent', '2.5'));
        $this->assertSame(100, DiscountMath::amount(100, 'percent', 100));
    }

    public function test_allocations_remain_exact_without_integer_overflow(): void
    {
        $weights = [999999999999999999, 1];
        $amount = 999999999999999999;
        $parts = DiscountMath::allocate($weights, $amount);
        $this->assertSame([999999999999999998, 1], $parts);
        for ($size = 1; $size <= 60; $size++) {
            $weights = [];
            for ($i = 0; $i < $size; $i++) {
                $weights[] = ($i * 17 + 3) % 31;
            }
            $amount = intdiv(array_sum($weights) * 53, 100);
            $parts = DiscountMath::allocate($weights, $amount);
            $this->assertSame($amount, array_sum($parts));
            foreach ($parts as $i => $part) {
                $this->assertTrue($part >= 0 && $part <= $weights[$i]);
            }
        }
    }

    public function test_invalid_percentage_is_rejected_instead_of_becoming_another_number(): void
    {
        $this->expectException(ValidationException::class);
        DiscountMath::amount(100000, 'percent', '2.555');
    }

    public function test_existing_integer_percentage_rounding_is_preserved(): void
    {
        $this->assertSame([999, 1001], DiscountMath::allocate([10001, 10002], 2000));
        $this->assertSame(1000, DiscountMath::amount(10001,'percent',10));
        $this->assertSame(100,DiscountMath::amount(100,'amount',200));
    }
}
