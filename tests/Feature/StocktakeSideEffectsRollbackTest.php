<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductVariant, StockCountDocument, StockMovement, User, Warehouse, WarehouseStock};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StocktakeSideEffectsRollbackTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        // This isolated SQLite database disappears with the application. Some
        // production migrations are intentionally forward-only, so teardown
        // must not run their prohibited down() methods.
        $this->beforeApplicationDestroyed(function () {
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        });
    }

    private function fixture(): array
    {
        // Match the nullable production draft schema; recovered SQLite legacy
        // migrations still declare the original non-null count column.
        \Illuminate\Support\Facades\Schema::table('stock_count_document_items',
            fn (\Illuminate\Database\Schema\Blueprint $table) =>
                $table->integer('actual_quantity')->nullable()->change()
        );
        $user = User::factory()->create(['is_active'=>true, 'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($user);
        $root = Category::withoutEvents(fn () => Category::create(['name'=>'Bulk '.Str::random(8)]));
        $child = Category::withoutEvents(fn () => Category::create(['name'=>'Child '.Str::random(8), 'parent_id'=>$root->id]));
        $outside = Category::withoutEvents(fn () => Category::create(['name'=>'Outside '.Str::random(8)]));
        $warehouse = Warehouse::where('type', 'central')->first()
            ?? Warehouse::withoutEvents(fn () => Warehouse::create(['name'=>'انبار مرکزی', 'type'=>'central', 'is_active'=>true]));
        $make = function (Category $category) use ($warehouse): array {
            $product = Product::withoutEvents(fn () => Product::create([
                'name'=>'Bulk product', 'category_id'=>$category->id, 'sku'=>Str::random(12),
                'stock'=>8, 'price'=>150000, 'is_sellable'=>false,
            ]));
            $variant = ProductVariant::withoutEvents(fn () => ProductVariant::create([
                'product_id'=>$product->id, 'variant_name'=>'Black', 'variant_code'=>Str::random(12),
                'stock'=>8, 'sell_price'=>150000, 'is_active'=>false, 'sales_enabled'=>false,
            ]));
            WarehouseStock::withoutEvents(fn () => WarehouseStock::create([
                'warehouse_id'=>$warehouse->id, 'product_id'=>$product->id,
                'product_variant_id'=>$variant->id, 'quantity'=>8,
            ]));
            return [$product, $variant];
        };
        return [$root, $make($root), $make($child), $make($outside)];
    }

    public function test_failed_batch_discards_outbox_rows_and_webhooks_but_successful_retry_dispatches_after_commit(): void
    {
        [$root, $first, $second] = $this->fixture();
        // DatabaseMigrations deliberately leaves no wrapping test transaction:
        // real transaction callbacks execute on successful endpoint commits.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
        if (! \Illuminate\Support\Facades\Schema::hasTable('site_stock_sync_outbox')) {
            \Illuminate\Support\Facades\Schema::create('site_stock_sync_outbox', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('variant_id');
                $table->string('origin'); $table->timestamp('created_at'); $table->timestamp('available_at');
            });
        }
        config(['services.request_driven_site_stock'=>true]);
        \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['ok'=>true], 200)]);
        \App\Models\InventoryWebhookSetting::create([
            'is_enabled'=>true, 'endpoint_url'=>'https://inventory-webhook.test/events',
            'secret'=>'isolated-test-secret', 'timeout_seconds'=>1,
        ]);
        $service = app(\App\Services\StockCountDocumentService::class);
        $a = $service->createProductDraft(['product_id'=>$first[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$first[1]->id=>6]], auth()->id());
        $b = $service->createProductDraft(['product_id'=>$second[0]->id, 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$second[1]->id=>null]], auth()->id());
        $payload = ['request_id'=>(string) Str::uuid(), 'category_id'=>$root->id, 'document_ids'=>[$a->id, $b->id],
            'review_tokens'=>[$a->id=>$service->reviewToken($a), $b->id=>$service->reviewToken($b)]];
        $this->postJson('/stock-count-documents/category-finalize', $payload)->assertUnprocessable();
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('site_stock_sync_outbox')->count());
        $this->assertSame(0, \App\Models\InventoryWebhookLog::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('draft', $a->fresh()->status);
        $service->updateProductDraft($b, ['document_date'=>'2026-10-30',
            'actual_quantities'=>[$second[1]->id=>5]], auth()->id());
        $payload['review_tokens'][$b->id] = $service->reviewToken($b);
        $this->postJson('/stock-count-documents/category-finalize', $payload)->assertOk();
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->url() === 'https://inventory-webhook.test/events');
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('site_stock_sync_outbox')->count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame('finalized', $a->fresh()->status);
        $this->assertSame('finalized', $b->fresh()->status);
        $sent = count(\Illuminate\Support\Facades\Http::recorded());
        $outbox = \Illuminate\Support\Facades\DB::table('site_stock_sync_outbox')->count();
        $this->postJson('/stock-count-documents/category-finalize', $payload)->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(2, StockMovement::count());
        $this->assertSame($sent, count(\Illuminate\Support\Facades\Http::recorded()));
        $this->assertSame($outbox, \Illuminate\Support\Facades\DB::table('site_stock_sync_outbox')->count());
    }
}
