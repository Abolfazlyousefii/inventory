<?php

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
});

function signInForProductFinder(bool $allowed = true): User
{
    $user = User::factory()->create();
    if ($allowed) {
        $role = Role::findOrCreate('preinvoice-product-finder', 'web');
        $role->givePermissionTo(Permission::findOrCreate('preinvoices.create', 'web'));
        $user->assignRole($role);
    }
    test()->actingAs($user);

    return $user;
}

function finderProduct(Category $category, string $name, string $shortCode, array $variants): Product
{
    $product = Product::query()->create([
        'category_id' => $category->id, 'name' => $name, 'sku' => 'SKU-'.$shortCode,
        'code' => '03'.$shortCode, 'short_barcode' => $shortCode, 'barcode' => 'BAR-'.$shortCode,
        'stock' => collect($variants)->sum('stock'), 'reserved' => 0, 'price' => 1000, 'is_sellable' => true,
    ]);
    foreach ($variants as $index => $row) {
        ProductVariant::query()->create([
            'product_id' => $product->id, 'model_list_id' => $row['model_id'] ?? null,
            'variant_name' => $row['name'], 'variety_name' => $row['name'],
            'variant_code' => $row['code'] ?? ($shortCode.'-'.$index), 'variety_code' => str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'sell_price' => 1000, 'buy_price' => 500, 'stock' => $row['stock'], 'reserved' => 0,
            'is_active' => $row['active'] ?? true, 'sales_enabled' => $row['sales_enabled'] ?? true,
        ]);
    }

    return $product;
}

it('protects the finder with authentication and preinvoice create permission', function () {
    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'A14']))->assertUnauthorized();
    signInForProductFinder(false);
    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'A14']))->assertForbidden();
});

it('returns product-centric A14 model and variant matches with bounded previews and safe fields', function () {
    signInForProductFinder();
    $category = Category::query()->create(['name' => 'گارد']);
    $model = ModelList::query()->create(['model_name' => 'Galaxy A14', 'code' => 'A014']);
    $product = finderProduct($category, 'گارد اقیانوسی سامسونگ', '0054', [
        ['name' => 'Galaxy A14 4G', 'code' => 'V-A14-1', 'stock' => 31, 'model_id' => $model->id],
        ['name' => 'Galaxy A14 5G', 'code' => 'V-A14-2', 'stock' => 12, 'model_id' => $model->id],
        ['name' => 'Case A14 Blue', 'code' => 'V-A14-3', 'stock' => 5],
        ['name' => 'A14 Extra', 'code' => 'V-A14-4', 'stock' => 0],
    ]);

    $response = $this->getJson(route('preinvoice.api.product-finder', ['q' => 'a 14', 'in_stock_only' => 1]));
    $response->assertOk()->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.matched_variants_count', 4)
        ->assertJsonCount(3, 'data.0.matched_variants')
        ->assertJsonPath('data.0.total_available_stock', 48);
    expect(array_keys($response->json('data.0')))->not->toContain('price', 'buy_price', 'margin', 'supplier');
});

it('searches product and variant codes and normalizes Persian digits and Arabic letters', function () {
    signInForProductFinder();
    $category = Category::query()->create(['name' => 'کیف']);
    $product = finderProduct($category, 'کیف یکتا', '1234', [['name' => 'مدل کوچک', 'code' => 'VAR-7788', 'stock' => 2]]);

    $this->getJson(route('preinvoice.api.product-finder', ['q' => '۱۲۳۴']))->assertJsonPath('data.0.id', $product->id);
    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'VAR-7788']))->assertJsonPath('data.0.id', $product->id);
    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'كيف يكتا']))->assertJsonPath('data.0.id', $product->id);
});

it('searches model brands and ranks exact models above product-name-only matches', function () {
    signInForProductFinder();
    $category = Category::query()->create(['name' => 'لوازم موبایل']);
    $model = ModelList::query()->create(['brand' => 'Samsung', 'model_name' => 'A14', 'code' => 'SM-A145']);
    $exactModel = finderProduct($category, 'محافظ نمایشگر ویژه', '3001', [
        ['name' => 'شفاف', 'stock' => 3, 'model_id' => $model->id],
    ]);
    $nameOnly = finderProduct($category, 'قاب A14 اقتصادی', '3002', [['name' => 'عمومی', 'stock' => 3]]);

    $a14 = $this->getJson(route('preinvoice.api.product-finder', ['q' => 'A14']))->assertOk();
    expect($a14->json('data.0.id'))->toBe($exactModel->id)
        ->and(collect($a14->json('data'))->pluck('id'))->toContain($nameOnly->id);

    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'Samsung']))
        ->assertOk()->assertJsonPath('data.0.id', $exactModel->id);
});

it('applies stock flags and recursive category filters', function () {
    signInForProductFinder();
    $root = Category::query()->create(['name' => 'لوازم جانبی']);
    $child = Category::query()->create(['name' => 'گارد', 'parent_id' => $root->id]);
    $leaf = Category::query()->create(['name' => 'گارد سامسونگ', 'parent_id' => $child->id]);
    $available = finderProduct($leaf, 'محصول موجود', '1010', [['name' => 'A14', 'stock' => 4]]);
    $unavailable = finderProduct($leaf, 'محصول ناموجود', '2020', [['name' => 'A14', 'stock' => 9, 'sales_enabled' => false]]);

    $this->getJson(route('preinvoice.api.product-finder', ['category_id' => $root->id, 'in_stock_only' => 1]))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $available->id);
    $ids = collect($this->getJson(route('preinvoice.api.product-finder', ['category_id' => $root->id, 'in_stock_only' => 0]))->json('data'))->pluck('id');
    expect($ids)->toContain($available->id, $unavailable->id);

    $this->getJson(route('preinvoice.api.product-finder', ['subcategory_id' => $child->id, 'in_stock_only' => 1]))
        ->assertJsonPath('data.0.id', $available->id);

    $details = $this->getJson('/preinvoice/api/products/'.$unavailable->id.'?include_unavailable=1')->assertOk();
    $details->assertJsonPath('data.product.varieties.0.sales_enabled', false)
        ->assertJsonPath('data.product.varieties.0.max_selectable_for_current_form', 0);
});

it('excludes inactive and sales-disabled variants from sellable totals', function () {
    signInForProductFinder();
    $category = Category::query()->create(['name' => 'موجودی']);
    $product = finderProduct($category, 'کالای ترکیبی', '4040', [
        ['name' => 'فعال', 'stock' => 7],
        ['name' => 'غیرفعال', 'stock' => 11, 'active' => false],
        ['name' => 'فروش بسته', 'stock' => 13, 'sales_enabled' => false],
    ]);

    $this->getJson(route('preinvoice.api.product-finder', ['q' => 'کالای ترکیبی']))
        ->assertOk()->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.sellable_variants_count', 1)
        ->assertJsonPath('data.0.total_available_stock', 7);
});

it('validates oversized queries and paginates without duplicate products', function () {
    signInForProductFinder();
    $this->getJson(route('preinvoice.api.product-finder', ['q' => str_repeat('a', 101)]))->assertUnprocessable();
    $category = Category::query()->create(['name' => 'صفحه‌بندی']);
    foreach (range(1, 12) as $index) {
        finderProduct($category, 'کالای تست '.$index, str_pad((string) $index, 4, '0', STR_PAD_LEFT), [['name' => 'مدل تست', 'stock' => 1]]);
    }
    $first = $this->getJson(route('preinvoice.api.product-finder', ['q' => 'تست', 'per_page' => 10, 'page' => 1]))->assertOk();
    $second = $this->getJson(route('preinvoice.api.product-finder', ['q' => 'تست', 'per_page' => 10, 'page' => 2]))->assertOk();
    expect(collect($first->json('data'))->pluck('id')->intersect(collect($second->json('data'))->pluck('id')))->toBeEmpty();
});

it('keeps finder selection isolated from reservation and waits for modal hidden event', function () {
    $finder = file_get_contents(public_path('js/pages/preinvoice-product-finder.js'));
    $page = file_get_contents(resource_path('views/preinvoice/create.blade.php'));

    expect($finder)->toContain('AbortController')->toContain('hidden.bs.modal')
        ->toContain('setTimeout(() => search(1), 350)')
        ->toContain('preinvoice:product-selected')
        ->not->toContain('syncDraftReservation')->not->toContain('groupedSelections')->not->toContain('reservation')
        ->and($page)->toContain("document.addEventListener('preinvoice:product-selected'")
        ->toContain('id="openProductFinderBtn"')
        ->toContain('<span>یافتن کالا</span>')
        ->toContain('applyMotherProduct(product, true)')
        ->toContain('motherCodeInput')
        ->toContain('saveGroupSelectionBtn')
        ->toContain('await syncDraftReservation(groupedSelections)');
});

it('uses one bootstrap bundle and stable modal lifecycle without double submit', function () {
    $finder = file_get_contents(public_path('js/pages/preinvoice-product-finder.js'));
    $page = file_get_contents(resource_path('views/preinvoice/create.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect(substr_count($layout.$page, "lib/bootstrap.bundle.min.js"))->toBe(1)
        ->and($finder)->toContain('const finderModal = bootstrap.Modal.getOrCreateInstance(modalEl)')
        ->toContain('finderModal.hide()')
        ->and($page)->toContain("const groupPickerModal = bootstrap.Modal.getOrCreateInstance(groupPickerElement)")
        ->toContain('if (isSavingGroupSelection) return;')
        ->toContain('groupPickerModal.hide()')
        ->toContain("groupPickerElement.addEventListener('hidden.bs.modal'")
        ->toContain('cleanupOrphanedProductModalArtifacts')
        ->not->toContain("document.getElementById('groupPickerModal').style.display")
        ->not->toContain('new bootstrap.Modal');
});
