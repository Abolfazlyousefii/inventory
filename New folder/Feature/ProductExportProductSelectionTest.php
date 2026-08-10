<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportProductSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        $this->app->forgetInstance(PermissionRegistrar::class);
        $this->createIsolatedSchema();
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
    }

    public function test_without_product_ids_keeps_existing_all_products_behavior(): void
    {
        $this->signIn();
        $this->product('محصول اول');
        $this->product('محصول دوم');

        $this->get(route('admin.product-exports.data'))
            ->assertOk()
            ->assertSee('محصول اول')
            ->assertSee('محصول دوم');
    }

    public function test_one_or_multiple_product_ids_only_show_selected_products(): void
    {
        $this->signIn();
        $first = $this->product('محصول انتخابی اول');
        $second = $this->product('محصول انتخابی دوم');
        $other = $this->product('محصول خارج از انتخاب');

        $this->get(route('admin.product-exports.data', ['product_ids' => [$first->id]]))
            ->assertOk()
            ->assertSee($first->name)
            ->assertDontSee($second->name)
            ->assertDontSee($other->name);

        $this->get(route('admin.product-exports.data', ['product_ids' => [$first->id, $second->id]]))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertDontSee($other->name);
    }

    public function test_duplicate_product_id_is_normalized_and_rendered_once(): void
    {
        $this->signIn();
        $product = $this->product('محصول یکتای انتخابی');

        $response = $this->get(route('admin.product-exports.data', [
            'product_ids' => [$product->id, (string) $product->id, $product->id],
        ]))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), $product->name));
    }

    public function test_invalid_or_more_than_two_hundred_product_ids_are_rejected(): void
    {
        $this->signIn();
        $url = route('admin.product-exports.index');

        $this->from($url)
            ->get(route('admin.product-exports.index', ['product_ids' => ['invalid']]))
            ->assertRedirect($url)
            ->assertSessionHasErrors('product_ids.0');

        $this->from($url)
            ->get(route('admin.product-exports.index', ['product_ids' => range(1, 201)]))
            ->assertRedirect($url)
            ->assertSessionHasErrors('product_ids');
    }

    public function test_product_ids_intersect_with_category_filter(): void
    {
        $this->signIn();
        $wantedCategory = Category::create(['name' => 'دسته انتخابی']);
        $otherCategory = Category::create(['name' => 'دسته دیگر']);
        $wanted = $this->product('محصول دسته درست', $wantedCategory);
        $other = $this->product('محصول دسته نادرست', $otherCategory);

        $this->get(route('admin.product-exports.data', [
            'root_category_id' => $wantedCategory->id,
            'product_ids' => [$wanted->id, $other->id],
        ]))
            ->assertOk()
            ->assertSee($wanted->name)
            ->assertDontSee($other->name);
    }

    public function test_product_ids_intersect_with_model_list_and_stock_status(): void
    {
        $this->signIn();
        $model = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A55']);
        $matching = $this->product('محصول مدل درست');
        $wrongModel = $this->product('محصول مدل نادرست');
        $outOfStock = $this->product('محصول ناموجود', null, 0);
        $this->variant($matching, $model, 'A55 موجود', 4);
        $this->variant($wrongModel, ModelList::create(['brand' => 'Samsung', 'model_name' => 'A35']), 'A35 موجود', 4);

        $this->get(route('admin.product-exports.data', [
            'model_brand' => 'Samsung',
            'model_list_ids' => [$model->id],
            'product_ids' => [$matching->id, $wrongModel->id],
        ]))
            ->assertOk()
            ->assertSee($matching->name)
            ->assertDontSee($wrongModel->name);

        $this->get(route('admin.product-exports.data', [
            'stock_status' => 'in_stock',
            'product_ids' => [$matching->id, $outOfStock->id],
        ]))
            ->assertOk()
            ->assertSee($matching->name)
            ->assertDontSee($outOfStock->name);
    }

    public function test_product_search_supports_name_codes_and_variant_fields(): void
    {
        $this->signIn();
        $byName = $this->product('گارد شفاف ویژه');
        $byCode = $this->product('کالای کددار', null, 1, ['code' => 'PRD-908']);
        $bySku = $this->product('کالای اس کی یو', null, 1, ['sku' => 'SKU-771']);
        $byBarcode = $this->product('کالای بارکددار', null, 1, ['barcode' => '6261234567890']);
        $byShortBarcode = $this->product('کالای بارکد کوتاه', null, 1, ['short_barcode' => '445566']);
        $byVariant = $this->product('کالای تنوع‌دار');
        $this->variant(
            $byVariant,
            ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone 15']),
            'مدل خاص',
            2,
            ['variant_code' => 'VAR-2026', 'variety_code' => 'V26', 'variety_name' => 'آبی اقیانوسی']
        );

        foreach ([
            ['شفاف ویژه', $byName->id],
            ['PRD-908', $byCode->id],
            ['SKU-771', $bySku->id],
            ['6261234567890', $byBarcode->id],
            ['445566', $byShortBarcode->id],
            ['VAR-2026', $byVariant->id],
            ['آبی اقیانوسی', $byVariant->id],
        ] as [$query, $expectedId]) {
            $this->getJson(route('admin.product-exports.products.search', ['q' => $query]))
                ->assertOk()
                ->assertJsonFragment(['id' => $expectedId]);
        }
    }

    public function test_product_search_obeys_current_filters_and_limit(): void
    {
        $this->signIn();
        $wantedCategory = Category::create(['name' => 'جست‌وجوی هدف']);
        $otherCategory = Category::create(['name' => 'جست‌وجوی دیگر']);
        $wanted = $this->product('کالای جست‌وجو هدف', $wantedCategory);
        $other = $this->product('کالای جست‌وجو خارج', $otherCategory);

        $this->getJson(route('admin.product-exports.products.search', [
            'q' => 'کالای جست‌وجو',
            'root_category_id' => $wantedCategory->id,
        ]))
            ->assertOk()
            ->assertJsonFragment(['id' => $wanted->id])
            ->assertJsonMissing(['id' => $other->id]);

        for ($index = 1; $index <= 35; $index++) {
            $this->product('Limit Search Product '.$index);
        }

        $this->getJson(route('admin.product-exports.products.search', ['q' => 'Limit Search']))
            ->assertOk()
            ->assertJsonPath('limit', 30)
            ->assertJsonCount(30, 'items');
    }

    public function test_one_character_search_is_live_and_limited_to_fifteen_products(): void
    {
        $this->signIn();
        for ($index = 1; $index <= 20; $index++) {
            $this->product('الف محصول '.$index);
        }

        $this->getJson(route('admin.product-exports.products.search', ['q' => 'ا']))
            ->assertOk()
            ->assertJsonPath('limit', 15)
            ->assertJsonCount(15, 'items');
    }

    public function test_search_normalizes_persian_letters_digits_and_multiword_tokens(): void
    {
        $this->signIn();
        $normalized = $this->product('ایرپاد کابل 1234');
        $multiword = $this->product('گارد چرمی ویژه');
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'آیفون 13']);
        $this->variant($multiword, $model, 'مدل محافظ');

        foreach (['ایرپاد', 'ايرپاد', 'کابل', 'كابل', '1234', '۱۲۳۴'] as $query) {
            $this->getJson(route('admin.product-exports.products.search', ['q' => $query]))
                ->assertOk()
                ->assertJsonFragment(['id' => $normalized->id]);
        }

        $this->getJson(route('admin.product-exports.products.search', ['q' => 'گارد چرمی آیفون']))
            ->assertOk()
            ->assertJsonFragment(['id' => $multiword->id]);
    }

    public function test_multiple_matching_variants_return_one_product_with_match_information(): void
    {
        $this->signIn();
        $product = $this->product('محصول چند تنوع');
        $model = ModelList::create(['brand' => 'Apple', 'model_name' => 'iPhone']);
        $this->variant($product, $model, 'تنوع ویژه اول', 2, ['variant_code' => 'MATCH-1']);
        $this->variant($product, $model, 'تنوع ویژه دوم', 2, ['variant_code' => 'MATCH-2']);

        $response = $this->getJson(route('admin.product-exports.products.search', ['q' => 'تنوع ویژه']))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $product->id);

        $this->assertNotEmpty($response->json('items.0.matched_variant'));
        $this->assertSame('موجود', $response->json('items.0.availability_label'));
    }

    public function test_exact_code_is_ranked_before_exact_product_name(): void
    {
        $this->signIn();
        $nameMatch = $this->product('PRIORITY');
        $codeMatch = $this->product('محصول کد اولویت', null, 1, ['code' => 'PRIORITY']);

        $response = $this->getJson(route('admin.product-exports.products.search', ['q' => 'PRIORITY']))
            ->assertOk();

        $this->assertSame($codeMatch->id, $response->json('items.0.id'));
        $this->assertContains($nameMatch->id, collect($response->json('items'))->pluck('id')->all());
    }

    public function test_product_search_respects_model_list_and_stock_filters(): void
    {
        $this->signIn();
        $selectedModel = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A55']);
        $otherModel = ModelList::create(['brand' => 'Samsung', 'model_name' => 'A35']);
        $matching = $this->product('گارد مدل موجود');
        $wrongModel = $this->product('گارد مدل دیگر');
        $outOfStock = $this->product('گارد مدل ناموجود');
        $this->variant($matching, $selectedModel, 'A55', 3);
        $this->variant($wrongModel, $otherModel, 'A35', 3);
        $this->variant($outOfStock, $selectedModel, 'A55', 0);

        $this->getJson(route('admin.product-exports.products.search', [
            'q' => 'گارد مدل',
            'model_brand' => 'Samsung',
            'model_list_ids' => [$selectedModel->id],
            'stock_status' => 'in_stock',
        ]))
            ->assertOk()
            ->assertJsonFragment(['id' => $matching->id])
            ->assertJsonMissing(['id' => $wrongModel->id])
            ->assertJsonMissing(['id' => $outOfStock->id]);
    }

    public function test_empty_search_does_not_execute_catalog_query(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $products = app(ProductExportService::class)->searchProducts([
            'root_category_id' => null,
            'subcategory_id' => null,
            'model_list_ids' => [],
            'product_ids' => [],
            'stock_status' => 'all',
            'include_without_price' => false,
        ], '');

        $this->assertTrue($products->isEmpty());
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_product_search_requires_products_export_permission(): void
    {
        $this->signIn(false);

        $this->getJson(route('admin.product-exports.products.search', ['q' => 'گارد']))
            ->assertForbidden();
    }

    public function test_selected_products_are_restored_after_refresh(): void
    {
        $this->signIn();
        $product = $this->product('محصول حفظ‌شده', null, 1, ['code' => 'KEEP-1']);

        $this->get(route('admin.product-exports.index', ['product_ids' => [$product->id]]))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('name="product_ids[]"', false)
            ->assertSee('value="'.$product->id.'"', false)
            ->assertSee('data-products-search-url', false);
    }

    public function test_print_receives_the_same_product_ids_and_meta(): void
    {
        $this->signIn();
        $selected = $this->product('محصول چاپ انتخابی');
        $outside = $this->product('محصول چاپ خارج');

        $this->get(route('admin.product-exports.print', ['product_ids' => [$selected->id]]))
            ->assertOk()
            ->assertSee($selected->name)
            ->assertDontSee($outside->name)
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$selected->id])
            ->assertViewHas('meta', fn (array $meta) => $meta['selected_products'] === $selected->name && $meta['selected_products_count'] === 1);
    }

    public function test_print_is_html_and_does_not_expose_mpdf_output(): void
    {
        $this->signIn();

        $response = $this->get(route('admin.product-exports.print'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('<!doctype html>', false)
            ->assertDontSee('%PDF-', false)
            ->assertDontSee('Mpdf', false);

        $this->assertStringNotContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_meta_reports_all_names_or_selected_count(): void
    {
        $products = collect([
            $this->product('محصول متا اول'),
            $this->product('محصول متا دوم'),
            $this->product('محصول متا سوم'),
            $this->product('محصول متا چهارم'),
        ]);
        $service = app(ProductExportService::class);

        $all = $service->meta(['product_ids' => [], 'stock_status' => 'all']);
        $two = $service->meta(['product_ids' => $products->take(2)->pluck('id')->all(), 'stock_status' => 'all']);
        $four = $service->meta(['product_ids' => $products->pluck('id')->all(), 'stock_status' => 'all']);

        $this->assertSame('همه محصولات', $all['selected_products']);
        $this->assertSame('محصول متا اول، محصول متا دوم', $two['selected_products']);
        $this->assertSame('4 محصول انتخاب‌شده', $four['selected_products']);
    }

    private function signIn(bool $withPermission = true): void
    {
        $role = Role::findOrCreate('product-export-test', 'web');
        $permission = Permission::findOrCreate('products.export', 'web');
        if ($withPermission) {
            $role->givePermissionTo($permission);
        }

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    private function product(
        string $name,
        ?Category $category = null,
        int $stock = 1,
        array $attributes = []
    ): Product {
        return Product::create(array_merge([
            'name' => $name,
            'category_id' => $category?->id,
            'price' => 1000,
            'stock' => $stock,
            'is_sellable' => true,
        ], $attributes));
    }

    private function variant(
        Product $product,
        ModelList $model,
        string $name,
        int $stock = 1,
        array $attributes = []
    ): ProductVariant {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'model_list_id' => $model->id,
            'variant_name' => $name,
            'sell_price' => 2000,
            'stock' => $stock,
            'is_active' => true,
            'sales_enabled' => true,
        ], $attributes));
    }

    private function createIsolatedSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('key')->nullable();
            $table->string('group')->nullable();
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->primary(['user_id', 'permission_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
        });

        Schema::create('model_lists', function (Blueprint $table) {
            $table->id();
            $table->string('brand')->nullable();
            $table->string('model_name');
            $table->string('code')->nullable();
            $table->timestamps();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('hex_code')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('short_barcode')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->integer('stock')->default(0);
            $table->json('models')->nullable();
            $table->boolean('has_colors')->default(false);
            $table->boolean('is_sellable')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('model_list_id')->nullable();
            $table->unsignedBigInteger('color_id')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('variety_name')->nullable();
            $table->string('variety_code')->nullable();
            $table->string('variant_code')->nullable();
            $table->string('unique_key')->nullable();
            $table->unsignedBigInteger('sell_price')->nullable();
            $table->integer('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('sales_enabled')->default(true);
            $table->timestamps();
        });
    }
}
