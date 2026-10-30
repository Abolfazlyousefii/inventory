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

final class StocktakeOpenCategorySalesTest extends TestCase
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

    private function freezeThreeCategories(Category $category, User $admin): void
    {
        $second = Category::create(['name'=>'Closed headphones']);
        $third = Category::create(['name'=>'Closed watches']);
        app(InventoryCountFreezeService::class)->activate([$category->id, $second->id, $third->id], false, $admin);
    }

    private function assertOfficialReservationThenApprove(PreinvoiceOrder $order, array $row, User $finance, bool $useFinanceEditor = false): void
    {
        $this->assertSame('pending_finance', $order->fresh()->status);
        $reservation = PreinvoiceDraftReservation::where('preinvoice_order_id', $order->id)
            ->where('reservation_scope', 'official')->whereNull('released_at')->sole();
        $this->assertSame((int) $row['id'], (int) $reservation->product_id);
        $this->assertSame(2, (int) $reservation->quantity);
        $this->assertSame(998, (int) WarehouseStock::where('product_variant_id', $row['variety_id'])->value('quantity'));
        $payload = ['finance_version'=>\App\Support\FinanceDocumentVersion::token($order->fresh('items'))];
        if ($useFinanceEditor) {
            $state = app(\App\Services\PreinvoiceDiscountHydrator::class)
                ->hydrateForEditing($order->fresh(['items.product', 'items.variant']));
            $payload += [
                'items'=>[['id'=>$order->items()->sole()->id, 'quantity'=>2, 'price'=>$row['price']]],
                'product_discounts'=>collect($state['product_groups'])->map(fn ($group) => [
                    'product_id'=>$group['product_id'], 'type'=>$group['discount_type'], 'value'=>$group['discount_value'],
                ])->all(),
                'invoice_discount_type'=>$state['invoice_discount']['type'],
                'invoice_discount_value'=>$state['invoice_discount']['value'], 'intent'=>'save_and_finalize',
            ];
        }
        $route = $useFinanceEditor ? 'preinvoice.draft.finance.save-and-finalize' : 'preinvoice.draft.finalize';
        $this->actingAs($finance)->post(route($route, $order->uuid), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $invoice = \App\Models\Invoice::where('preinvoice_order_id', $order->id)->sole();
        $this->assertSame('converted_to_invoice', $order->fresh()->status);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame((int) $row['id'], (int) $invoice->items()->sole()->product_id);
        $this->assertSame(2, (int) $invoice->items()->sole()->quantity);
        $this->assertSame('consumed', $reservation->fresh()->release_reason);
        $this->assertNotNull($reservation->fresh()->converted_at);
        $this->assertSame(998, (int) WarehouseStock::where('product_variant_id', $row['variety_id'])->value('quantity'));
    }

    public function test_open_category_submits_and_finance_creates_real_invoice_while_three_categories_are_frozen(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $historical = \App\Models\Invoice::withoutEvents(fn () => \App\Models\Invoice::create([
            'uuid'=>'HISTORICAL-COLLECTION', 'status'=>\App\Models\Invoice::STATUS_PENDING_COLLECTION,
            'customer_name'=>'Historical warehouse customer', 'subtotal'=>2002, 'total'=>2002,
        ]));
        $historical->items()->create([
            'product_id'=>$rows[0]['id'], 'variant_id'=>$rows[0]['variety_id'],
            'quantity'=>2, 'price'=>1001, 'line_total'=>2002,
        ]);
        $historicalBefore = $historical->fresh('items')->toJson();
        $this->freezeThreeCategories($category, $admin);
        $this->actingAs($seller)->post(route('preinvoice.draft.save'), largePayloadPost([$rows[1]]))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $order = PreinvoiceOrder::where('created_by', $seller->id)->sole();
        $this->assertOfficialReservationThenApprove($order, $rows[1], $admin, true);
        $this->assertSame($historicalBefore, $historical->fresh('items')->toJson());
        $this->assertTrue(app(InventoryCountFreezeService::class)->blocked($rows[0]['id']));
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
    }

    public function test_removing_closed_rows_from_existing_mixed_draft_allows_normal_submission_and_finance_approval(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $order = largePayloadOrder($seller, $rows);
        $openItem = $order->items()->where('product_id', $rows[1]['id'])->sole();
        $remaining = $rows[1];
        $remaining['item_id'] = $openItem->id;
        $this->freezeThreeCategories($category, $admin);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertSame(2, $order->items()->count());
        $this->actingAs($seller)->put(route('preinvoice.draft.update', $order->uuid), largePayloadPost([$remaining]))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $this->assertSame(1, $order->items()->count());
        $this->assertOfficialReservationThenApprove($order, $remaining, $admin);
        $this->assertTrue(app(InventoryCountFreezeService::class)->blocked($rows[0]['id']));
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
        $this->assertSame(0, PreinvoiceDraftReservation::where('product_id', $rows[0]['id'])->whereNull('released_at')->count());
    }

    public function test_stale_finance_request_for_closed_category_is_denied_with_category_name_and_no_invoice(): void
    {
        [$seller, $admin, $category, $rows] = $this->fixture();
        $this->actingAs($seller)->post(route('preinvoice.draft.save'), largePayloadPost([$rows[0]]))
            ->assertSessionHasNoErrors();
        $order = PreinvoiceOrder::where('created_by', $seller->id)->sole();
        $reviewedVersion = \App\Support\FinanceDocumentVersion::token($order->fresh('items'));
        $this->freezeThreeCategories($category, $admin);
        $response = $this->actingAs($admin)->postJson(route('preinvoice.draft.finalize', $order->uuid), [
            'finance_version'=>$reviewedVersion,
        ])->assertUnprocessable();
        $this->assertStringContainsString($category->name, json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE));
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertFalse($order->invoice()->exists());
        $this->assertSame(1000, (int) WarehouseStock::where('product_variant_id', $rows[0]['variety_id'])->value('quantity'));
        $this->assertSame(0, PreinvoiceDraftReservation::whereNull('released_at')->count());
    }
}

