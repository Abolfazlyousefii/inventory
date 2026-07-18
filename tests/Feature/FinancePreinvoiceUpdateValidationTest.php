<?php

use App\Http\Requests\FinanceUpdatePreinvoiceRequest;
use App\Models\Category;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\MessageBag;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function financeValidationUser(): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('finance', 'web');
    $user->assignRole($role);

    return $user;
}

function financeValidationOrderFixture(): PreinvoiceOrder
{
    $category = Category::query()->create(['name' => 'Validation category '.uniqid()]);

    $product = Product::withoutEvents(fn () => Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Validation product',
        'sku' => 'VALIDATION-PRODUCT-'.uniqid(),
        'stock' => 10,
        'reserved' => 0,
        'price' => 1_000_000,
    ]));

    $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'sales_enabled' => true,
        'variant_name' => 'Default',
        'variant_code' => 'VALIDATION-VARIANT-'.uniqid(),
        'sell_price' => 1_000_000,
        'stock' => 10,
        'reserved' => 0,
    ]));

    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'finance-validation-'.uniqid(),
        'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,
        'customer_name' => 'مشتری اعتبارسنجی',
        'shipping_price' => 0,
        'discount_allocation_mode' => 'product_lines',
        'total_price' => 1_000_000,
    ]);

    PreinvoiceOrderItem::query()->create([
        'preinvoice_order_id' => $order->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'quantity' => 1,
        'price' => 1_000_000,
        'line_total' => 1_000_000,
        'line_discount_amount' => 0,
    ]);

    return $order;
}

it('defines Persian edit reason validation messages', function () {
    $request = new FinanceUpdatePreinvoiceRequest();

    expect($request->rules()['edit_reason'])->toContain('required', 'string', 'min:3', 'max:1000')
        ->and($request->messages()['edit_reason.required'])->toBe('لطفاً دلیل ویرایش مالی را وارد کنید.')
        ->and($request->messages()['edit_reason.min'])->toBe('دلیل ویرایش مالی باید حداقل ۳ کاراکتر باشد.')
        ->and($request->attributes()['edit_reason'])->toBe('دلیل ویرایش مالی');
});

it('renders edit reason field attributes old input and one validation message', function () {
    $order = financeValidationOrderFixture();
    $errors = (new ViewErrorBag())->put('default', new MessageBag([
        'edit_reason' => ['لطفاً دلیل ویرایش مالی را وارد کنید.'],
    ]));

    $response = $this->actingAs(financeValidationUser())
        ->withSession([
            '_old_input' => ['edit_reason' => 'علت قبلی تست'],
            'errors' => $errors,
        ])->get(route('preinvoice.draft.finance.edit', $order->uuid));

    $response->assertOk()
        ->assertSee('id="edit_reason"', false)
        ->assertSee('name="edit_reason"', false)
        ->assertSee('required minlength="3" maxlength="1000"', false)
        ->assertSee('علت قبلی تست', false)
        ->assertSee('لطفاً دلیل ویرایش مالی را وارد کنید.', false);

    expect(substr_count($response->getContent(), 'لطفاً دلیل ویرایش مالی را وارد کنید.'))->toBe(1);
});

it('redirects a successful finance edit back to the finance edit page without finalize coupling', function () {
    $order = financeValidationOrderFixture();
    $item = $order->items()->firstOrFail();

    $response = $this->actingAs(financeValidationUser())->put(route('preinvoice.draft.finance.update', $order->uuid), [
        'intent' => 'save',
        'action' => 'save',
        'items' => [[
            'id' => $item->id,
            'quantity' => 1,
            'price' => '1,000,000',
        ]],
        'product_discounts' => [[
            'product_id' => $item->product_id,
            'type' => 'amount',
            'value' => '0',
        ]],
        'invoice_discount_type' => 'none',
        'invoice_discount_value' => '0',
        'edit_reason' => 'ذخیره تست اعتبارسنجی',
    ]);

    $response->assertRedirect(route('preinvoice.draft.finance.edit', $order->uuid));
});
