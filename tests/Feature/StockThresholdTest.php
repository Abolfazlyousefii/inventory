<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockThresholdRule, User, Warehouse, WarehouseStock};
use App\Services\StockThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

class StockThresholdTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $quantity = 5): array
    {
        Http::fake();
        $root = Category::create(['name' => 'آداپتور']);
        $child = Category::create(['name' => 'سرشارژر', 'parent_id' => $root->id]);
        $product = Product::withoutEvents(fn () => Product::create(['name' => 'شارژر 67w', 'price' => 100, 'code' => 'THRESHOLD', 'sku' => 'THRESHOLD', 'category_id' => $child->id, 'stock' => 99, 'is_sellable' => false]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create(['product_id' => $product->id, 'variant_name' => 'مشکی', 'variant_code' => 'THRESHOLD-BLACK', 'is_active' => false, 'sales_enabled' => false, 'reserved' => 7, 'stock' => 99]));
        $central = Warehouse::create(['name' => 'Central', 'type' => 'central']);
        $return = Warehouse::create(['name' => 'Return', 'type' => 'return']);
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $return->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 100]));
        return [$root, $child, $product, $variant, $central];
    }

    private function rule(string $type, int $id, string $measure, int $minimum): StockThresholdRule
    {
        return StockThresholdRule::create(['target_type' => $type, 'target_id' => $id, 'measure' => $measure, 'minimum' => $minimum]);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('StockThresholdManager', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::where('key', 'page.warehouse.thresholds')->firstOrFail());
        $user->assignRole($role);
        return $user;
    }

    public function test_no_rules_mean_no_alerts_and_no_inventory_writes(): void
    {
        $warehouses = DB::table('warehouses')->count();
        $movements = DB::table('stock_movements')->count();
        $this->assertCount(0, app(StockThresholdService::class)->alerts(false));
        $this->assertDatabaseCount('warehouses', $warehouses);
        $this->assertDatabaseCount('stock_movements', $movements);
    }

    public function test_central_free_quantity_is_not_reduced_twice_and_inactive_items_are_included(): void
    {
        [$root, , $product, $variant] = $this->fixture();
        $this->rule('category', $root->id, 'variant', 5);
        $before = DB::table('warehouse_stocks')->get()->toArray();
        $alerts = app(StockThresholdService::class)->alerts(false);
        $this->assertCount(1, $alerts);
        $this->assertSame(5, $alerts[0]['available']);
        $this->assertSame(0, $alerts[0]['shortfall']);
        $this->assertSame($variant->id, $alerts[0]['variant_id']);
        $this->assertFalse($alerts[0]['product_sale_enabled']);
        $this->assertFalse($alerts[0]['variant_active']);
        $this->assertFalse($alerts[0]['variant_sales_enabled']);
        $this->assertEquals($before, DB::table('warehouse_stocks')->get()->toArray());
        $this->assertSame(99, (int) $product->fresh()->stock);
        $this->assertSame(7, (int) $variant->fresh()->reserved);
    }

    public function test_specific_category_product_and_variant_rules_override_ancestors(): void
    {
        [$root, $child, $product, $variant] = $this->fixture(4);
        $this->rule('category', $root->id, 'variant', 10);
        $this->rule('category', $child->id, 'variant', 3);
        $service = app(StockThresholdService::class);
        $this->assertCount(0, $service->alerts(false));
        $this->rule('product', $product->id, 'variant', 6);
        $this->assertSame(6, $service->alerts(false)[0]['minimum']);
        $specific = $this->rule('variant', $variant->id, 'variant', 2);
        $this->assertCount(0, $service->alerts(false));
        $specific->delete();
        $this->assertSame(6, $service->alerts(false)[0]['minimum']);
    }

    public function test_product_total_and_variant_alerts_are_separate_and_zero_threshold_is_valid(): void
    {
        [, , $product, $variant] = $this->fixture(0);
        $this->rule('product', $product->id, 'product', 0);
        $this->rule('variant', $variant->id, 'variant', 1);
        $alerts = app(StockThresholdService::class)->alerts(false);
        $this->assertCount(2, $alerts);
        $this->assertCount(1, $alerts->pluck('product_id')->unique());
        $this->assertSame(0, $alerts->firstWhere('variant_id', null)['available']);
    }

    public function test_product_total_does_not_count_legacy_aggregate_twice_and_unconfigured_items_are_excluded(): void
    {
        [, , $product, , $central] = $this->fixture(5);
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central->id, 'product_id' => $product->id, 'quantity' => 99]));
        $simple = Product::withoutEvents(fn () => Product::create(['name' => 'Simple', 'price' => 100, 'code' => 'SIMPLE', 'sku' => 'SIMPLE', 'category_id' => $product->category_id, 'stock' => 99]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::create(['warehouse_id' => $central->id, 'product_id' => $simple->id, 'quantity' => 2]));
        $this->rule('product', $product->id, 'product', 5);
        $service = app(StockThresholdService::class);
        $this->assertCount(1, $service->alerts(false));
        $this->assertSame(5, $service->alerts(false)->first()['available']);
        $this->rule('product', $simple->id, 'product', 2);
        $this->assertCount(2, $service->alerts(false));
        $this->assertSame(2, $service->alerts(false)->firstWhere('product_id', $simple->id)['available']);
    }

    public function test_supply_page_filters_out_low_and_edge_without_hiding_inactive_variants(): void
    {
        [$root, , , $variant, $central] = $this->fixture(0);
        $this->rule('category', $root->id, 'variant', 5);
        $this->actingAs($this->actor());

        $this->get(route('stock-thresholds.index', ['status' => 'out']))
            ->assertOk()->assertSee('THRESHOLD-BLACK')->assertSee('تنوع غیرفعال')->assertSee('فروش بسته');
        $this->get(route('stock-thresholds.index', ['status' => 'low']))
            ->assertOk()->assertDontSee('THRESHOLD-BLACK');

        $stock = DB::table('warehouse_stocks')->where('warehouse_id', $central->id)->where('product_variant_id', $variant->id);
        $stock->update(['quantity' => 4]);
        $this->get(route('stock-thresholds.index', ['status' => 'low']))
            ->assertOk()->assertSee('THRESHOLD-BLACK')->assertSee('نیازمند تأمین');
        $this->get(route('stock-thresholds.index', ['status' => 'out']))
            ->assertOk()->assertDontSee('THRESHOLD-BLACK');

        $stock->update(['quantity' => 5]);
        $this->get(route('stock-thresholds.index', ['status' => 'edge']))
            ->assertOk()->assertSee('THRESHOLD-BLACK')->assertSee('مرز آستانه');
    }

    public function test_page_permission_is_required_for_all_actions(): void
    {
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $this->actingAs($user)->getJson(route('stock-thresholds.summary'))->assertForbidden();
        $this->postJson(route('stock-thresholds.daily'))->assertForbidden();
        $this->postJson(route('stock-thresholds.store'), ['target_type' => 'category', 'target_id' => 1, 'measure' => 'product', 'minimum' => 1])->assertForbidden();
        $this->assertDatabaseCount('stock_threshold_rules', 0);
        $this->assertDatabaseCount('stock_threshold_alert_days', 0);
    }

    public function test_authorized_user_can_render_search_update_and_delete_rules(): void
    {
        [, , $product, $variant] = $this->fixture();
        $this->actingAs($this->actor())->get(route('stock-thresholds.index'))->assertOk()->assertSee('آستانه موجودی');
        $this->getJson(route('stock-thresholds.search', ['type' => 'variant', 'q' => '67w']))->assertOk()->assertJsonPath('items.0.id', $variant->id);
        $payload = ['target_type' => 'product', 'target_id' => $product->id, 'measure' => 'variant', 'minimum' => 8];
        $this->post(route('stock-thresholds.store'), $payload)->assertRedirect();
        $this->post(route('stock-thresholds.store'), array_merge($payload, ['minimum' => 6]))->assertRedirect();
        $this->assertDatabaseCount('stock_threshold_rules', 1);
        $this->assertSame(6, StockThresholdRule::first()->minimum);
        $rendered = $this->get(route('stock-thresholds.index'))->assertOk()->assertSee('مشکی');
        $this->postJson(route('stock-thresholds.store'), array_merge($payload, ['minimum' => -1]))->assertUnprocessable();
        $this->postJson(route('stock-thresholds.store'), array_merge($payload, ['target_id' => 999999]))->assertUnprocessable();
        $this->delete(route('stock-thresholds.destroy', StockThresholdRule::first()))->assertRedirect();
        $this->assertDatabaseCount('stock_threshold_rules', 0);
    }

    public function test_alert_is_once_per_tehran_calendar_day_per_user_across_sessions(): void
    {
        [$root] = $this->fixture();
        $this->rule('category', $root->id, 'variant', 5);
        $this->travelTo(now('Asia/Tehran')->setTime(23, 59));
        $first = $this->actor();
        $this->actingAs($first)->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', true)->assertJsonPath('count', 1);
        $this->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', false);
        $this->actingAs($this->actor())->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', true);
        $this->travel(2)->minutes();
        $this->actingAs($first)->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', true);
        $this->assertDatabaseCount('stock_threshold_alert_days', 3);
    }

    public function test_no_shortage_does_not_consume_daily_alert_and_later_shortage_can_notify(): void
    {
        [$root] = $this->fixture(6);
        $this->rule('category', $root->id, 'variant', 5);
        $this->actingAs($this->actor())->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', false);
        $this->assertDatabaseCount('stock_threshold_alert_days', 0);
        DB::table('warehouse_stocks')->where('quantity', 6)->update(['quantity' => 5]);
        Cache::forget(StockThresholdService::CACHE_KEY);
        $this->postJson(route('stock-thresholds.daily'))->assertOk()->assertJsonPath('show', true);
    }
}
