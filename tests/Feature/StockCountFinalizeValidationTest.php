<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCountDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountFinalizeValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_form_redirects_back_with_structured_conflicts_and_preserves_input(): void
    {
        [$user, $document, $variants, $stocks] = $this->draftWithReservedVariants();

        $response = $this->withoutMiddleware()->actingAs($user)->from(route('stock-count-documents.edit', $document))->patch(route('stock-count-documents.finalize', $document), [
            'document_date' => '2026-07-11',
            'confirm_empty_as_zero' => '1',
            'actual_quantities' => [
                $variants[0]->id => '0',
                $variants[1]->id => '5',
            ],
        ]);

        $response->assertRedirect(route('stock-count-documents.edit', $document));
        $response->assertSessionHasErrors('finalize', null, 'stock_count_finalize');
        $response->assertSessionHasInput('actual_quantities.'.$variants[0]->id, '0');
        $response->assertSessionHas('stock_count_conflicts', function (array $conflicts) use ($variants) {
            return count($conflicts) === 2
                && collect($conflicts)->pluck('variant_id')->sort()->values()->all() === [$variants[0]->id, $variants[1]->id]
                && collect($conflicts)->every(fn ($conflict) => $conflict['reason_code'] === 'actual_below_reserved');
        });

        $this->assertSame('draft', $document->refresh()->status);
        $this->assertNull($document->finalized_at);
        $this->assertSame(12, $stocks[0]->refresh()->quantity);
        $this->assertSame(9, $stocks[1]->refresh()->quantity);
        $this->assertSame(3, $variants[0]->refresh()->reserved);
        $this->assertSame(8, $variants[1]->refresh()->reserved);
    }

    public function test_json_request_returns_structured_422_without_changing_inventory(): void
    {
        [$user, $document, $variants, $stocks] = $this->draftWithReservedVariants();

        $response = $this->withoutMiddleware()->actingAs($user)->patchJson(route('stock-count-documents.finalize', $document), [
            'document_date' => '2026-07-11',
            'confirm_empty_as_zero' => '1',
            'actual_quantities' => [
                $variants[0]->id => 1,
                $variants[1]->id => 7,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'امکان ثبت نهایی سند وجود ندارد.')
            ->assertJsonPath('conflicts.0.variant_id', $variants[0]->id)
            ->assertJsonPath('conflicts.0.reason_code', 'actual_below_reserved');

        $this->assertSame('draft', $document->refresh()->status);
        $this->assertSame(12, $stocks[0]->refresh()->quantity);
        $this->assertSame(9, $stocks[1]->refresh()->quantity);
    }

    public function test_stock_change_conflict_is_structured_and_rolls_back(): void
    {
        [$user, $document, $variants, $stocks] = $this->draftWithReservedVariants();
        $stocks[0]->update(['quantity' => 14]);

        $response = $this->withoutMiddleware()->actingAs($user)->patchJson(route('stock-count-documents.finalize', $document), [
            'document_date' => '2026-07-11',
            'confirm_empty_as_zero' => '1',
            'actual_quantities' => [
                $variants[0]->id => 20,
                $variants[1]->id => 20,
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('conflicts.0.reason_code', 'stock_changed');
        $this->assertSame('draft', $document->refresh()->status);
        $this->assertSame(14, $stocks[0]->refresh()->quantity);
    }

    public function test_successful_finalize_still_works_after_valid_quantities(): void
    {
        [$user, $document, $variants, $stocks] = $this->draftWithReservedVariants();

        $this->withoutMiddleware()->actingAs($user)->patch(route('stock-count-documents.finalize', $document), [
            'document_date' => '2026-07-11',
            'confirm_empty_as_zero' => '1',
            'actual_quantities' => [
                $variants[0]->id => 15,
                $variants[1]->id => 18,
            ],
        ])->assertRedirect(route('stock-count-documents.view', $document));

        $this->assertSame('finalized', $document->refresh()->status);
        $this->assertSame(12, $stocks[0]->refresh()->quantity);
        $this->assertSame(10, $stocks[1]->refresh()->quantity);
        $this->assertSame(3, $variants[0]->refresh()->reserved);
        $this->assertSame(8, $variants[1]->refresh()->reserved);
    }

    public function test_edit_page_renders_finalize_alert_client_validation_and_scroll_hooks(): void
    {
        [$user, $document, $variants] = $this->draftWithReservedVariants();

        $conflicts = [[
            'variant_id' => $variants[0]->id,
            'variant_name' => 'تنوع تست ۱',
            'sku' => 'T-1',
            'reason_code' => 'actual_below_reserved',
            'reason' => 'موجودی واقعی کمتر از رزرو فعال است.',
            'actual_quantity' => 0,
            'reserved_quantity' => 3,
            'current_quantity' => 12,
        ]];

        $response = $this->withoutMiddleware()->actingAs($user)->withSession([
            'stock_count_conflicts' => $conflicts,
            'errors' => (new \Illuminate\Support\ViewErrorBag())->put('stock_count_finalize', new \Illuminate\Support\MessageBag(['finalize' => ['خطا']]))
        ])->get(route('stock-count-documents.edit', $document));

        $response->assertOk()
            ->assertSee('ثبت نهایی سند انجام نشد')
            ->assertSee('has-stock-count-error', false)
            ->assertSee('موجودی واقعی نمی‌تواند کمتر از رزرو فعال باشد', false)
            ->assertSee('این مقدار خالی است و هنگام ثبت نهایی صفر خواهد شد', false)
            ->assertSee('scrollIntoView', false)
            ->assertSee('data-scroll-error', false);
    }

    private function draftWithReservedVariants(): array
    {
        $user = User::factory()->create();
        $category = Category::query()->create(['name' => 'عمومی']);
        $warehouse = Warehouse::query()->create(['name' => 'انبار مرکزی', 'type' => 'central', 'is_active' => true]);
        $product = Product::query()->create(['category_id' => $category->id, 'name' => 'محصول تست', 'sku' => 'P-1', 'stock' => 21, 'price' => 1000]);
        $variantA = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع تست ۱', 'variant_code' => 'T-1', 'variety_id' => 2001, 'sell_price' => 1000, 'stock' => 12, 'reserved' => 3, 'is_active' => true, 'sales_enabled' => true]);
        $variantB = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'تنوع تست ۲', 'variant_code' => 'T-2', 'variety_id' => 2002, 'sell_price' => 1000, 'stock' => 9, 'reserved' => 8, 'is_active' => true, 'sales_enabled' => true]);
        $stockA = WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variantA->id, 'quantity' => 12]);
        $stockB = WarehouseStock::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variantB->id, 'quantity' => 9]);

        $this->withoutMiddleware()->actingAs($user)->post(route('stock-count-documents.store'), [
            'product_id' => $product->id,
            'document_date' => '2026-07-11',
            'actual_quantities' => [
                $variantA->id => 15,
                $variantB->id => 17,
            ],
        ])->assertRedirect();

        return [$user, StockCountDocument::query()->firstOrFail(), [$variantA, $variantB], [$stockA, $stockB]];
    }
}
