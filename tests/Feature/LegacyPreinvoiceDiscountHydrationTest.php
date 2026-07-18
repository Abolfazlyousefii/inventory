<?php

use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Services\PreinvoiceDiscountHydrator;
use Illuminate\Support\Collection;

function legacyHydrationOrder(array $attributes, array $items): PreinvoiceOrder
{
    $order = new PreinvoiceOrder($attributes);
    $order->setRelation('items', new Collection($items));

    return $order;
}

function legacyHydrationItem(int $productId, int $quantity, int $price, int $discount): PreinvoiceOrderItem
{
    return new PreinvoiceOrderItem([
        'product_id' => $productId,
        'variant_id' => $productId,
        'quantity' => $quantity,
        'price' => $price,
        'line_discount_amount' => $discount,
    ]);
}

it('hydrates unstructured legacy discount as invoice discount when line discounts are zero', function () {
    $order = legacyHydrationOrder([
        'discount_amount' => 1_000_000,
        'discount_breakdown' => null,
    ], [
        legacyHydrationItem(10, 1, 5_000_000, 0),
    ]);

    $state = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order);

    expect($state['invoice_discount']['type'])->toBe('amount')
        ->and($state['invoice_discount']['value'])->toBe(1_000_000)
        ->and($state['invoice_discount']['amount'])->toBe(1_000_000);
});

it('splits legacy total discount between item discounts and invoice discount', function () {
    $order = legacyHydrationOrder([
        'discount_amount' => 1_500_000,
        'discount_breakdown' => null,
    ], [
        legacyHydrationItem(10, 1, 5_000_000, 200_000),
        legacyHydrationItem(20, 1, 5_000_000, 300_000),
    ]);

    $state = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order);

    expect($state['items_discount'])->toBe(500_000)
        ->and($state['legacy_invoice_discount'])->toBe(1_000_000)
        ->and($state['invoice_discount']['value'])->toBe(1_000_000);
});

it('preserves structured product discount type and value exactly', function () {
    $order = legacyHydrationOrder([
        'discount_amount' => 2_200_000,
        'discount_breakdown' => [
            'groups' => [[
                'product_id' => 10,
                'discount_type' => 'percent',
                'discount_value' => 10,
                'discount_amount' => 2_200_000,
                'raw_subtotal' => 22_000_000,
                'final_amount' => 19_800_000,
            ]],
        ],
    ], [
        legacyHydrationItem(10, 1, 10_000_000, 1_000_000),
        legacyHydrationItem(10, 1, 12_000_000, 1_200_000),
    ]);

    $state = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order);

    expect($state['product_groups'][10]['discount_type'])->toBe('percent')
        ->and($state['product_groups'][10]['discount_value'])->toBe(10)
        ->and($state['product_groups'][10]['discount_amount'])->toBe(2_200_000);
});
