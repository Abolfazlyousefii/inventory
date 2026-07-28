<?php

use App\Services\SalesReturnNewProductPayloadNormalizer;

function normalizeSalesReturnNewProduct(array $payload): array
{
    return app(SalesReturnNewProductPayloadNormalizer::class)->normalize($payload);
}

it('supplies every schema two default without undefined array keys', function (): void {
    $payload = normalizeSalesReturnNewProduct(['schema_version' => 2]);

    expect($payload)->toHaveKeys([
        'schema_version', 'temporary_product_uuid', 'name', 'product_name', 'category_id',
        'category_path_snapshot', 'is_sellable', 'sales_enabled', 'unit', 'use_models',
        'model_brand_group', 'model_list_ids', 'selected_models', 'use_designs', 'designs',
        'purchase_price', 'sell_price', 'refund_unit_price_default', 'selected_variants',
    ])->and($payload['category_path_snapshot'])->toBeArray()->toBeEmpty()
        ->and($payload['model_brand_group'])->toBe('')
        ->and($payload['model_list_ids'])->toBe([])
        ->and($payload['selected_models'])->toBe([])
        ->and($payload['designs'])->toBe([])
        ->and($payload['selected_variants'])->toBe([]);
});

it('turns malformed collection and string values into safe types', function (): void {
    $payload = normalizeSalesReturnNewProduct([
        'schema_version' => 2,
        'name' => ['invalid'],
        'category_path_snapshot' => 'invalid',
        'model_brand_group' => ['invalid'],
        'model_list_ids' => 'invalid',
        'selected_models' => null,
        'designs' => 'invalid',
        'selected_variants' => 'invalid',
    ]);

    expect($payload['name'])->toBe('')
        ->and($payload['category_path_snapshot'])->toBe([])
        ->and($payload['model_brand_group'])->toBe('')
        ->and($payload['model_list_ids'])->toBe([])
        ->and($payload['selected_models'])->toBe([])
        ->and($payload['designs'])->toBe([])
        ->and($payload['selected_variants'])->toBe([]);
});

it('removes stale model data when models are disabled', function (): void {
    $payload = normalizeSalesReturnNewProduct([
        'schema_version' => 2,
        'use_models' => false,
        'model_brand_group' => 'Old brand',
        'model_list_ids' => [9, 9],
        'selected_models' => [['id' => 9]],
        'selected_variants' => [['model_list_id' => 9, 'design_index' => 0]],
    ]);

    expect($payload['model_brand_group'])->toBe('')
        ->and($payload['model_list_ids'])->toBe([])
        ->and($payload['selected_models'])->toBe([])
        ->and($payload['selected_variants'][0]['model_list_id'])->toBeNull();
});

it('removes stale design data when designs are disabled', function (): void {
    $payload = normalizeSalesReturnNewProduct([
        'schema_version' => 2,
        'use_designs' => false,
        'designs' => [['index' => 7, 'name' => 'Old design']],
        'selected_variants' => [['model_list_id' => null, 'design_index' => 7]],
    ]);

    expect($payload['designs'])->toBe([])
        ->and($payload['selected_variants'][0]['design_index'])->toBe(0);
});

it('trims design names and makes their indexes stable and sequential', function (): void {
    $payload = normalizeSalesReturnNewProduct([
        'schema_version' => 2,
        'use_designs' => true,
        'designs' => [
            ['index' => 8, 'name' => '  طرح اول '],
            ['index' => 8, 'name' => 'طرح دوم'],
        ],
    ]);

    expect($payload['designs'])->toBe([
        ['index' => 1, 'name' => 'طرح اول'],
        ['index' => 2, 'name' => 'طرح دوم'],
    ]);
});

it('upgrades incomplete legacy drafts without throwing', function (): void {
    $payload = normalizeSalesReturnNewProduct([
        'product_name' => 'کالای قدیمی',
        'variant_name' => 'تنوع قدیمی',
    ]);

    expect($payload['schema_version'])->toBe(2)
        ->and($payload['name'])->toBe('کالای قدیمی')
        ->and($payload['selected_variants'])->toHaveCount(1)
        ->and($payload['selected_variants'][0]['display_name'])->toBe('تنوع قدیمی');
});

it('keeps the four frontend variant contracts and reset behavior explicit', function (): void {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)->toContain("const models=useM?[...np.selectedModels].map(id=>npModelById(id)).filter(Boolean):[null]")
        ->and($view)->toContain("const designs=useD?npDesigns():[{index:0,name:''}]")
        ->and($view)->toContain('model_list_id:useModels?')
        ->and($view)->toContain('design_index:useDesigns?')
        ->and($view)->toContain('function resetNewProductBuilder()')
        ->and($view)->toContain('renderItems();resetNewProductBuilder()');
});
