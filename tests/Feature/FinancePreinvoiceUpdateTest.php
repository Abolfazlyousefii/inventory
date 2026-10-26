<?php

use App\Models\CustomerLedger;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\PreinvoiceOrderReview;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\RoutePermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('rejects changes to quantity or unit price from the discounts-only finance screen', function (string $intent, string $field, int $value) {
    ['product'=>$product,'variant'=>$variant,'order'=>$order,'item'=>$item] = financeEditableOrderFixture();
    $row = ['id'=>$item->id,'quantity'=>2,'price'=>10_000_000];
    $row[$field] = $value;
    $payload = ['finance_version'=>\App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items'=>[$row], 'product_discounts'=>[], 'invoice_discount_type'=>'none', 'invoice_discount_value'=>0,
        'edit_reason'=>'تست محدودیت مالی','intent'=>$intent];
    $this->actingAs(financeEditorUser());
    $response = $intent === 'save' ? $this->put(route('preinvoice.draft.finance.update',$order->uuid),$payload)
        : $this->post(route('preinvoice.draft.finance.save-and-finalize',$order->uuid),$payload);
    $response->assertSessionHasErrors('items');
    expect($item->fresh()->quantity)->toBe(2)->and($item->fresh()->price)->toBe(10_000_000)
        ->and($variant->fresh()->stock)->toBe(50)->and(Invoice::count())->toBe(0)
        ->and(PreinvoiceOrderReview::count())->toBe(0);
})->with([
    ['save','quantity',3],['save','quantity',1],['save','price',11_000_000],
    ['save_and_finalize','quantity',3],['save_and_finalize','quantity',1],['save_and_finalize','price',11_000_000],
]);

it('shows a single green review link in the queue without approving directly', function () {
    ['order'=>$order] = financeEditableOrderFixture();
    $html = view('preinvoice.partials.finance-actions',['o'=>$order,'isExpired'=>false])->render();
    expect($html)->toContain('مشاهده و تأیید')->toContain('btn-success')
        ->not->toContain('ویرایش مالی')->not->toContain('method="POST"')
        ->and(substr_count($html,route('preinvoice.draft.finance',$order->uuid)))->toBe(1);
});

it('rolls back financial edits when a staged payment fails validation during approval', function () {
    ['product' => $product, 'variant' => $variant, 'order' => $order, 'item' => $item] = financeEditableOrderFixture();
    $this->mock(\App\Services\PreinvoiceReservationService::class, function ($mock) {
        $mock->shouldReceive('assertFinanceApprovable')->andReturnNull();
    });
    $before = $item->getAttributes();
    $response = $this->actingAs(financeEditorUser())->post(route('preinvoice.draft.finance.save-and-finalize', $order->uuid), [
        'finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items' => [['id' => $item->id, 'quantity' => 2, 'price' => 10_000_000]],
        'product_discounts' => [['product_id' => $product->id, 'type' => 'amount', 'value' => 0]],
        'invoice_discount_type' => 'none', 'invoice_discount_value' => 0,
        'edit_reason' => 'اصلاح قیمت و دریافت', 'intent' => 'save_and_finalize',
        'payments' => [['method' => 'cash', 'amount' => 1000, 'paid_at' => 'invalid-date']],
    ]);
    $response->assertSessionHasErrors('payments.0.paid_at');
    expect($item->fresh()->price)->toBe((int) $before['price'])
        ->and($order->fresh()->total_price)->toBe(19_500_000)
        ->and($variant->fresh()->stock)->toBe(50)
        ->and($product->fresh()->price)->toBe(12_000_000)
        ->and(Invoice::count())->toBe(0)
        ->and(CustomerLedger::count())->toBe(0)
        ->and(PreinvoiceOrderReview::count())->toBe(0);
});

it('opens the unified initial finance review with accessible numeric fields and staged payments', function () {
    ['order' => $order] = financeEditableOrderFixture();
    $response = $this->actingAs(financeEditorUser())->get(route('preinvoice.draft.finance', $order->uuid))
        ->assertOk()->assertSee('financeEditForm', false)->assertSee('financePaymentComposer', false)
        ->assertSee('تخفیف روی مجموع این کالا')->assertDontSee('type="number"', false)
        ->assertDontSee('افزودن / حذف کالا')->assertDontSee('مشاهده و ویرایش')
        ->assertSee('type="hidden" class="js-qty"', false)->assertSee('type="hidden" class="js-price"', false);
    if (getenv('FINANCE_UI_QA_RENDER')) {
        file_put_contents(storage_path('app/finance-review-qa.html'), $response->getContent());
    }
});

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

function financeEditorUser(): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('finance', 'web');
    $permission = Permission::query()->where('key', 'page.sales.preinvoice_finance_review')->first()
        ?? Permission::findOrCreate('page.sales.preinvoice_finance_review', 'web');
    if ($permission->key !== 'page.sales.preinvoice_finance_review') {
        $permission->forceFill(['key' => 'page.sales.preinvoice_finance_review'])->save();
    }
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    return $user;
}

function financeEditableOrderFixture(): array
{
    $category = Category::query()->create(['name' => 'Finance category '.uniqid()]);

    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Finance product',
        'sku' => 'FIN-PRODUCT-'.uniqid(),
        'stock' => 50,
        'reserved' => 0,
        'price' => 12_000_000,
    ]));

    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Default',
        'variant_code' => 'FIN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'sell_price' => 12_000_000,
        'stock' => 50,
        'reserved' => 0,
    ]));

    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'finance-edit-'.uniqid(),
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'مشتری مالی',
        'customer_mobile' => '09120000000',
        'shipping_price' => 0,
        'discount_amount' => 500_000,
        'total_price' => 19_500_000,
        'discount_allocation_mode' => 'product_lines',
    ]);

    $item = PreinvoiceOrderItem::query()->create([
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 2,
        'price' => 10_000_000,
        'line_discount_amount' => 500_000,
        'line_total' => 19_500_000,
    ]);

    return compact('product', 'variant', 'order', 'item');
}

it('saves finance edits behaviorally without finalizing or creating invoices', function () {
    ['product' => $product, 'order' => $order, 'item' => $item] = financeEditableOrderFixture();
    $user = financeEditorUser();
    $oldStatus = $order->status;

    $response = $this->actingAs($user)->put(route('preinvoice.draft.finance.update', $order->uuid), [
        'intent' => 'save',
        'action' => 'save',
        'finance_version' => \App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items' => [[
            'id' => $item->id,
            'quantity' => 2,
            'price' => '10,000,000',
        ]],
        'product_discounts' => [[
            'product_id' => $product->id,
            'type' => 'percent',
            'value' => '10',
        ]],
        'invoice_discount_type' => 'percent',
        'invoice_discount_value' => '5',
        'edit_reason' => 'اصلاح تست مالی',
    ]);

    $response->assertRedirect(route('preinvoice.draft.finance.edit', $order->uuid));

    $item->refresh();
    $order->refresh();

    expect($item->quantity)->toBe(2)
        ->and($item->price)->toBe(10_000_000)
        ->and($item->line_discount_amount)->toBe(2_000_000)
        ->and($item->line_total)->toBe(18_000_000)
        ->and($order->status)->toBe($oldStatus)
        ->and($order->product_discount_amount)->toBe(2_000_000)
        ->and($order->invoice_discount_type)->toBe('percent')
        ->and((float) $order->invoice_discount_value)->toBe(5.0)
        ->and($order->invoice_discount_amount)->toBe(900_000)
        ->and($order->discount_amount)->toBe(2_900_000)
        ->and($order->total_price)->toBe(17_100_000)
        ->and($order->discount_breakdown['groups'][0]['discount_type'])->toBe('percent')
        ->and($order->discount_breakdown['groups'][0]['discount_value'])->toBe(10)
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceItem::query()->count())->toBe(0)
        ->and(CustomerLedger::query()->count())->toBe(0)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->count())->toBe(0)
        ->and(PreinvoiceOrderReview::query()->where('preinvoice_order_id', $order->id)->where('action', 'finance_edited')->exists())->toBeTrue();
});

it('keeps request intent and action contracts aligned with save and save_and_finalize', function () {
    $rules = (new App\Http\Requests\FinanceUpdatePreinvoiceRequest())->rules();

    expect($rules['intent'])->toContain('in:save,save_and_finalize')
        ->and($rules['action'])->toContain('in:save,save_and_finalize');
});

it('preserves staged payments after saving only without posting them or changing catalog prices', function () {
    ['product'=>$product,'variant'=>$variant,'order'=>$order,'item'=>$item] = financeEditableOrderFixture();
    $payments = [['method'=>'cash','amount'=>1000,'paid_at'=>'2026-10-26']];
    $payload = ['finance_version'=>\App\Support\FinanceDocumentVersion::token($order->fresh('items')),
        'items'=>[['id'=>$item->id,'quantity'=>2,'price'=>10_000_000]],'product_discounts'=>[],
        'invoice_discount_type'=>'none','invoice_discount_value'=>0,'edit_reason'=>'ذخیره بدون تأیید',
        'intent'=>'save','payments'=>$payments];
    $this->actingAs(financeEditorUser())->put(route('preinvoice.draft.finance.update',$order->uuid),[
        'finance_payload'=>json_encode($payload),'finance_payload_item_count'=>1,
    ])->assertSessionHasNoErrors()->assertSessionHasInput('payments',$payments);
    expect(\App\Models\InvoicePayment::count())->toBe(0)
        ->and($order->fresh()->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)
        ->and($product->fresh()->price)->toBe(12_000_000)
        ->and($variant->fresh()->sell_price)->toBe(12_000_000);
});
