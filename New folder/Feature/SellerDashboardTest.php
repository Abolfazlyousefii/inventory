<?php

namespace Tests\Feature;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SellerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_sees_only_quick_actions_allowed_by_real_permissions(): void
    {
        $seller = $this->userWithPermissions('sales-dashboard', [
            'dashboard.view',
            'preinvoices.create',
            'preinvoices.own.view',
            'customers.view',
        ]);

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ثبت پیش‌فاکتور جدید')
            ->assertSee('پیش‌فاکتورهای من')
            ->assertSee('مشتریان')
            ->assertDontSee('>فاکتورها<', false)
            ->assertDontSee('گزارش‌های مدیریتی');
    }

    public function test_seller_statistics_and_recent_rows_are_restricted_to_seller_id(): void
    {
        Carbon::setTestNow('2026-07-27 10:30:00');
        $sellerA = $this->seller();
        $sellerB = $this->seller('seller-b');

        $this->order($sellerA, PreinvoiceOrder::STATUS_DRAFT, 'مشتری ویژه الف', 1_200_000);
        $this->order($sellerA, PreinvoiceOrder::STATUS_PENDING_FINANCE, 'مشتری دوم الف', 2_300_000);
        $this->order($sellerA, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, 'مشتری سوم الف', 3_400_000);
        $this->order($sellerB, PreinvoiceOrder::STATUS_DRAFT, 'مشتری محرمانه ب', 9_000_000);
        $this->order($sellerB, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, 'مشتری دوم ب', 8_000_000);

        $this->actingAs($sellerA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('مشتری ویژه الف')
            ->assertSee('مشتری دوم الف')
            ->assertSee('مشتری سوم الف')
            ->assertSee(number_format(6_900_000))
            ->assertDontSee('مشتری محرمانه ب')
            ->assertDontSee('مشتری دوم ب')
            ->assertDontSee(number_format(17_000_000));
    }

    public function test_temporary_autosave_is_excluded_from_dashboard_and_my_preinvoices(): void
    {
        $seller = $this->seller();
        $this->order($seller, PreinvoiceOrder::STATUS_DRAFT, 'پیش‌نویس واقعی', 1_000_000);
        $this->order($seller, PreinvoiceOrder::STATUS_DRAFT, 'ذخیره خودکار موقت', 2_000_000, [
            'is_auto_draft' => true,
            'auto_saved_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('پیش‌نویس واقعی')
            ->assertDontSee('ذخیره خودکار موقت');

        $this->actingAs($seller)
            ->get(route('preinvoice.my.index', ['tab' => 'drafts']))
            ->assertOk()
            ->assertSee('پیش‌نویس واقعی')
            ->assertDontSee('ذخیره خودکار موقت');
    }

    public function test_work_item_counts_use_actual_status_constants_and_returned_item_has_priority(): void
    {
        $seller = $this->seller();

        $this->order($seller, PreinvoiceOrder::STATUS_DRAFT, 'پیش‌نویس');
        $this->order($seller, PreinvoiceOrder::STATUS_PENDING_FINANCE, 'منتظر مالی');
        $this->order($seller, PreinvoiceOrder::STATUS_FINANCE_REVIEWING, 'بررسی مالی');
        $this->order($seller, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, 'برگشتی مالی');
        $this->order($seller, PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, 'تبدیل شده');
        $this->order($seller, PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, 'لغو شده');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'برگشتی از مالی برای اصلاح',
                'پیش‌فاکتورهای پیش‌نویس',
                'در انتظار تأیید مالی',
                'در حال بررسی مالی',
                'تأییدشده‌های امروز',
                'لغوشده توسط مالی',
            ])
            ->assertSee('اصلاح')
            ->assertSee('ادامه ثبت');
    }

    public function test_recent_preinvoices_are_limited_to_five_and_use_id_as_tie_breaker(): void
    {
        $seller = $this->seller();
        $sameTime = Carbon::parse('2026-07-27 09:00:00');
        $orders = collect();

        foreach (range(1, 6) as $index) {
            $orders->push($this->order(
                $seller,
                PreinvoiceOrder::STATUS_PENDING_FINANCE,
                "مشتری ترتیب {$index}",
                100_000 * $index,
                ['created_at' => $sameTime, 'updated_at' => $sameTime]
            ));
        }

        $response = $this->actingAs($seller)->get(route('dashboard'))->assertOk();

        $response->assertDontSee('مشتری ترتیب 1');
        $response->assertSeeInOrder([
            'مشتری ترتیب 6',
            'مشتری ترتیب 5',
            'مشتری ترتیب 4',
            'مشتری ترتیب 3',
            'مشتری ترتیب 2',
        ]);
    }

    public function test_empty_state_is_actionable_and_zero_conversion_is_safe(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('هنوز پیش‌فاکتوری ثبت نکرده‌اید.')
            ->assertSee('ثبت اولین پیش‌فاکتور')
            ->assertSee('0٪')
            ->assertDontSee('NaN');
    }

    public function test_page_access_exposes_parent_page_actions_but_not_other_pages(): void
    {
        $user = $this->userWithPermissions('limited-dashboard', [
            'dashboard.view',
            'preinvoices.own.view',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('ثبت اولین پیش‌فاکتور')
            ->assertSee(route('preinvoice.create'), false)
            ->assertDontSee(route('customers.index'), false)
            ->assertDontSee(route('invoices.index'), false);
    }

    public function test_owner_sees_management_reports_below_sales_dashboard_and_monthly_endpoint_works(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['دسترسی‌های اصلی فروش', 'گزارش‌های مدیریتی'])
            ->assertSee('خلاصه فروش')
            ->assertSee('خلاصه انبار')
            ->assertSee('خلاصه مالی')
            ->assertSee('گزارش ماهانه')
            ->assertSee('فعالیت‌های اخیر')
            ->assertSee('میانبرهای ماژول‌ها');

        $this->actingAs($admin)
            ->getJson(route('dashboard.monthly-report'))
            ->assertOk()
            ->assertJsonStructure(['range_label', 'summary', 'metrics']);
    }

    public function test_regular_seller_cannot_access_management_monthly_report(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->getJson(route('dashboard.monthly-report'))
            ->assertForbidden();
    }

    public function test_finance_and_warehouse_users_load_without_sales_data_or_server_errors(): void
    {
        $finance = $this->userWithPermissions('finance-dashboard', [
            'dashboard.view',
            'finance.reports.view',
            'invoices.view',
        ]);
        $warehouse = $this->userWithPermissions('warehouse-dashboard', [
            'dashboard.view',
            'inventory.view',
            'products.view',
        ]);

        $this->actingAs($finance)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('خلاصه مالی')
            ->assertDontSee('دسترسی‌های اصلی فروش');

        $this->actingAs($warehouse)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('خلاصه انبار')
            ->assertDontSee('دسترسی‌های اصلی فروش');
    }

    public function test_unknown_preinvoice_status_does_not_break_the_dashboard(): void
    {
        $seller = $this->seller();
        $this->order($seller, 'future_status', 'مشتری وضعیت آینده');

        $this->actingAs($seller)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('مشتری وضعیت آینده')
            ->assertSee('وضعیت نامشخص');
    }

    private function seller(string $roleName = 'seller-dashboard'): User
    {
        return $this->userWithPermissions($roleName, [
            'dashboard.view',
            'preinvoices.create',
            'preinvoices.own.view',
            'preinvoices.drafts.edit',
            'customers.view',
        ]);
    }

    private function userWithPermissions(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');

        foreach ($permissions as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));

            $pagePermission = PageAccessCatalog::pagePermissionForLegacy($permissionName);
            if ($pagePermission && ! DB::table('permissions')->where('key', $pagePermission)->exists()) {
                DB::table('permissions')->insert([
                    'key'=>$pagePermission, 'name'=>$pagePermission, 'group'=>'page-test',
                    'guard_name'=>'web', 'created_at'=>now(), 'updated_at'=>now(),
                ]);
            }
            $pagePermissionId = $pagePermission ? DB::table('permissions')->where('key', $pagePermission)->value('id') : null;
            if ($pagePermissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $role->id,
                    'permission_id' => $pagePermissionId,
                ]);
            }
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function order(
        User $seller,
        string $status,
        string $customerName,
        int $total = 500_000,
        array $overrides = []
    ): PreinvoiceOrder {
        return PreinvoiceOrder::query()->create(array_merge([
            'uuid' => 'dash-'.str()->uuid(),
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'status' => $status,
            'customer_name' => $customerName,
            'customer_mobile' => '09120000000',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total_price' => $total,
            'document_date' => now(),
            'is_auto_draft' => false,
        ], $overrides));
    }
}
