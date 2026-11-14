<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CnyExchangeRate;
use App\Models\Product;
use App\Models\ProductCnyPricing;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CnyPriceCalculator;
use App\Services\CnyPricingService;
use App\Services\Sync\SyncProductsToSite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two-database backend integration coverage: yuan repricing -> Site rows.
 * Both connections are separate in-memory SQLite databases. NEVER use live Site.
 * This does not test the public storefront UI or production MySQL compatibility.
 */
class CnyToSiteDatabaseSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'cny_pricing.enabled' => true,
            'cny_pricing.allow_sample' => true,
            'cny_pricing.production_mode' => false,
            'navasan.enabled' => false,
            // This suite uses sample CNY fixtures, not live Nobitex quotes.
            'nobitex.enabled' => false,
            'services.request_driven_site_stock' => false,
            'inventory.site_sellable_warehouse_ids' => [],
        ]);

        // Redirect the hard-coded 'site' Eloquent models to a dedicated SQLite
        // memory connection BEFORE issuing any Site query. No DB_SITE or MySQL use.
        config()->set('database.connections.site', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('site');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection('site')->getDriverName());
        $this->assertSame(':memory:', DB::connection('site')->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->createIsolatedSiteSchema();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        DB::disconnect('site');
        DB::purge('site');
        parent::tearDown();
    }

    private function createIsolatedSiteSchema(): void
    {
        Schema::connection('site')->create('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('title');
            $table->string('product_code')->nullable();
            $table->string('image')->nullable();
            $table->string('type');
            $table->string('price_type');
            $table->string('unit');
            $table->string('special');
            $table->string('rounding_type');
            $table->string('rounding_amount');
            $table->string('slug');
            $table->string('lang');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        Schema::connection('site')->create('prices', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('product_id');
            $table->string('external_variant_id')->nullable();
            $table->string('variant_code')->nullable();
            $table->string('variant_name')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('regular_price');
            $table->integer('discount')->default(0);
            $table->unsignedBigInteger('discount_price');
            $table->integer('stock')->default(0);
            $table->timestamp('discount_expire_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('site')->create('attribute_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('lang');
            $table->string('type');
            $table->integer('ordering');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('site')->create('attributes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_group_id');
            $table->string('name');
            $table->string('value')->nullable();
            $table->timestamps();
        });
        Schema::connection('site')->create('attribute_price', function (Blueprint $table): void {
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('price_id');
            $table->timestamps();
        });
    }

    /** @return array{0: Product, 1: ProductVariant} */
    private function createActiveYuanProduct(): array
    {
        $category = Category::create(['name' => 'CNY Site E2E', 'code' => '93']);
        $product = Product::withoutEvents(fn () => Product::create([
            'name' => 'CNY end-to-end test product',
            'sku' => 'CNY-E2E-LOCAL',
            'code' => 'CNY-E2E-LOCAL',
            'category_id' => $category->id,
            'price' => 2_910_000,
            'uniform_sale_price' => 2_910_000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
            'product_id' => $product->id,
            'variant_name' => 'Test model',
            'variant_code' => 'CNY-E2E-VARIANT',
            'variety_code' => '0001',
            'sell_price' => 2_910_000,
            'buy_price' => 2_000_000,
            'is_active' => true,
            'sales_enabled' => true,
        ]));
        $initialRate = CnyExchangeRate::create([
            'provider' => 'sample',
            'rate_rial' => 400_000,
            'quoted_at' => now()->subMinutes(2),
        ]);
        $user = User::factory()->create();
        ProductCnyPricing::create([
            'product_id' => $product->id,
            'buy_cny_units' => 50_000,
            'enabled' => true,
            'applied_rate_id' => $initialRate->id,
            'approved_by' => $user->id,
            'policy' => CnyPriceCalculator::POLICY,
        ]);

        return [$product, $variant];
    }

    private function repriceTo410000(): int
    {
        CnyExchangeRate::create([
            'provider' => 'sample',
            'rate_rial' => 410_000,
            'quoted_at' => now(),
        ]);

        return app(CnyPricingService::class)->reprice();
    }

    public function test_yuan_reprice_reconciles_persisted_site_product_and_price_then_becomes_idempotent(): void
    {
        [$product, $variant] = $this->createActiveYuanProduct();
        $sync = app(SyncProductsToSite::class);

        $first = $sync->syncProduct($product->id);
        $this->assertSame(1, $first['products_created']);
        $this->assertSame(1, $first['variants_created']);
        $this->assertSame(2_910_000, (int) DB::connection('site')->table('prices')->where('id', $variant->id)->value('price'));
        $this->assertSame(1, DB::connection('site')->table('attribute_price')->count());

        $this->assertSame(1, $this->repriceTo410000());
        $this->assertSame(2_990_000, (int) $variant->fresh()->sell_price);
        $this->assertSame(2_990_000, (int) $product->fresh()->price);

        $updated = $sync->syncProduct($product->id);
        $this->assertSame(1, $updated['variants_updated']);
        $sitePrice = DB::connection('site')->table('prices')->where('id', $variant->id)->first();
        $this->assertSame(2_990_000, (int) $sitePrice->price);
        $this->assertSame(2_990_000, (int) $sitePrice->regular_price);
        $this->assertSame(2_990_000, (int) $sitePrice->discount_price);
        $this->assertSame(0, (int) $sitePrice->discount);
        $this->assertSame(1, DB::connection('site')->table('prices')->count());

        $again = $sync->syncProduct($product->id);
        $this->assertSame(1, $again['products_skipped']);
        $this->assertSame(1, $again['variants_skipped']);
        $this->assertSame(0, $again['variants_updated']);
        Http::assertNothingSent();
    }

    public function test_yuan_reprice_keeps_an_existing_active_site_discount_amount_unchanged(): void
    {
        [$product, $variant] = $this->createActiveYuanProduct();
        $sync = app(SyncProductsToSite::class);
        $sync->syncProduct($product->id);

        // Existing shop-side promotion: the customer currently pays 2,600,000 IRR.
        DB::connection('site')->table('prices')->where('id', $variant->id)->update([
            'price' => 2_600_000,
            'regular_price' => 2_910_000,
            'discount_price' => 2_600_000,
            'discount' => 11,
            'discount_expire_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->assertSame(1, $this->repriceTo410000());
        $sync->syncProduct($product->id);
        $sitePrice = DB::connection('site')->table('prices')->where('id', $variant->id)->first();

        // Business policy: new regular CNY-based price, unchanged discount amount.
        // This is deliberately a regression test for real persisted Site discounts.
        $this->assertSame(2_990_000, (int) $sitePrice->regular_price);
        $this->assertSame(2_600_000, (int) $sitePrice->price);
        $this->assertSame(2_600_000, (int) $sitePrice->discount_price);
        $this->assertSame(13, (int) $sitePrice->discount); // recalculated %; the amount stays fixed
        $this->assertNotNull($sitePrice->discount_expire_at);
        $this->assertSame(2_990_000, (int) $variant->fresh()->sell_price);

        // The second reconciliation must not replace the retained Site discount.
        $again = $sync->syncProduct($product->id);
        $this->assertSame(1, $again['variants_skipped']);
        $this->assertSame(0, $again['variants_updated']);
        $this->assertSame(2_600_000, (int) DB::connection('site')->table('prices')
            ->where('id', $variant->id)->value('price'));
        Http::assertNothingSent();
    }
    public function test_expired_site_promotion_is_not_preserved_after_yuan_repricing(): void
    {
        [$product, $variant] = $this->createActiveYuanProduct();
        $sync = app(SyncProductsToSite::class);
        $sync->syncProduct($product->id);

        DB::connection('site')->table('prices')->where('id', $variant->id)->update([
            'price' => 2_600_000,
            'regular_price' => 2_910_000,
            'discount_price' => 2_600_000,
            'discount' => 11,
            'discount_expire_at' => now()->subDay()->toDateTimeString(),
        ]);

        $this->assertSame(1, $this->repriceTo410000());
        $sync->syncProduct($product->id);
        $sitePrice = DB::connection('site')->table('prices')->where('id', $variant->id)->first();
        $this->assertSame(2_990_000, (int) $sitePrice->regular_price);
        $this->assertSame(2_990_000, (int) $sitePrice->price);
        $this->assertSame(0, (int) $sitePrice->discount);
        Http::assertNothingSent();
    }

}
