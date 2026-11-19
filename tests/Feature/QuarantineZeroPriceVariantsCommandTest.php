<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeactivationDocument;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuarantineZeroPriceVariantsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('services.sales_server.sync_enabled', false);
    }

    public function test_zero_price_variant_only_disables_sales_and_keeps_price_stock_reserved(): void
    {
        [$product, $variant] = $this->seedVariant(0, 5, 2, 3, 4);
        $actor = User::factory()->create();
        $beforeWarehouseStock = (int) DB::table('warehouse_stocks')->sum('quantity');
        $beforeProductStock = (int) $product->fresh()->stock;

        // The production command requires a responsible user and an audit reason.
        $this->artisan('inventory:quarantine-zero-price-variants', [
            '--apply' => true,
            '--actor' => (string) $actor->id,
            '--reason' => 'INV-05 test: sale price is missing',
            '--output' => 'testing/zero-a',
        ])->assertExitCode(0);

        $variant->refresh();
        $this->assertSame(0, (int) $variant->sell_price);
        $this->assertSame(5, (int) $variant->stock);
        $this->assertSame(2, (int) $variant->reserved);
        $this->assertFalse($variant->sales_enabled);
        $this->assertTrue($variant->is_active);
        $this->assertSame($beforeProductStock, (int) $product->fresh()->stock);
        $this->assertSame($beforeWarehouseStock, (int) DB::table('warehouse_stocks')->sum('quantity'));

        $document = ProductDeactivationDocument::query()
            ->where('product_id', $product->id)->firstOrFail();
        $this->assertSame((int) $actor->id, (int) $document->created_by);
        $this->assertSame(ProductDeactivationDocument::ACTION_DEACTIVATE, $document->action_type);
        $this->assertSame(ProductDeactivationDocument::SCOPE_VARIANTS, $document->scope_type);
        $this->assertSame(1, (int) $document->items_count);
        $this->assertSame((int) $variant->id, (int) $document->items()->firstOrFail()->variant_id);
        $this->assertStringContainsString('INV-05', (string) $document->reason_text);
        Storage::disk('local')->assertExists('testing/zero-a/summary.json');
    }

    public function test_dry_run_changes_nothing_and_second_apply_is_idempotent(): void
    {
        [$product, $variant] = $this->seedVariant(0, 8, 1, 1, 0);
        $actor = User::factory()->create();
        $beforeWarehouseStock = (int) DB::table('warehouse_stocks')->sum('quantity');
        $beforeProductStock = (int) $product->fresh()->stock;

        $this->artisan('inventory:quarantine-zero-price-variants', [
            '--output' => 'testing/zero-b',
        ])->assertExitCode(0);
        $this->assertTrue($variant->fresh()->sales_enabled);
        $this->assertSame(0, ProductDeactivationDocument::query()->where('product_id', $product->id)->count());
        $this->assertSame($beforeWarehouseStock, (int) DB::table('warehouse_stocks')->sum('quantity'));
        Storage::disk('local')->assertExists('testing/zero-b/summary.json');

        foreach (['testing/zero-b1', 'testing/zero-b2'] as $output) {
            $this->artisan('inventory:quarantine-zero-price-variants', [
                '--apply' => true,
                '--actor' => (string) $actor->id,
                '--reason' => 'INV-05 test: zero price quarantine',
                '--output' => $output,
            ])->assertExitCode(0);
        }

        $this->assertFalse($variant->fresh()->sales_enabled);
        $this->assertSame(1, ProductDeactivationDocument::query()->where('product_id', $product->id)->count());
        $this->assertSame(8, (int) $variant->fresh()->stock);
        $this->assertSame(1, (int) $variant->fresh()->reserved);
        $this->assertSame(0, (int) $variant->fresh()->sell_price);
        $this->assertSame($beforeProductStock, (int) $product->fresh()->stock);
        $this->assertSame($beforeWarehouseStock, (int) DB::table('warehouse_stocks')->sum('quantity'));
    }

    public function test_apply_without_actor_or_reason_fails_without_changing_sales_status(): void
    {
        [$product, $variant] = $this->seedVariant(0, 5, 2, 3, 0);
        $beforeWarehouseStock = (int) DB::table('warehouse_stocks')->sum('quantity');

        $this->artisan('inventory:quarantine-zero-price-variants', [
            '--apply' => true,
            '--output' => 'testing/zero-missing-authorization',
        ])->assertExitCode(1);

        $this->assertTrue($variant->fresh()->sales_enabled);
        $this->assertSame(0, ProductDeactivationDocument::query()->where('product_id', $product->id)->count());
        $this->assertSame($beforeWarehouseStock, (int) DB::table('warehouse_stocks')->sum('quantity'));
    }

    /** @return array{Product, ProductVariant} */
    private function seedVariant(int $price, int $stock, int $reserved, int $centralQty, int $otherQty): array
    {
        $category = Category::query()->create([
            'name' => 'INV-05 quarantine '.uniqid(),
            'code' => uniqid('Q-C-'),
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'INV-05 product',
            'sku' => uniqid('Q-P-'),
            'price' => 1000,
            'stock' => $stock,
            'reserved' => $reserved,
            'is_sellable' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'INV-05 variant',
            'variant_code' => uniqid('Q-V-'),
            'sell_price' => $price,
            'stock' => $stock,
            'reserved' => $reserved,
            'is_active' => true,
            'sales_enabled' => true,
        ]);

        $centralWarehouse = Warehouse::query()->where('type', 'central')->first()
            ?? Warehouse::query()->create(['name' => 'Central', 'type' => 'central', 'is_active' => true]);
        $otherWarehouse = Warehouse::query()->where('type', 'personnel')->first()
            ?? Warehouse::query()->create(['name' => 'Personnel', 'type' => 'personnel', 'is_active' => true]);

        foreach ([[$centralWarehouse->id, $centralQty], [$otherWarehouse->id, $otherQty]] as [$warehouseId, $quantity]) {
            DB::table('warehouse_stocks')->insert([
                'warehouse_id' => $warehouseId,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
            ]);
        }

        return [$product, $variant];
    }
}
