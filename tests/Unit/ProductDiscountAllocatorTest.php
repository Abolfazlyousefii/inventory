<?php

use App\Services\ProductDiscountAllocator;

it('allocates amount discounts across multiple variants with exact rounding', function () {
    $items = collect([
        (object) ['id' => 1, 'product_id' => 10, 'quantity' => 1, 'price' => 10_000_000],
        (object) ['id' => 2, 'product_id' => 10, 'quantity' => 1, 'price' => 20_000_000],
        (object) ['id' => 3, 'product_id' => 10, 'quantity' => 1, 'price' => 30_000_000],
    ]);

    $result = app(ProductDiscountAllocator::class)->allocate($items, [[
        'product_id' => 10,
        'discount_type' => 'amount',
        'discount_value' => 7_500_000,
    ]]);

    expect(array_sum($result['lines']))->toBe(7_500_000)
        ->and($result['lines'][1])->toBe(1_250_000)
        ->and($result['lines'][2])->toBe(2_500_000)
        ->and($result['lines'][3])->toBe(3_750_000)
        ->and($result['groups'][0]['discount_amount'])->toBe(7_500_000);
});

it('allocates percent discounts and puts rounding remainder on last positive line', function () {
    $items = collect([
        (object) ['id' => 1, 'product_id' => 10, 'quantity' => 1, 'price' => 10_001],
        (object) ['id' => 2, 'product_id' => 10, 'quantity' => 1, 'price' => 10_002],
    ]);

    $result = app(ProductDiscountAllocator::class)->allocate($items, [[
        'product_id' => 10,
        'discount_type' => 'percent',
        'discount_value' => 10,
    ]]);

    expect(array_sum($result['lines']))->toBe(2_000)
        ->and($result['lines'][2])->toBe(1_000);
});
