<?php

use App\Models\Category;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\CommissionSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionRateTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function commissionUiProduct(): Product
{
    $category = Category::query()->create(['name' => 'UI Commission Category']);

    return Product::query()->create(['name' => 'UI Commission Product', 'sku' => 'UI-COM', 'category_id' => $category->id, 'stock' => 1, 'reserved' => 0, 'price' => 1000]);
}

function commissionPageUser(?string $actionKey = null): User
{
    $page = Permission::query()->where('key', 'page.commercial.commissions')->firstOrFail();
    $role = Role::findOrCreate('CommissionPageRole'.($actionKey ? str_replace('.', '-', $actionKey) : 'Viewer'), 'web');
    $role->givePermissionTo($page);
    if ($actionKey) {
        $action = Permission::query()->where('key', $actionKey)->firstOrFail();
        $role->givePermissionTo($action);
    }
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('enforces page access for guests users and owners', function () {
    $this->get('/commercial/commissions')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/commercial/commissions')->assertForbidden();

    $this->actingAs(commissionPageUser())->get('/commercial/commissions')
        ->assertOk()
        ->assertSee('نمای کلی')
        ->assertSee('نرخ‌ها و کمپین‌ها')
        ->assertSee('اسناد و تسویه')
        ->assertSee('هنوز دسته‌ای برای مدیریت نرخ ثبت نشده است.');

    $owner = User::factory()->create();
    $owner->assignRole(Role::findOrCreate('Owner', 'web'));
    $this->actingAs($owner)->get('/commercial/commissions')->assertOk();
});

it('keeps rate mutations behind their action permission', function () {
    $product = commissionUiProduct();
    $payload = ['target_type' => 'product', 'target_id' => $product->id, 'percentage' => '1.5'];

    $this->actingAs(commissionPageUser())->post(route('commercial.commissions.rates.store'), $payload)->assertForbidden();
    $this->actingAs(commissionPageUser('commissions.manage_rates'))->post(route('commercial.commissions.rates.store'), $payload)
        ->assertRedirect(route('commercial.commissions.index'));
    expect(CommissionRateRevision::query()->firstOrFail()->percentage)->toBe('1.5000');
});

it('separates campaign and period mutations from page access', function () {
    $viewer = commissionPageUser();
    $this->actingAs($viewer)->post(route('commercial.commissions.campaigns.store'), [])->assertForbidden();
    $this->actingAs($viewer)->put(route('commercial.commissions.settings.update'), ['cycle_day' => 5])->assertForbidden();

    $campaignManager = commissionPageUser('commissions.manage_campaigns');
    $this->actingAs($campaignManager)->post(route('commercial.commissions.campaigns.store'), [])->assertSessionHasErrors(['name']);
    $periodManager = commissionPageUser('commissions.manage_periods');
    $this->actingAs($periodManager)->put(route('commercial.commissions.settings.update'), ['cycle_day' => '۵'])
        ->assertRedirect(route('commercial.commissions.index'));
});

it('shows the commercial menu item only through page access', function () {
    $withoutPage = User::factory()->create();
    $this->actingAs($withoutPage)->view('layouts.sidebar')->assertDontSee('پورسانت');
    $this->actingAs(commissionPageUser())->view('layouts.sidebar')->assertSee('پورسانت');
});

it('keeps the lazy rate tree query count bounded as the catalog grows', function () {
    for ($index = 1; $index <= 40; $index++) {
        Category::query()->create(['name' => 'Root '.$index]);
    }
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $nodes = app(CommissionRateTreeService::class)->roots();

    expect($nodes)->toHaveCount(40)
        ->and($queries)->toBeLessThanOrEqual(4);
});

it('searches rate tree categories products and variants without loading the full catalog', function () {
    $category = Category::query()->create(['name' => 'یاقوت دسته']);
    $product = Product::query()->create([
        'name' => 'یاقوت کالا', 'sku' => 'YAGHOOT-P', 'category_id' => $category->id,
        'stock' => 1, 'reserved' => 0, 'price' => 1000,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id, 'variant_name' => 'یاقوت تنوع', 'variant_code' => 'YAGHOOT-V',
        'is_active' => true, 'sales_enabled' => true, 'stock' => 1, 'reserved' => 0, 'sell_price' => 1000,
    ]);

    $items = app(CommissionRateTreeService::class)->search('یاقوت')['items'];

    expect(collect($items)->pluck('type')->all())->toBe(['category', 'product', 'variant'])
        ->and(collect($items)->pluck('label')->all())->toBe(['یاقوت دسته', 'یاقوت کالا', 'یاقوت تنوع']);

    $this->actingAs(commissionPageUser())
        ->getJson(route('commercial.commissions.tree', ['scope' => 'all', 'q' => 'یاقوت']))
        ->assertOk()
        ->assertJsonCount(3, 'items');
});

it('exposes own inherited effective and source values for the rate tree ui', function () {
    $parent = Category::query()->create(['name' => 'Parent']);
    $child = Category::query()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    $product = Product::query()->create(['name' => 'Inherited Product', 'sku' => 'INHERITED', 'category_id' => $child->id, 'stock' => 1, 'reserved' => 0, 'price' => 1000]);
    CommissionRateRevision::query()->create([
        'target_type' => 'category',
        'target_id' => $parent->id,
        'target_key' => "category:{$parent->id}",
        'active_marker' => 1,
        'category_id' => $parent->id,
        'percentage' => '2.5000',
        'effective_from' => now(),
        'created_by' => User::factory()->create()->id,
    ]);

    $childNode = app(CommissionRateTreeService::class)->children('category', $parent->id)['items'][0];
    $productNode = collect(app(CommissionRateTreeService::class)->children('category', $child->id)['items'])->firstWhere('id', $product->id);

    expect($childNode)->toMatchArray([
        'percentage' => '2.5000',
        'own_rate' => null,
        'inherited_rate' => '2.5000',
        'source_type' => 'category',
        'source_id' => $parent->id,
    ])->and($productNode)->toMatchArray([
        'percentage' => '2.5000',
        'own_rate' => null,
        'inherited_rate' => '2.5000',
        'source_type' => 'category',
        'source_id' => $parent->id,
    ]);
});

it('protects recalculation and seller details with action and seller scope permissions', function () {
    $period = CommissionPeriod::query()->create(['label' => 'Access period', 'start_at' => '2026-08-01', 'end_at' => '2026-09-01', 'cycle_day_snapshot' => 10, 'status' => 'open']);
    $seller = commissionPageUser();
    $otherSeller = commissionPageUser();
    CommissionSetting::current()->update(['seller_visibility_enabled' => true]);

    $this->actingAs($seller)->post(route('commercial.commissions.periods.recalculate', $period))->assertForbidden();
    $this->actingAs($seller)->get(route('commercial.commissions.sellers.show', [$period, $seller]))->assertOk();
    $this->actingAs($seller)->get(route('commercial.commissions.sellers.show', [$period, $otherSeller]))->assertForbidden();

    $manager = commissionPageUser('commissions.view_seller_details');
    $this->actingAs($manager)->get(route('commercial.commissions.sellers.show', [$period, $otherSeller]))->assertOk();
    $calculator = commissionPageUser('commissions.recalculate');
    $this->actingAs($calculator)->post(route('commercial.commissions.periods.recalculate', $period))->assertRedirect();
});
