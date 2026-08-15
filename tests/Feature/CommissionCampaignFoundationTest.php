<?php

use App\Models\Category;
use App\Models\CommissionCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionCampaignResolver;
use App\Services\Commissions\CommissionCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function commissionCampaignProductTree(): array
{
    $root = Category::query()->create(['name' => 'ریشه کمپین']);
    $child = Category::query()->create(['name' => 'فرزند کمپین', 'parent_id' => $root->id]);
    $product = Product::query()->create(['name' => 'Campaign Product', 'sku' => 'CAM-P', 'category_id' => $child->id, 'stock' => 1, 'reserved' => 0, 'price' => 1000]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'Campaign Variant', 'variant_code' => 'CAM-V', 'is_active' => true, 'sales_enabled' => true, 'stock' => 1, 'reserved' => 0, 'sell_price' => 1000]);

    return compact('root', 'child', 'product', 'variant');
}

it('applies one additive campaign bonus across category product and variant matches', function () {
    ['root' => $root, 'product' => $product, 'variant' => $variant] = commissionCampaignProductTree();
    $campaign = app(CommissionCampaignService::class)->save([
        'name' => 'تخلیه موجودی', 'bonus_percentage' => '۵',
        'start_at' => now()->subDay(), 'end_at' => now()->addDay(),
        'targets' => ["category:{$root->id}", "product:{$product->id}", "variant:{$variant->id}"],
    ], User::factory()->create());

    $result = app(CommissionCampaignResolver::class)->resolve($product, $variant, now());
    expect($result)->not->toBeNull()
        ->and($result->campaignId)->toBe($campaign->id)
        ->and($result->bonusPercentage)->toBe('5.0000')
        ->and(collect($result->matchedTargets))->toHaveCount(3);
});

it('cascades category targets to descendants and rejects overlapping campaigns', function () {
    ['root' => $root, 'product' => $product, 'variant' => $variant] = commissionCampaignProductTree();
    $actor = User::factory()->create();
    $service = app(CommissionCampaignService::class);
    $service->save([
        'name' => 'A', 'bonus_percentage' => '2.5',
        'start_at' => '2026-08-01 00:00:00', 'end_at' => '2026-08-15 00:00:00',
        'targets' => ["category:{$root->id}"],
    ], $actor);

    expect(app(CommissionCampaignResolver::class)->resolve($product, $variant, '2026-08-10 12:00:00'))->not->toBeNull();

    expect(fn () => $service->save([
        'name' => 'B', 'bonus_percentage' => '3',
        'start_at' => '2026-08-10 00:00:00', 'end_at' => '2026-08-20 00:00:00',
        'targets' => ["product:{$product->id}"],
    ], $actor))->toThrow(ValidationException::class);

    expect(CommissionCampaign::query()->count())->toBe(1);
});

it('keeps the previous campaign and targets when editing', function () {
    ['root' => $root, 'product' => $product] = commissionCampaignProductTree();
    $actor = User::factory()->create();
    $service = app(CommissionCampaignService::class);
    $old = $service->save([
        'name' => 'Old', 'bonus_percentage' => '2',
        'start_at' => '2026-09-01', 'end_at' => '2026-09-10',
        'targets' => ["category:{$root->id}"],
    ], $actor);
    $new = $service->save([
        'name' => 'New', 'bonus_percentage' => '3',
        'start_at' => '2026-09-01', 'end_at' => '2026-09-10',
        'targets' => ["product:{$product->id}"],
    ], $actor, $old);

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->archived_at)->not->toBeNull()
        ->and($old->targets()->value('target_key'))->toBe("category:{$root->id}")
        ->and($new->targets()->value('target_key'))->toBe("product:{$product->id}");
});
