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

final class StocktakeFreezeSalesLifecycleTest extends TestCase
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
        $rows = largePayloadProducts(2);
        $category = Product::findOrFail($rows[0]['id'])->category;
        $other = Category::create(['name'=>'Unblocked accessories']);
        Product::withoutEvents(fn () => Product::findOrFail($rows[1]['id'])->update(['category_id'=>$other->id]));
        return [$seller, $admin, $category, $rows];
    }

    public function test_finance_pending_order_without_reservation_rows_returns_to_draft_with_named_category_reason(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, $rows);
        $order->update(['status'=>'pending_finance']);
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(2, $order->items()->count());
        $review = $order->reviews()->where('action', 'stocktake_freeze')->sole();
        $this->assertStringContainsString($category->name, $review->reason);
        $this->assertSame(0, PreinvoiceDraftReservation::count());
    }

    public function test_freeze_notifies_seller_and_finance_about_disabled_category_and_returned_draft(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, $rows);
        $order->update(['status'=>'pending_finance']);
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $notifications = \App\Models\SystemNotification::where('type', 'inventory_count_freeze')
            ->where('notifiable_type', PreinvoiceOrder::class)->where('notifiable_id', $order->id)->get();
        $sellerNotice = $notifications->firstWhere('user_id', $seller->id);
        $financeNotice = $notifications->firstWhere('role', 'finance');
        $this->assertNotNull($sellerNotice);
        $this->assertNotNull($financeNotice);
        $this->assertStringContainsString($category->name, $sellerNotice->message);
        $this->assertStringContainsString($category->name, $financeNotice->message);
        $this->assertSame('draft', $order->fresh()->status);
    }

    public function test_category_close_leaves_unrelated_finance_order_and_items_untouched(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, [$rows[1]]);
        $order->update(['status'=>'pending_finance']);
        $before = $order->fresh()->toJson();
        $itemsBefore = $order->items()->get()->toJson();
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $this->assertSame($before, $order->fresh()->toJson());
        $this->assertSame($itemsBefore, $order->items()->get()->toJson());
        $this->assertSame(0, $order->reviews()->count());
        $this->assertSame(0, \App\Models\SystemNotification::where('type', 'inventory_count_freeze')
            ->where('notifiable_id', $order->id)->count());
    }

    public function test_stale_seller_submit_after_category_closes_preserves_draft_and_names_disabled_category(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, $rows);
        $before = $order->items()->orderBy('id')->get(['product_id', 'variant_id', 'quantity', 'price'])->toJson();
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $response = $this->actingAs($seller)->put(route('preinvoice.draft.update', $order->uuid), largePayloadPost($rows));
        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($message) => str_contains($message, $category->name));
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame($before, $order->items()->orderBy('id')->get(['product_id', 'variant_id', 'quantity', 'price'])->toJson());
        $this->assertSame(0, PreinvoiceDraftReservation::where('product_id', $rows[0]['id'])->whereNull('released_at')->count());
        $this->assertSame(0, PreinvoiceDraftReservation::where('reservation_scope', 'official')->whereNull('released_at')->count());
        $this->assertSame(998, (int) WarehouseStock::where('product_variant_id', $rows[1]['variety_id'])->value('quantity'));
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
    }

    public function test_new_submission_with_closed_category_saves_all_rows_as_draft_and_reserves_only_open_category(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $this->actingAs($seller)->post(route('preinvoice.draft.save'), largePayloadPost($rows))
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, $category->name));
        $order = PreinvoiceOrder::where('created_by', $seller->id)->sole();
        $this->assertSame('draft', $order->status);
        $this->assertSame(2, $order->items()->count());
        $this->assertEqualsCanonicalizing(array_column($rows, 'id'), $order->items()->pluck('product_id')->all());
        $this->assertSame(0, PreinvoiceDraftReservation::where('reservation_scope', 'official')->whereNull('released_at')->count());
        $this->assertSame(0, PreinvoiceDraftReservation::where('product_id', $rows[0]['id'])->whereNull('released_at')->count());
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
        $this->assertSame(998, (int) WarehouseStock::where('product_variant_id', $rows[1]['variety_id'])->value('quantity'));
        $this->assertFalse($order->invoice()->exists());
        $this->assertSame(0, \App\Models\SystemNotification::where('type', 'preinvoice_submitted')->count());
    }

    public function test_retrying_previously_submitted_token_after_freeze_redirects_to_existing_draft_with_warning(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $payload = largePayloadPost($rows);
        $this->actingAs($seller)->post(route('preinvoice.draft.save'), $payload)->assertSessionHasNoErrors();
        $order = PreinvoiceOrder::where('created_by', $seller->id)->sole();
        $this->assertSame('pending_finance', $order->status);
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        $this->assertSame('draft', $order->fresh()->status);
        $before = $order->items()->orderBy('id')->get()->toJson();
        $noticesBefore = \App\Models\SystemNotification::where('type', 'preinvoice_submitted')->count();
        $this->post(route('preinvoice.draft.save'), $payload)
            ->assertRedirect(route('preinvoice.draft.edit', $order->uuid))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, $category->name));
        $this->assertSame(1, PreinvoiceOrder::where('created_by', $seller->id)->count());
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame($before, $order->items()->orderBy('id')->get()->toJson());
        $this->assertSame(0, PreinvoiceDraftReservation::whereNull('released_at')->count());
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
        $this->assertSame($noticesBefore, \App\Models\SystemNotification::where('type', 'preinvoice_submitted')->count());
    }

    public function test_stale_finance_approval_reports_closed_category_before_any_conversion(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, $rows);
        $order->update(['status'=>'pending_finance']);
        $stale = $order->fresh();
        app(InventoryCountFreezeService::class)->activate([$category->id], false, $admin);
        try {
            app(PreinvoiceReservationService::class)->assertFinanceApprovable($stale, $admin);
            $this->fail('Finance approved a closed category');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($category->name, $e->getMessage());
        }
        $this->assertFalse($order->invoice()->exists());
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
    }

    public function test_count_finalization_keeps_category_closed_until_explicit_reopen_and_other_categories_work(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $freeze = app(InventoryCountFreezeService::class);
        $freeze->activate([$category->id], false, $admin);
        $service = app(StockCountDocumentService::class);
        $document = $service->createProductDraft(['product_id'=>$rows[0]['id'], 'document_date'=>'2026-10-30',
            'actual_quantities'=>[$rows[0]['variety_id']=>995]], $admin->id);
        $service->finalize($document, $admin->id, true);
        $this->assertSame('finalized', $document->fresh()->status);
        $this->assertTrue($freeze->blocked($rows[0]['id']));
        $this->assertFalse($freeze->blocked($rows[1]['id']));
        $freeze->assertProduct($rows[1]['id']);
        try {
            $freeze->assertProduct($rows[0]['id']);
            $this->fail('Finalizing a stock count reopened sales');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stocktake', $e->errors());
        }
        $freeze->deactivate($admin);
        $freeze->assertProduct($rows[0]['id']);
        $this->assertFalse($freeze->blocked($rows[0]['id']));
        $this->assertSame(995, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
    }
}
