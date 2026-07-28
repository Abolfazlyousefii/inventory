<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class PurchaseValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
    }

    #[DataProvider('moneyValues')]
    public function test_money_values_are_normalized_to_the_same_integer_rial(string $value): void
    {
        $this->assertSame(
            1_000_000_000,
            $this->invoke('normalizeMoneyValue', [$value]),
        );
    }

    public static function moneyValues(): array
    {
        return [
            'english plain' => ['1000000000'],
            'english grouped' => ['1,000,000,000'],
            'persian plain' => ['۱۰۰۰۰۰۰۰۰۰'],
            'persian grouped' => ['۱٬۰۰۰٬۰۰۰٬۰۰۰'],
            'arabic grouped' => ['١٬٠٠٠٬٠٠٠٬٠٠٠'],
        ];
    }

    public function test_json_payload_preserves_all_rows_without_using_php_input_variable_slots(): void
    {
        $items = [];

        for ($index = 1; $index <= 300; $index++) {
            $items[] = [
                'client_key' => "product-1-variant-{$index}",
                'product_id' => 1,
                'variant_id' => $index,
                'quantity' => 2,
                'buy_price' => 1_000_000_000,
                'sell_price' => 1_200_000_000,
            ];
        }

        $request = Request::create('/purchases', 'POST', [
            'supplier_id' => '42',
            'items_json' => json_encode($items, JSON_THROW_ON_ERROR),
        ]);

        $this->invoke('mergeJsonPurchaseItems', [$request]);

        $this->assertSame('42', $request->input('supplier_id'));
        $this->assertCount(300, $request->input('items'));
        $this->assertSame($items[299], $request->input('items.299'));
    }

    public function test_invalid_json_payload_is_rejected_without_partial_items(): void
    {
        $request = Request::create('/purchases', 'POST', [
            'items_json' => '[{"product_id":1}',
        ]);

        try {
            $this->invoke('mergeJsonPurchaseItems', [$request]);
            $this->fail('Invalid JSON was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
            $this->assertNull($request->input('items'));
        }
    }

    public function test_two_billion_rial_line_total_stays_an_integer(): void
    {
        $quantity = 2;
        $unitPrice = $this->invoke('normalizeMoneyValue', ['۱٬۰۰۰٬۰۰۰٬۰۰۰']);
        $lineSubtotal = $quantity * $unitPrice;
        $discount = $this->invoke('calculateDiscount', [$lineSubtotal, null, 0]);

        $this->assertSame(2_000_000_000, $lineSubtotal);
        $this->assertSame(2_000_000_000, $lineSubtotal - $discount);
        $this->assertIsInt($lineSubtotal);
    }

    public function test_same_submission_token_cannot_be_acquired_twice(): void
    {
        $token = '91f47515-7198-4a11-a9fd-f2130f00d071';

        $this->assertSame(
            'purchase-submission:'.$token,
            $this->invoke('acquirePurchaseSubmission', [$token]),
        );

        $this->expectException(ValidationException::class);
        $this->invoke('acquirePurchaseSubmission', [$token]);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $controller = new PurchaseController;
        $reflection = new ReflectionMethod($controller, $method);

        return $reflection->invokeArgs($controller, $arguments);
    }
}
