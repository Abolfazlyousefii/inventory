<?php

namespace Tests\Feature;

use App\Models\PriceChangeDocument;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PriceChangeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UniformProductPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('parent_id')->nullable();
            $t->string('code')->nullable();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->integer('category_id');
            $t->string('name');
            $t->string('sku')->nullable();
            $t->string('code')->nullable();
            $t->boolean('is_sellable')->default(true);
            $t->integer('price')->default(0);
            $t->integer('stock')->default(0);
            $t->integer('reserved')->default(0);
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_10_15_180000_add_uniform_sale_price_to_products.php'))->up();
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->integer('product_id');
            $t->integer('model_list_id')->nullable();
            $t->string('variant_name')->nullable();
            $t->string('variety_name')->nullable();
            $t->string('variant_code')->nullable();
            $t->integer('sell_price');
            $t->integer('buy_price')->default(80);
            $t->integer('stock')->default(10);
            $t->integer('reserved')->default(2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('price_change_documents', function (Blueprint $t) {
            $t->id();
            foreach (['uuid', 'code', 'title', 'scope_type', 'change_type', 'rounding_mode', 'status', 'note'] as $f) {
                $t->string($f)->nullable();
            }$t->json('scope_payload');
            $t->decimal('change_value', 18, 2)->nullable();
            $t->integer('items_count');
            $t->integer('created_by')->nullable();
            $t->integer('applied_by')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('price_change_document_items', function (Blueprint $t) {
            $t->id();
            foreach (['price_change_document_id', 'product_id', 'product_variant_id', 'old_price', 'new_price'] as $f) {
                $t->integer($f);
            }foreach (['product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot', 'change_type', 'rounding_mode', 'status', 'error_message'] as $f) {
                $t->string($f)->nullable();
            }$t->decimal('change_value', 18, 2)->nullable();
            $t->json('validation_details')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            $t->string('action');
            $t->string('subject_type')->nullable();
            $t->integer('subject_id')->nullable();
            $t->text('description')->nullable();
            $t->json('properties')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });
        DB::table('categories')->insert(['id' => 1, 'name' => 'Cases']);
        DB::table('products')->insert(['id' => 1, 'category_id' => 1, 'name' => 'Case', 'price' => 0, 'stock' => 20, 'reserved' => 4]);
        DB::table('product_variants')->insert([['id' => 1, 'product_id' => 1, 'variant_name' => 'A', 'sell_price' => 350, 'is_active' => true], ['id' => 2, 'product_id' => 1, 'variant_name' => 'B', 'sell_price' => 200, 'is_active' => false]]);
    }

    private function payload(string $type = 'set_fixed_price'): array
    {
        return ['category_id' => 1, 'subcategory_id' => null, 'product_id' => 1, 'variant_ids' => [], 'uniform_pricing' => true, 'include_active_variants_only' => true, 'in_stock_only' => true, 'change_type' => $type, 'change_value' => $type === 'set_fixed_price' ? 400 : 10, 'rounding_mode' => 'none'];
    }

    private function draft(): PriceChangeDocument
    {
        $s = app(PriceChangeService::class);

        return $s->storeDraft($this->payload(), $s->buildPreview($this->payload()));
    }

    private function actor(): User
    {
        $user = new User;
        $user->id = 1;

        return $user;
    }

    public function test_fixed_price_covers_every_variant_and_relative_change_never_guesses_mixed_prices(): void
    {
        $s = app(PriceChangeService::class);
        $rows = $s->buildPreview($this->payload());
        $this->assertCount(2, $rows);
        $this->assertSame([400], $rows->pluck('new_price')->unique()->values()->all());
        $this->assertSame(['valid'], $rows->pluck('status')->unique()->values()->all());
        $relative = $s->buildPreview($this->payload('increase_percent'));
        $this->assertCount(2, $relative);
        $this->assertSame(['invalid'], $relative->pluck('status')->unique()->values()->all());
    }

    public function test_apply_sets_master_and_all_variants_new_variants_inherit_and_purchase_writes_cannot_override(): void
    {
        $s = app(PriceChangeService::class);
        $doc = $this->draft();
        $before = DB::table('product_variants')->get(['id', 'stock', 'reserved', 'buy_price'])->toArray();
        $s->applyDocument($doc, $this->actor());
        $this->assertSame(400, (int) Product::find(1)->uniform_sale_price);
        $this->assertSame([400], ProductVariant::pluck('sell_price')->unique()->all());
        $this->assertEquals($before, DB::table('product_variants')->get(['id', 'stock', 'reserved', 'buy_price'])->toArray());
        $variant = ProductVariant::create(['product_id' => 1, 'variant_name' => 'New', 'sell_price' => 50]);
        $this->assertSame(400, $variant->sell_price);
        $variant->update(['sell_price' => 100, 'buy_price' => 90]);
        $this->assertSame(400, $variant->fresh()->sell_price);
        $this->assertSame(90, $variant->fresh()->buy_price);
        $preview = $s->buildPreview($this->payload('increase_percent'));
        $this->assertSame([440], $preview->pluck('new_price')->unique()->values()->all());
    }

    public function test_stale_variant_price_rolls_back_the_entire_document(): void
    {
        $doc = $this->draft();
        DB::table('product_variants')->where('id', 2)->update(['sell_price' => 250]);
        try {
            app(PriceChangeService::class)->applyDocument($doc, $this->actor());
            $this->fail('Stale price accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تغییر', $e->getMessage());
        }
        $this->assertNull(Product::find(1)->uniform_sale_price);
        $this->assertSame(350, ProductVariant::find(1)->sell_price);
        $this->assertSame('draft', $doc->fresh()->status);
    }

    public function test_new_variant_after_preview_requires_a_new_document(): void
    {
        $doc = $this->draft();
        DB::table('product_variants')->insert(['product_id' => 1, 'sell_price' => 300]);
        $this->expectException(RuntimeException::class);
        app(PriceChangeService::class)->applyDocument($doc, $this->actor());
    }

    public function test_consistent_legacy_price_supports_percent_change_without_reading_the_zero_summary(): void
    {
        DB::table('product_variants')->update(['sell_price' => 350]);
        $s = app(PriceChangeService::class);
        $payload = $this->payload('increase_percent');
        $preview = $s->buildPreview($payload);
        $this->assertSame([385], $preview->pluck('new_price')->unique()->values()->all());
        $doc = $s->storeDraft($payload, $preview);
        $s->applyDocument($doc, $this->actor());
        $this->assertSame(385, Product::find(1)->uniform_sale_price);
    }

    public function test_explicit_fixed_price_can_repair_zero_prices_without_activating_disabled_variants(): void
    {
        DB::table('product_variants')->update(['sell_price' => 0]);
        $s = app(PriceChangeService::class);
        $s->applyDocument($this->draft(), $this->actor());
        $this->assertSame([400], ProductVariant::pluck('sell_price')->unique()->all());
        $this->assertFalse(ProductVariant::find(2)->is_active);
        $this->assertSame(400, Product::find(1)->uniform_sale_price);
    }

    public function test_legacy_partial_price_document_cannot_override_an_established_master(): void
    {
        $s = app(PriceChangeService::class);
        $s->applyDocument($this->draft(), $this->actor());
        $payload = array_merge($this->payload(), ['uniform_pricing' => false, 'variant_ids' => [1], 'change_value' => 500]);
        $legacy = $s->storeDraft($payload, $s->buildPreview($payload));
        $this->expectException(RuntimeException::class);
        $s->applyDocument($legacy, $this->actor());
    }

    public function test_historical_invoice_and_preinvoice_prices_are_never_rewritten(): void
    {
        foreach (['invoice_items', 'preinvoice_order_items'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->integer('price');
            });
            DB::table($table)->insert(['price' => 123]);
        }
        $s = app(PriceChangeService::class);
        $s->applyDocument($this->draft(), $this->actor());
        $this->assertSame(123, DB::table('invoice_items')->value('price'));
        $this->assertSame(123, DB::table('preinvoice_order_items')->value('price'));
        $this->assertSame(1, DB::table('invoice_items')->count());
    }

    public function test_a_later_product_conflict_rolls_back_earlier_product_updates(): void
    {
        DB::table('products')->insert(['id' => 2, 'category_id' => 1, 'name' => 'Second', 'price' => 0]);
        DB::table('product_variants')->insert(['id' => 3, 'product_id' => 2, 'sell_price' => 200]);
        $s = app(PriceChangeService::class);
        $payload = array_merge($this->payload(), ['product_id' => null]);
        $doc = $s->storeDraft($payload, $s->buildPreview($payload));
        DB::table('product_variants')->where('id', 3)->update(['sell_price' => 300]);
        try {
            $s->applyDocument($doc, $this->actor());
            $this->fail('Stale second product accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('تغییر', $e->getMessage());
        }
        $this->assertNull(Product::find(1)->uniform_sale_price);
        $this->assertSame(350, ProductVariant::find(1)->sell_price);
        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame(0, $doc->items()->where('status', 'applied')->count());
    }

    public function test_master_change_after_preview_is_rejected_even_if_variant_prices_have_not_changed(): void
    {
        $doc = $this->draft();
        DB::table('products')->where('id', 1)->update(['uniform_sale_price' => 450]);
        $this->expectException(RuntimeException::class);
        app(PriceChangeService::class)->applyDocument($doc, $this->actor());
    }
}
