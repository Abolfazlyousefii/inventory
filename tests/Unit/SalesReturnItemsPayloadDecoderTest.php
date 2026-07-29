<?php

use App\Services\SalesReturnItemsPayloadDecoder;
use App\Http\Requests\StoreSalesReturnRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

function compactSalesReturnPayload(int $count, int $refundPrice = 850000): array
{
    $productUuid = (string) Str::uuid();
    $variants = collect(range(1, $count))->map(fn (int $index) => [
        'temporary_variant_uuid' => (string) Str::uuid(),
        'model_list_id' => null,
        'design_index' => $index % 100,
        'display_name' => "تنوع {$index}",
        'preview_code' => str_pad((string) $index, 11, '0', STR_PAD_LEFT),
    ])->all();

    return [
        'version' => 1,
        'items' => collect($variants)->map(fn (array $variant) => [
            'item_source' => 'new_product',
            'return_quantity' => 1,
            'item_condition' => 'healthy',
            'destination_warehouse_id' => 1,
            'refund_unit_price' => $refundPrice,
            'purchase_price' => 600000,
            'sell_price' => 900000,
            'new_product_ref' => [
                'temporary_product_uuid' => $productUuid,
                'temporary_variant_uuid' => $variant['temporary_variant_uuid'],
            ],
        ])->all(),
        'new_products' => [
            $productUuid => [
                'schema_version' => 2,
                'temporary_product_uuid' => $productUuid,
                'name' => 'کالای تست',
                'product_name' => 'کالای تست',
                'category_id' => 1,
                'category_path_snapshot' => [],
                'is_sellable' => 1,
                'sales_enabled' => 1,
                'unit' => 'عدد',
                'use_models' => 0,
                'model_brand_group' => '',
                'model_list_ids' => [],
                'selected_models' => [],
                'use_designs' => 1,
                'designs' => [],
                'purchase_price' => 600000,
                'sell_price' => 900000,
                'refund_unit_price_default' => $refundPrice,
                'selected_variants' => $variants,
            ],
        ],
    ];
}

it('decodes compact payloads with one one hundred and two hundred fifty variants', function (int $count): void {
    $compact = compactSalesReturnPayload($count);
    $json = json_encode($compact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $items = app(SalesReturnItemsPayloadDecoder::class)->decode($json);

    expect($items)->toHaveCount($count)
        ->and(collect($items)->every(fn ($item) => $item['refund_unit_price'] >= 1))->toBeTrue()
        ->and(collect($items)->every(fn ($item) => $item['new_product_payload']['refund_unit_price_default'] >= 1))->toBeTrue()
        ->and(collect($items)->every(fn ($item) => count($item['new_product_payload']['selected_variants']) === 1))->toBeTrue()
        ->and(collect($items)->pluck('new_product_payload.temporary_product_uuid')->unique())->toHaveCount(1)
        ->and(collect($items)->pluck('new_product_payload.temporary_variant_uuid')->unique())->toHaveCount($count);
})->with([1, 100, 250]);

it('rejects invalid product and variant references with validation errors', function (string $brokenPart): void {
    $payload = compactSalesReturnPayload(1);
    if ($brokenPart === 'product') {
        $payload['items'][0]['new_product_ref']['temporary_product_uuid'] = (string) Str::uuid();
    } else {
        $payload['items'][0]['new_product_ref']['temporary_variant_uuid'] = (string) Str::uuid();
    }

    expect(fn () => app(SalesReturnItemsPayloadDecoder::class)->expand($payload))
        ->toThrow(ValidationException::class);
})->with(['product', 'variant']);

it('rejects malformed json invalid versions and oversized payloads', function (): void {
    $decoder = app(SalesReturnItemsPayloadDecoder::class);

    expect(fn () => $decoder->decode('{broken'))->toThrow(ValidationException::class)
        ->and(fn () => $decoder->expand(['version' => 2, 'items' => [], 'new_products' => []]))->toThrow(ValidationException::class)
        ->and(fn () => $decoder->decode(str_repeat('x', SalesReturnItemsPayloadDecoder::MAX_BYTES + 1)))->toThrow(ValidationException::class);
});

it('uses one post variable instead of recursively generated item inputs', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain('name="items_payload"')
        ->and($view)->toContain('function buildCompactItemsPayload()')
        ->and($view)->toContain("function hiddenInputs(){return ''}")
        ->and($view)->not->toContain('name="${name}"')
        ->and($view)->toContain("['npPurchase','npSell','npRefund'].forEach(npSyncPrice)");
});

it('expands compact payloads in the shared store and update request preparation path', function (): void {
    $compact = compactSalesReturnPayload(100);
    $productUuid = array_key_first($compact['new_products']);
    $compact['new_products'][$productUuid]['category_id'] = null;
    $request = StoreSalesReturnRequest::create('/vouchers/section/return-from-sale', 'POST', [
        'items_payload' => json_encode($compact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
    ]);
    (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);

    expect($request->input('items'))->toHaveCount(100)
        ->and($request->input('items.99.refund_unit_price'))->toBe(850000)
        ->and($request->input('items.99.new_product_payload.refund_unit_price_default'))->toBe(850000)
        ->and($request->input('items.99.new_product_payload.selected_variants'))->toHaveCount(1);
});
