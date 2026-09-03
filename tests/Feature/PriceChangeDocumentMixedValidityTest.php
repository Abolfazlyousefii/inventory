<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PriceChangeDocument;
use App\Models\PriceChangeDocumentItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PriceChangeDocumentMixedValidityTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_is_created_with_ninety_five_valid_and_five_invalid_items(): void
    {
        [$user, $category, $product] = $this->fixture();

        foreach (range(1, 100) as $number) {
            $this->variant($product, $number <= 95 ? 10_000 : 0, $number);
        }

        $this->actingAs($user)
            ->post(route('products.price-changes.store'), $this->payload($category))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $document = PriceChangeDocument::query()->sole();

        $this->assertSame(100, $document->items_count);
        $this->assertSame(100, $document->items()->count());
        $this->assertSame(95, $document->items()->where('status', PriceChangeDocumentItem::STATUS_VALID)->count());
        $this->assertSame(5, $document->items()->where('status', PriceChangeDocumentItem::STATUS_INVALID)->count());
    }

    public function test_document_is_rejected_when_every_item_is_invalid(): void
    {
        [$user, $category, $product] = $this->fixture();
        foreach (range(1, 5) as $number) {
            $this->variant($product, 0, $number);
        }

        $this->actingAs($user)
            ->from(route('products.price-changes.create'))
            ->post(route('products.price-changes.store'), $this->payload($category))
            ->assertRedirect(route('products.price-changes.create'))
            ->assertSessionHasErrors(['change_value' => 'هیچ آیتم معتبری برای ثبت وجود ندارد']);

        $this->assertDatabaseCount('price_change_documents', 0);
        $this->assertDatabaseCount('price_change_document_items', 0);
    }

    public function test_apply_changes_only_valid_items_and_leaves_invalid_items_untouched(): void
    {
        [$user, $category, $product] = $this->fixture();
        $validVariant = $this->variant($product, 10_000, 1);
        $invalidVariant = $this->variant($product, 0, 2);

        $this->actingAs($user)->post(route('products.price-changes.store'), $this->payload($category));
        $document = PriceChangeDocument::query()->sole();

        $this->actingAs($user)
            ->post(route('products.price-changes.apply', $document))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(11_000, $validVariant->fresh()->sell_price);
        $this->assertSame(0, $invalidVariant->fresh()->sell_price);
        $this->assertSame(PriceChangeDocumentItem::STATUS_APPLIED, $document->items()->where('product_variant_id', $validVariant->id)->value('status'));
        $this->assertSame(PriceChangeDocumentItem::STATUS_INVALID, $document->items()->where('product_variant_id', $invalidVariant->id)->value('status'));
        $this->assertNull($document->items()->where('product_variant_id', $invalidVariant->id)->value('applied_at'));
    }

    public function test_invalid_item_persists_its_validation_message_and_details(): void
    {
        [$user, $category, $product] = $this->fixture();
        $this->variant($product, 10_000, 1);
        $invalidVariant = $this->variant($product, 0, 2);

        $this->actingAs($user)->post(route('products.price-changes.store'), $this->payload($category));

        $item = PriceChangeDocumentItem::query()->where('product_variant_id', $invalidVariant->id)->sole();
        $this->assertSame(PriceChangeDocumentItem::STATUS_INVALID, $item->status);
        $this->assertSame('قیمت فروش قبلی معتبر نیست.', $item->error_message);
        $this->assertSame('zero_price', $item->validation_details['type']);
    }

    public function test_non_positive_calculated_price_is_saved_for_review_without_blocking_valid_items(): void
    {
        [$user, $category, $product] = $this->fixture();
        $this->variant($product, 10_000, 1);
        $invalidVariant = $this->variant($product, 500, 2);
        $payload = array_merge($this->payload($category), [
            'change_type' => PriceChangeDocument::CHANGE_DECREASE_AMOUNT,
            'change_value' => 1_000,
        ]);

        $this->actingAs($user)
            ->post(route('products.price-changes.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $item = PriceChangeDocumentItem::query()->where('product_variant_id', $invalidVariant->id)->sole();
        $this->assertSame(-500, $item->new_price);
        $this->assertSame(PriceChangeDocumentItem::STATUS_INVALID, $item->status);
        $this->assertSame('قیمت جدید باید بزرگ‌تر از صفر باشد.', $item->error_message);
        $this->assertSame('non_positive_new_price', $item->validation_details['type']);
    }

    public function test_apply_keeps_existing_price_drift_protection_for_valid_items(): void
    {
        [$user, $category, $product] = $this->fixture();
        $variant = $this->variant($product, 10_000, 1);
        $this->actingAs($user)->post(route('products.price-changes.store'), $this->payload($category));
        $document = PriceChangeDocument::query()->sole();
        $variant->forceFill(['sell_price' => 12_000])->save();

        $this->actingAs($user)
            ->post(route('products.price-changes.apply', $document))
            ->assertSessionHasErrors('apply');

        $this->assertSame(12_000, $variant->fresh()->sell_price);
        $this->assertSame(PriceChangeDocument::STATUS_DRAFT, $document->fresh()->status);
        $this->assertSame(PriceChangeDocumentItem::STATUS_VALID, $document->items()->sole()->status);
    }

    private function fixture(): array
    {
        $role = Role::findOrCreate('price-change-mixed-validity', 'web');
        foreach (['products.price_changes.create', 'products.price_changes.view', 'products.price_changes.apply'] as $key) {
            $role->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }
        $pageId = DB::table('permissions')->where('key', 'page.products.price_changes')->value('id');
        DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $pageId]);
        $user = User::factory()->create();
        $user->assignRole($role);

        $category = Category::query()->create(['name' => 'Mixed price changes']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Mixed product',
            'sku' => 'MIXED-PC',
            'stock' => 100,
            'reserved' => 0,
            'price' => 10_000,
            'is_sellable' => true,
        ]);

        return [$user, $category, $product];
    }

    private function variant(Product $product, int $sellPrice, int $number): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'variant_name' => 'Variant '.$number,
            'variant_code' => 'MIXED-'.$number,
            'sell_price' => $sellPrice,
            'stock' => 1,
            'reserved' => 0,
            'is_active' => true,
            'sales_enabled' => true,
        ]);
    }

    private function payload(Category $category): array
    {
        return [
            'category_id' => $category->id,
            'subcategory_id' => null,
            'product_id' => null,
            'variant_ids' => [],
            'change_type' => PriceChangeDocument::CHANGE_INCREASE_AMOUNT,
            'change_value' => 1_000,
            'rounding_mode' => PriceChangeDocument::ROUND_NONE,
            'include_active_products_only' => 1,
            'include_active_variants_only' => 1,
            'in_stock_only' => 0,
        ];
    }
}
