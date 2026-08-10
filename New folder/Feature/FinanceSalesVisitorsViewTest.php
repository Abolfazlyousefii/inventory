<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_report_has_filters_active_submit_and_designed_empty_state(): void
    {
        $response = $this->actingAs($this->owner())
            ->get(route('finance.reports.sales-visitors'));

        $response->assertOk()
            ->assertSee('گزارش فروش ویزیتورها')
            ->assertSee('name="user_id"', false)
            ->assertSee('name="customer_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertSee('type="submit"', false)
            ->assertDontSee('type="submit" disabled', false)
            ->assertSee('برای فیلترهای انتخاب‌شده گزارشی یافت نشد.')
            ->assertSee('بازه تاریخ یا فروشنده را تغییر دهید و دوباره جست‌وجو کنید.')
            ->assertSee('id="appSidebar"', false);
    }

    public function test_report_with_data_has_expected_columns_and_totals_footer(): void
    {
        $seller = User::factory()->create(['name' => 'فروشنده تست', 'is_seller' => true]);
        $customer = Customer::query()->create([
            'first_name' => 'مشتری',
            'last_name' => 'تست',
            'mobile' => '09120000002',
        ]);

        Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => PreinvoiceOrder::query()->create([
                'uuid' => fake()->unique()->uuid(),
                'created_by' => $seller->id,
                'seller_id' => $seller->id,
                'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                'customer_name' => $customer->display_name,
                'customer_mobile' => $customer->mobile,
                'customer_address' => 'تهران',
                'province_id' => 1,
                'shipping_id' => 0,
            ])->id,
            'seller_id' => $seller->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->display_name,
            'subtotal' => 250000,
            'total' => 250000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        $this->actingAs($this->owner())
            ->get(route('finance.reports.sales-visitors'))
            ->assertOk()
            ->assertSee('نام فروشنده')
            ->assertSee('تعداد فاکتور')
            ->assertSee('تعداد مشتری')
            ->assertSee('جمع فروش')
            ->assertSee('جمع پرداخت‌شده')
            ->assertSee('مانده')
            ->assertSee('جمع کل')
            ->assertSee('فروشنده تست');
    }

    public function test_user_without_an_allowed_finance_role_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('finance.reports.sales-visitors'))
            ->assertForbidden();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
