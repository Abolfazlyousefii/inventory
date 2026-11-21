<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BundleComponentAllocationService;
use App\Services\ProductBundleService;
use App\Services\WarehouseStockService;
use App\Services\AutomaticBundleAvailabilityService;
use App\Services\PreinvoiceBundleStockService;
use App\Services\Sync\SyncProductsToSite;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BundleReleasedComponentStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasColumn('products', 'is_bundle')) {
            Schema::table('products', fn (Blueprint $t) => $t->boolean('is_bundle')->default(false));
        }
        if (!Schema::hasColumn('products', 'bundle_stock_mode')) {
            Schema::table('products', fn (Blueprint $t) => $t->string('bundle_stock_mode')->default('physical'));
        }
        if (!Schema::hasTable('product_bundle_components')) {
            Schema::create('product_bundle_components', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id');
                $t->unsignedBigInteger('component_variant_id');
                $t->unsignedInteger('quantity');
                $t->string('discount_type')->default('percent');
                $t->unsignedBigInteger('discount_value')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('bundle_component_allocations')) {
            Schema::create('bundle_component_allocations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('bundle_variant_id');
                $t->unsignedBigInteger('component_variant_id');
                $t->unsignedInteger('remaining');
                $t->timestamps();
            });
        }
        config(['database.connections.site' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('site');
        Schema::connection('site')->create('products', function (Blueprint $t) {
            $t->id(); $t->boolean('published')->default(false);
            $t->timestamp('publish_date')->nullable();
        });
        Schema::connection('site')->create('order_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('price_id');
        });
    }

    private function createBundle(bool $threeParts = false): array
    {
        $category = Category::withoutEvents(fn () => Category::create(['name' => 'Allocation release test']));
        $make = function (string $name, bool $bundle = false) use ($category) {
            $product = Product::withoutEvents(fn () => Product::create([
                'name' => $name, 'sku' => 'TEST-'.Str::random(10),
                'category_id' => $category->id, 'is_sellable' => true,
                'is_bundle' => $bundle, 'bundle_stock_mode' => $bundle ? 'automatic' : 'physical',
                'price' => 1000,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id' => $product->id, 'variant_name' => $name,
                'variant_code' => 'TEST-'.Str::random(10), 'sell_price' => 1000,
                'is_active' => true, 'sales_enabled' => true,
            ]));
            return $variant;
        };
        $a = $make('Component A');
        $b = $make('Component B');
        $c = $threeParts ? $make('Component C') : null;
        $bundle = $make('Automatic bundle', true);
        $central = WarehouseStockService::centralWarehouseId();
        WarehouseStockService::set($central, (int) $a->product_id, (int) $a->id, 100);
        WarehouseStockService::set($central, (int) $b->product_id, (int) $b->id, 100);
        if ($c) WarehouseStockService::set($central, (int) $c->product_id, (int) $c->id, 100);
        $recipe = $threeParts
            ? [['variant_id' => $a->id, 'quantity' => 2], ['variant_id' => $b->id, 'quantity' => 3], ['variant_id' => $c->id, 'quantity' => 5]]
            : [['variant_id' => $a->id, 'quantity' => 10], ['variant_id' => $b->id, 'quantity' => 5]];
        app(ProductBundleService::class)->defineRecipe((int) $bundle->id, $recipe);
        $allocation = $threeParts
            ? [(int) $a->id => 10, (int) $b->id => 15, (int) $c->id => 25]
            : [(int) $a->id => 50, (int) $b->id => 25];
        app(BundleComponentAllocationService::class)->setAllocations((int) $bundle->id, $allocation);
        return [$bundle, $a, $b, $central, $c];
    }

    /** Resuming is read-only until every precondition is met. */
    public function test_stopped_bundle_gets_actionable_stock_guidance_and_can_resume_after_allocation(): void
    {
        [$bundle, $a, $b, $central, $c] = $this->createBundle(true);
        $product = Product::findOrFail($bundle->product_id);
        $product->forceFill(['is_sellable' => false, 'bundle_site_auto_publish' => false])->saveQuietly();
        $this->mock(SyncProductsToSite::class)->shouldReceive('syncProduct')->andReturn(true);
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);

        app(BundleComponentAllocationService::class)->setAllocations((int) $bundle->id, [
            (int) $a->id => 0, (int) $b->id => 0, (int) $c->id => 0,
        ]);
        $issues = app(ProductBundleService::class)->resumeReadinessIssues($product->fresh(), $bundle->fresh());
        $this->assertContains('stock', array_column($issues, 'step'));
        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 1, 'reason' => 'Test reopening with zero stock',
        ])->assertSessionHasErrors('enabled');
        $this->assertFalse((bool) $product->fresh()->is_sellable);
        $this->assertSame(100, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));

        app(BundleComponentAllocationService::class)->setAllocations((int) $bundle->id, [
            (int) $a->id => 2, (int) $b->id => 3, (int) $c->id => 5,
        ]);
        $this->assertSame([], app(ProductBundleService::class)->resumeReadinessIssues($product->fresh(), $bundle->fresh()));
        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 1, 'reason' => 'Test reopening after safe allocation',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) $product->fresh()->is_sellable);
        $this->assertSame(1, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));
        $this->assertSame(98, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }

    public function test_stopped_bundle_requires_resaving_stale_price_before_resume(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $product = Product::findOrFail($bundle->product_id);
        $product->forceFill(['is_sellable' => false, 'bundle_site_auto_publish' => false,
            'bundle_discount_type' => 'percent', 'bundle_discount_value' => 0])->saveQuietly();
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $this->mock(SyncProductsToSite::class)->shouldReceive('syncProduct')->andReturn(true);
        $before = WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id);

        $this->assertContains('price', array_column(
            app(ProductBundleService::class)->resumeReadinessIssues($product->fresh(), $bundle->fresh()), 'step'
        ));
        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 1, 'reason' => 'Test safe resume with old price',
        ])->assertSessionHasErrors('enabled');
        $this->assertFalse((bool) $product->fresh()->is_sellable);

        $this->post(route('products.bundle.pricing', $product), [
            'bundle_discount_type' => 'percent', 'bundle_discount_value' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([], app(ProductBundleService::class)->resumeReadinessIssues($product->fresh(), $bundle->fresh()));
        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 1, 'reason' => 'Test safe resume after price correction',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) $product->fresh()->is_sellable);
        $this->assertSame($before, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }

    public function test_reducing_units_per_bundle_returns_freed_stock_only_once(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $service = app(ProductBundleService::class);
        $recipe = [
            ['variant_id' => $a->id, 'quantity' => 8],
            ['variant_id' => $b->id, 'quantity' => 5],
        ];
        $service->defineRecipe((int) $bundle->id, $recipe);
        $this->assertSame(60, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(75, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 40,
        ]);
        $service->defineRecipe((int) $bundle->id, $recipe);
        $this->assertSame(60, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }

    public function test_release_command_previews_then_returns_only_explicit_excess(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2,
        ])->assertExitCode(0);
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 50,
        ]);
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2, '--apply' => true,
        ])->assertExitCode(0);
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(90, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 20,
        ]);
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $b->id, 'remaining' => 10,
        ]);
        $this->artisan('bundle:release-unused', [
            'bundle_variant_id' => $bundle->id, '--keep' => 2, '--apply' => true,
        ])->assertExitCode(0);
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }
    public function test_release_preview_is_exact_and_a_stale_or_duplicate_apply_cannot_change_stock(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        $service = app(\App\Services\UnusedBundleAllocationReleaseService::class);
        $preview = $service->preview((int) $bundle->id, 2);
        $this->assertSame(45, $preview['released_total']);
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $applied = $service->apply((int) $bundle->id, 2, $preview['token'], null, 'Test approved release of unused components');
        $this->assertSame(45, $applied['released_total']);
        $this->assertDatabaseHas('bundle_management_events', ['event_id' => $applied['event_id'], 'action' => 'release']);
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        try {
            $service->apply((int) $bundle->id, 2, $preview['token'], null, 'Repeated release form submission');
            $this->fail('A consumed preview token must not be accepted twice.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('keep', $exception->errors());
        }
        $this->assertSame(80, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(1, DB::table('bundle_management_events')->where('action', 'release')->count());
    }


    /** An actual POST to the manager returns/reallocates each SKU, not a virtual stock counter. */
    public function test_quantity_five_to_four_and_back_moves_exact_parts_and_returned_parts_are_sellable(): void
    {
        [$bundle, $a, $b, $central, $c] = $this->createBundle(true);
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $this->mock(SyncProductsToSite::class)->shouldReceive('syncProduct')->andReturn(true);
        $product = Product::findOrFail($bundle->product_id);
        $expected = [$a->id => 10, $b->id => 15, $c->id => 25];
        $post = fn (int $count, array $snapshot) => $this->post(route('products.bundle.policy', $product), [
            'bundle_quantity_mode' => 'shared', 'bundle_shared_quantity' => $count,
            'expected_allocations' => $snapshot, 'reason' => 'Adjust unused bundle quantities in safe test',
        ]);

        $this->assertSame(5, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));
        $post(4, $expected)->assertSessionHasNoErrors()->assertRedirect();
        foreach ([[$a, 92, 8], [$b, 88, 12], [$c, 80, 20]] as [$part, $free, $protected]) {
            $this->assertSame($free, WarehouseStockService::available($central, (int) $part->product_id, (int) $part->id));
            $this->assertDatabaseHas('bundle_component_allocations', [
                'bundle_variant_id' => $bundle->id, 'component_variant_id' => $part->id, 'remaining' => $protected,
            ]);
        }
        $this->assertSame(4, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));
        $this->assertSame(92, app(PreinvoiceBundleStockService::class)->free((int) $a->product_id, (int) $a->id));
        app(PreinvoiceBundleStockService::class)->reserve((int) $a->product_id, (int) $a->id, 2);
        $this->assertSame(90, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(4, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));

        // A stale second POST is rejected before any additional stock movement.
        $post(3, $expected)->assertSessionHasErrors('allocations');
        $this->assertSame(88, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertSame(1, DB::table('bundle_management_events')->where('action', 'allocation_adjust')->count());

        $post(5, [$a->id => 8, $b->id => 12, $c->id => 20])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(88, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(85, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertSame(75, WarehouseStockService::available($central, (int) $c->product_id, (int) $c->id));
        $this->assertSame(5, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));
        $this->assertSame(2, DB::table('bundle_management_events')->where('action', 'allocation_adjust')->count());
        $audit = json_decode(DB::table('bundle_management_events')->where('action', 'allocation_adjust')->orderBy('id')->value('details'), true);
        $this->assertSame([2, 3, 5], array_column($audit['rows'], 'ordinary_stock_delta'));
    }


    public function test_increase_with_a_short_component_rolls_back_every_other_allocation(): void
    {
        [$bundle, $a, $b, $central, $c] = $this->createBundle(true);
        WarehouseStockService::set($central, (int) $b->product_id, (int) $b->id, 0);
        try {
            app(BundleComponentAllocationService::class)->setAllocations((int) $bundle->id, [
                (int) $a->id => 12, (int) $b->id => 18, (int) $c->id => 30,
            ]);
            $this->fail('A bundle increase must fail atomically when even one component is unavailable.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('Component B', $e->errors()['allocations'][0]);
            $this->assertStringContainsString('3', $e->errors()['allocations'][0]);
        }
        foreach ([[$a, 90, 10], [$b, 0, 15], [$c, 75, 25]] as [$part, $free, $protected]) {
            $this->assertSame($free, WarehouseStockService::available($central, (int) $part->product_id, (int) $part->id));
            $this->assertDatabaseHas('bundle_component_allocations', [
                'bundle_variant_id' => $bundle->id, 'component_variant_id' => $part->id, 'remaining' => $protected,
            ]);
        }
        $this->assertSame(5, app(AutomaticBundleAvailabilityService::class)->available((int) $bundle->id));
    }

    public function test_legacy_site_hold_lines_block_quantity_and_recipe_changes_without_stock_movement(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        if (!Schema::hasTable('site_stock_holds')) {
            Schema::create('site_stock_holds', function (Blueprint $t): void {
                $t->id(); $t->string('status');
            });
        }
        if (!Schema::hasTable('site_stock_hold_lines')) {
            Schema::create('site_stock_hold_lines', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('hold_id'); $t->unsignedBigInteger('variant_id');
            });
        }
        $holdId = DB::table('site_stock_holds')->insertGetId(['status' => 'held']);
        DB::table('site_stock_hold_lines')->insert(['hold_id' => $holdId, 'variant_id' => $bundle->id]);
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $product = Product::findOrFail($bundle->product_id);
        $this->post(route('products.bundle.policy', $product), [
            'bundle_quantity_mode' => 'shared', 'bundle_shared_quantity' => 4,
            'expected_allocations' => [$a->id => 50, $b->id => 25],
        ])->assertSessionHasErrors('allocations');
        try {
            app(ProductBundleService::class)->defineRecipe((int) $bundle->id, [
                ['variant_id' => $a->id, 'quantity' => 9], ['variant_id' => $b->id, 'quantity' => 5],
            ]);
            $this->fail('Existing held orders must retain their original recipe.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('components', $e->errors());
        }
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(75, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertSame(10, DB::table('product_bundle_components')->where('bundle_variant_id', $bundle->id)->where('component_variant_id', $a->id)->value('quantity'));
        $this->assertSame(0, DB::table('bundle_management_events')->where('action', 'allocation_adjust')->count());
    }

    public function test_active_site_order_hold_blocks_release_without_any_stock_movement(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        if (!Schema::hasTable('site_stock_holds')) {
            Schema::create('site_stock_holds', function (Blueprint $table): void {
                $table->id(); $table->string('status');
            });
        }
        if (!Schema::hasTable('site_stock_hold_lines')) {
            Schema::create('site_stock_hold_lines', function (Blueprint $table): void {
                $table->id(); $table->unsignedBigInteger('hold_id');
                $table->unsignedBigInteger('variant_id');
            });
        }
        $holdId = DB::table('site_stock_holds')->insertGetId(['status' => 'held']);
        DB::table('site_stock_hold_lines')->insert(['hold_id' => $holdId, 'variant_id' => $bundle->id]);
        $service = app(\App\Services\UnusedBundleAllocationReleaseService::class);
        $preview = $service->preview((int) $bundle->id, 0);
        try {
            $service->apply((int) $bundle->id, 0, $preview['token'], null, 'Active site order must not release stock');
            $this->fail('An active Site hold must block the stock return.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Site bundle holds', $exception->getMessage());
        }
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 50,
        ]);
        $this->assertSame(0, DB::table('bundle_management_events')->where('action', 'release')->count());
    }

    public function test_physical_bundle_stock_blocks_release_without_changing_allocations(): void
    {
        [$bundle, $a, $b, $central] = $this->createBundle();
        WarehouseStockService::set($central, (int) $bundle->product_id, (int) $bundle->id, 1);
        $service = app(\App\Services\UnusedBundleAllocationReleaseService::class);
        $preview = $service->preview((int) $bundle->id, 0);
        try {
            $service->apply((int) $bundle->id, 0, $preview['token'], null, 'Unsafe physical stock should block');
            $this->fail('A physical bundle must block release.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('physical', $exception->getMessage());
        }
        $this->assertSame(50, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertDatabaseHas('bundle_component_allocations', [
            'bundle_variant_id' => $bundle->id, 'component_variant_id' => $a->id, 'remaining' => 50,
        ]);
    }

    /** Automatic bundles have protected virtual capacity, not finished warehouse stock. */
    public function test_stock_ledger_shows_automatic_capacity_and_resume_audit_without_creating_physical_stock(): void
    {
        [$bundle, $a, $b, $central, $c] = $this->createBundle(true);
        $product = Product::findOrFail($bundle->product_id);
        $this->assertSame(0, (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity'));
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $this->mock(SyncProductsToSite::class)->shouldReceive('syncProduct')->andReturn(true);

        $read = fn () => app(\App\Http\Controllers\ProductStockLedgerController::class)
            ->index(\Illuminate\Http\Request::create('/products/'.$product->id.'/stock-ledger'), $product->fresh())
            ->getData()['bundleLedger'];
        $initial = $read();
        $this->assertSame(5, $initial['protected_packs']);
        $this->assertSame(5, $initial['available_for_new_orders']);
        $this->assertSame(50, $initial['protected_units']);

        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 0, 'reason' => 'Testing status-only pause without inventory movement',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $product->fresh()->is_sellable);
        $paused = $read();
        $this->assertSame(5, $paused['protected_packs']);
        $this->assertSame(0, $paused['available_for_new_orders']);
        $this->assertSame('pause', $paused['events']->items()[0]->action);
        $this->assertSame(0, $paused['events']->items()[0]->returned_units);

        $this->post(route('products.bundle.sale', $product), [
            'enabled' => 1, 'reason' => 'Testing status-only resume without inventory movement',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue((bool) $product->fresh()->is_sellable);
        $resumed = $read();
        $this->assertSame(5, $resumed['protected_packs']);
        $this->assertSame(5, $resumed['available_for_new_orders']);
        $this->assertSame('resume', $resumed['events']->items()[0]->action);
        $this->assertSame(0, $resumed['events']->items()[0]->returned_units);
        $this->assertSame(0, (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity'));
        $this->assertSame(90, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
    }

    public function test_stock_ledger_explains_exact_release_lines_without_double_counting_bundle_warehouse_stock(): void
    {
        [$bundle, $a, $b, $central, $c] = $this->createBundle(true);
        $product = Product::findOrFail($bundle->product_id);
        $service = app(\App\Services\UnusedBundleAllocationReleaseService::class);
        $preview = $service->preview((int) $bundle->id, 4);
        $service->apply((int) $bundle->id, 4, $preview['token'], null, 'Release exactly one unused automatic bundle');
        $view = app(\App\Http\Controllers\ProductStockLedgerController::class)->index(
            \Illuminate\Http\Request::create('/products/'.$product->id.'/stock-ledger'), $product
        );
        $ledger = $view->getData()['bundleLedger'];
        $this->assertSame(4, $ledger['protected_packs']);
        $this->assertSame(4, $ledger['available_for_new_orders']);
        $this->assertSame(40, $ledger['protected_units']);
        $event = $ledger['events']->items()[0];
        $this->assertSame('release', $event->action);
        $this->assertSame(10, $event->returned_units);
        $this->assertSame(0, $event->allocated_units);
        $this->assertSame([2, 3, 5], array_column($event->changes, 'ordinary_delta'));
        $this->assertSame(0, (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('quantity'));
        $this->assertSame(92, WarehouseStockService::available($central, (int) $a->product_id, (int) $a->id));
        $this->assertSame(88, WarehouseStockService::available($central, (int) $b->product_id, (int) $b->id));
        $this->assertSame(80, WarehouseStockService::available($central, (int) $c->product_id, (int) $c->id));
    }

}
