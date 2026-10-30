<?php

namespace Tests\Feature;

use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\{Category, PreinvoiceDraftReservation, PreinvoiceOrder, Product, ProductVariant, StockCountDocument, User, WarehouseStock};
use App\Services\{InventoryCountFreezeService, PreinvoiceReservationService, StockCountDocumentService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

final class StocktakeSalesWarningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->withoutMiddleware(RoutePermissionMiddleware::class);
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_bundle')->default(false);
            $t->string('bundle_stock_mode')->default('physical');
        });
        Schema::create('product_bundle_components', function (Blueprint $t) {
            $t->id(); $t->integer('bundle_variant_id'); $t->integer('component_variant_id');
            $t->integer('quantity'); $t->timestamps();
        });
        Schema::create('bundle_component_allocations', function (Blueprint $t) {
            $t->id(); $t->integer('bundle_variant_id'); $t->integer('component_variant_id');
            $t->integer('remaining'); $t->timestamps();
        });
        $seller = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        $rows = largePayloadProducts(3);
        $category = Product::findOrFail($rows[0]['id'])->category;
        $secondClosed = Category::create(['name'=>'Closed headphones']);
        Product::withoutEvents(fn () => Product::findOrFail($rows[1]['id'])->update(['category_id'=>$secondClosed->id]));
        $other = Category::create(['name'=>'Unblocked accessories']);
        Product::withoutEvents(fn () => Product::findOrFail($rows[2]['id'])->update(['category_id'=>$other->id]));
        app(InventoryCountFreezeService::class)->activate([$category->id, $secondClosed->id], false, $admin);
        return [$seller, $admin, [$category, $secondClosed], $rows];
    }

    private function payload(array $rows): array
    {
        return ['reservation_token'=>(string) \Illuminate\Support\Str::uuid(),
            'customer_name'=>'Mixed category customer', 'customer_mobile'=>'09120000000', 'products'=>$rows];
    }

    private function assertWarning(?array $warning, array $closed, array $categories): void
    {
        $this->assertNotNull($warning);
        $this->assertEqualsCanonicalizing(array_column($closed, 'id'), $warning['blocked_product_ids']);
        $names = Product::whereIn('id', array_column($closed, 'id'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing($names, $warning['blocked_product_names']);
        $this->assertEqualsCanonicalizing(array_map(fn ($c) => $c->name, $categories), $warning['category_names']);
        foreach ($names as $name) $this->assertStringContainsString($name, $warning['message']);
        foreach ($categories as $category) $this->assertStringContainsString($category->name, $warning['message']);
    }

    private function assertOnlyOpenProductReserved(array $rows): void
    {
        foreach (array_slice($rows, 0, 2) as $closed) {
            $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $closed['variety_id'])->value('quantity'));
            $this->assertSame(0, PreinvoiceDraftReservation::where('product_id', $closed['id'])->whereNull('released_at')->count());
        }
        $this->assertSame(998, (int) WarehouseStock::where('product_variant_id', $rows[2]['variety_id'])->value('quantity'));
        $this->assertSame(0, PreinvoiceDraftReservation::where('reservation_scope', 'official')->whereNull('released_at')->count());
        $this->assertSame(0, \App\Models\Invoice::count());
        $this->assertSame(0, \App\Models\SystemNotification::where('type', 'preinvoice_submitted')->count());
    }

    public function test_mixed_autosave_preserves_all_draft_rows_and_lists_every_closed_product_and_category(): void
    {
        [$seller, , $categories, $rows] = $this->fixture();
        $response = $this->actingAs($seller)->postJson(route('preinvoice.autosave'), $this->payload($rows))->assertOk();
        $this->assertWarning($response->json('stocktake_warning'), array_slice($rows, 0, 2), $categories);
        $order = PreinvoiceOrder::where('uuid', $response->json('uuid'))->sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame(3, $order->items()->count());
        $this->assertEqualsCanonicalizing(array_column($rows, 'id'), $order->items()->pluck('product_id')->all());
        $this->assertOnlyOpenProductReserved($rows);
    }

    public function test_sync_lists_all_closed_products_then_clears_warning_when_only_open_rows_remain(): void
    {
        [$seller, , $categories, $rows] = $this->fixture();
        $token = (string) \Illuminate\Support\Str::uuid();
        $payload = ['reservation_token'=>$token, 'submission_token'=>$token,
            'items'=>array_map(fn ($row) => ['product_id'=>$row['id'], 'variant_id'=>$row['variety_id'], 'quantity'=>2], $rows)];
        $response = $this->actingAs($seller)->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk();
        $this->assertWarning($response->json('stocktake_warning'), array_slice($rows, 0, 2), $categories);
        $this->assertOnlyOpenProductReserved($rows);
        $payload['items'] = [$payload['items'][2]];
        $this->postJson(route('preinvoice.api.reservations.sync'), $payload)->assertOk()
            ->assertJsonStructure(['stocktake_warning'])->assertJsonPath('stocktake_warning', null);
        $this->assertOnlyOpenProductReserved($rows);
    }

    public function test_manual_removal_from_saved_mixed_draft_clears_warning_after_snapshot_confirmation(): void
    {
        [$seller, , $categories, $rows] = $this->fixture();
        $payload = $this->payload($rows);
        $saved = $this->actingAs($seller)->postJson(route('preinvoice.autosave'), $payload)->assertOk()->json();
        $this->assertWarning($saved['stocktake_warning'] ?? null, array_slice($rows, 0, 2), $categories);
        $payload['products'] = [$rows[2]];
        $payload += ['draft_uuid'=>$saved['uuid'], 'base_version'=>$saved['version'], 'action'=>'confirm_changes'];
        $challenge = $this->postJson(route('preinvoice.autosave'), $payload)->assertUnprocessable()
            ->assertJsonPath('code', 'snapshot_reduction')->json('confirmation_token');
        $payload['confirmation_token'] = $challenge;
        $this->postJson(route('preinvoice.autosave'), $payload)->assertOk()
            ->assertJsonStructure(['stocktake_warning'])->assertJsonPath('stocktake_warning', null);
        $order = PreinvoiceOrder::where('uuid', $saved['uuid'])->sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame((int) $rows[2]['id'], (int) $order->items()->sole()->product_id);
        $this->assertOnlyOpenProductReserved($rows);
    }

    public function test_open_only_autosave_returns_explicit_null_warning_during_other_category_freezes(): void
    {
        [$seller, , , $rows] = $this->fixture();
        $this->actingAs($seller)->postJson(route('preinvoice.autosave'), $this->payload([$rows[2]]))->assertOk()
            ->assertJsonStructure(['stocktake_warning'])->assertJsonPath('stocktake_warning', null);
        $this->assertOnlyOpenProductReserved($rows);
    }

    public function test_explicit_manual_draft_save_preserves_stale_mixed_rows_and_warns_about_every_closed_product(): void
    {
        [$seller, , $categories, $rows] = $this->fixture();
        $response = $this->actingAs($seller)->post(route('preinvoice.draft.save'), largePayloadPost($rows, ['intent'=>'draft']))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning');
        $warning = session('warning');
        foreach (array_slice($rows, 0, 2) as $row) {
            $this->assertStringContainsString(Product::findOrFail($row['id'])->name, $warning);
        }
        foreach ($categories as $category) $this->assertStringContainsString($category->name, $warning);
        $order = PreinvoiceOrder::where('created_by', $seller->id)->sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame(3, $order->items()->count());
        $this->assertOnlyOpenProductReserved($rows);
    }
}

