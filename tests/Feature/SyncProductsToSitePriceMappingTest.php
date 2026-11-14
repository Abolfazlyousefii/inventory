<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use App\Models\Site\Price as SitePrice;
use App\Services\Sync\SyncProductsToSite;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization tests for Inventory -> Site price mapping only.
 * No real Site connection or external API is used; these are not end-to-end tests.
 */
class SyncProductsToSitePriceMappingTest extends TestCase
{
    private function mapPrice(array $attributes): array
    {
        $variant = new ProductVariant();
        $variant->forceFill(array_merge([
            'id' => 701,
            'product_id' => 402,
            'variant_name' => 'Yuan test variant',
            'variant_code' => 'LOCAL-CNY-701',
            'is_active' => true,
            'sales_enabled' => true,
            'sell_price' => 2_910_000,
        ], $attributes));

        $method = new ReflectionMethod(SyncProductsToSite::class, 'makePriceData');
        return $method->invoke(new SyncProductsToSite(), $variant, 4, null);
    }

    public function test_repriced_variant_without_discount_maps_to_new_customer_price(): void
    {
        $result = $this->mapPrice(['sell_price' => 2_990_000]);

        $this->assertSame(2_990_000, $result['price']);
        $this->assertSame(2_990_000, $result['regular_price']);
        $this->assertSame(2_990_000, $result['discount_price']);
        $this->assertSame(0, $result['discount']);
        $this->assertSame(4, $result['stock']);
    }

    public function test_existing_discount_price_currently_overrides_new_yuan_sale_price(): void
    {
        // Describes CURRENT sync behavior. It does not prescribe the correct business policy.
        $result = $this->mapPrice([
            'sell_price' => 2_990_000, // changed by the yuan repricing engine
            'regular_price' => 3_100_000,
            'discount_price' => 2_600_000,
        ]);

        $this->assertSame(2_600_000, $result['price']);
        $this->assertSame(3_100_000, $result['regular_price']);
        $this->assertSame(2_600_000, $result['discount_price']);
        $this->assertSame(16, $result['discount']);
    }

    public function test_changed_mapped_price_is_detected_even_when_site_timestamp_matches(): void
    {
        $desired = $this->mapPrice(['sell_price' => 2_990_000]);
        $current = new SitePrice();
        $current->forceFill($desired);
        $current->forceFill(['price' => 2_910_000, 'discount_price' => 2_910_000]);

        $method = new ReflectionMethod(SyncProductsToSite::class, 'priceHasDifferences');
        $this->assertTrue($method->invoke(new SyncProductsToSite(), $current, $desired));
    }

    public function test_unchanged_mapped_price_is_not_marked_for_rewrite(): void
    {
        $desired = $this->mapPrice(['sell_price' => 2_990_000]);
        $current = new SitePrice();
        $current->forceFill($desired);

        $method = new ReflectionMethod(SyncProductsToSite::class, 'priceHasDifferences');
        $this->assertFalse($method->invoke(new SyncProductsToSite(), $current, $desired));
    }
}
