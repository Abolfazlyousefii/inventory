<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    public function test_guest_cannot_view_customers(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_user_without_permission_receives_403(): void
    {
        $this->actingAs(User::factory()->create())->get('/customers')->assertForbidden();
    }

    public function test_user_with_view_permission_and_super_admin_can_view_customers(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('customers.view');

        $this->actingAs($viewer)->get('/customers')->assertOk()->assertSee('مشتریان');
        $this->actingAs($this->admin)->get('/customers')->assertOk();
    }

    public function test_customer_create_and_edit_forms_render(): void
    {
        $customer = $this->createCustomer('مشتری فرم', '09121111111');

        $this->actingAs($this->admin)->get('/customers/create')
            ->assertOk()->assertSee('افزودن شماره دیگر');
        $this->actingAs($this->admin)->get("/customers/{$customer->id}/edit")
            ->assertOk()->assertSee('09121111111');
    }

    public function test_view_only_user_cannot_create_update_or_delete_customer_via_url(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('customers.view');
        $customer = $this->createCustomer('مشتری محافظت‌شده', '09121111111');

        $this->actingAs($viewer)->get('/customers/create')->assertForbidden();
        $this->actingAs($viewer)->post('/customers', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put("/customers/{$customer->id}", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete("/customers/{$customer->id}")->assertForbidden();
    }

    public function test_project_manager_receives_customer_permissions_by_default(): void
    {
        $role = Role::findByName('project-manager');

        $this->assertTrue($role->hasAllPermissions([
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',
        ]));
        $this->assertFalse(Role::findByName('team-member')->hasPermissionTo('customers.view'));
    }

    public function test_authorized_user_can_create_customer_with_normalized_phone(): void
    {
        $this->actingAs($this->admin)->post('/customers', $this->payload([
            'phones' => [['phone' => '۰۹۱۲۳۴۵۶۷۸۹']],
        ]))->assertRedirect();

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('مشتری آزمایشی', $customer->name);
        $this->assertDatabaseHas('customer_phones', [
            'customer_id' => $customer->id,
            'phone' => '09123456789',
            'is_primary' => true,
        ]);
    }

    public function test_authorized_user_can_create_customer_with_frontend_modal_payload(): void
    {
        $response = $this->actingAs($this->admin)->post('/customers', [
            'customer_name' => 'Test Customer',
            'mobile' => '+98 912 345 6789',
            'address' => 'Test address',
            'province_id' => 1,
            'city_id' => 1,
        ]);

        $response->assertSessionDoesntHaveErrors()->assertRedirect();

        $customer = Customer::query()->firstOrFail();
        $this->assertSame('Test Customer', $customer->name);
        $this->assertSame('09123456789', $customer->mobile);
        $this->assertDatabaseHas('customer_phones', [
            'customer_id' => $customer->id,
            'phone' => '09123456789',
            'is_primary' => true,
        ]);
    }

    public function test_frontend_modal_payload_rejects_a_duplicate_mobile(): void
    {
        $this->createCustomer('Existing Customer', '09123456789');

        $this->actingAs($this->admin)->post('/customers', [
            'customer_name' => 'Duplicate Customer',
            'mobile' => '+989123456789',
        ])->assertSessionHasErrors('phones.0.phone');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_frontend_modal_payload_rejects_an_invalid_iranian_mobile(): void
    {
        $this->actingAs($this->admin)->post('/customers', [
            'customer_name' => 'Invalid Mobile Customer',
            'mobile' => '02112345678',
        ])->assertSessionHasErrors('phones.0.phone');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_phones', 0);
    }

    public function test_authorized_user_can_define_password_when_creating_customer(): void
    {
        $this->actingAs($this->admin)->post('/customers', $this->payload([
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]))->assertRedirect();

        $customer = Customer::query()->firstOrFail();

        $this->assertNotSame('SecurePass123', $customer->password);
        $this->assertTrue(Hash::check('SecurePass123', $customer->password));
        $this->assertNotNull($customer->password_changed_at);
    }

    public function test_customer_password_can_be_changed_and_blank_password_keeps_current_password(): void
    {
        $customer = $this->createCustomer('مشتری رمزدار', '09121111111');
        $customer->forceFill([
            'password' => Hash::make('OldPassword123'),
            'password_changed_at' => now()->subDay(),
        ])->save();
        $originalHash = $customer->password;

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'phones' => [['phone' => '09121111111']],
            'password' => '',
            'password_confirmation' => '',
        ]))->assertRedirect("/customers/{$customer->id}");

        $this->assertSame($originalHash, $customer->fresh()->password);

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'phones' => [['phone' => '09121111111']],
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]))->assertRedirect("/customers/{$customer->id}");

        $this->assertTrue(Hash::check('NewPassword123', $customer->fresh()->password));
    }

    public function test_customer_can_have_multiple_phones_and_exactly_one_primary_phone(): void
    {
        $this->actingAs($this->admin)->post('/customers', $this->payload([
            'phones' => [
                ['phone' => '+989123456789'],
                ['phone' => '9351234567'],
                ['phone' => '989361234567'],
            ],
            'primary_phone' => 1,
        ]))->assertRedirect();

        $customer = Customer::query()->firstOrFail();
        $this->assertCount(3, $customer->phones);
        $this->assertSame(1, $customer->phones()->where('is_primary', true)->count());
        $this->assertSame('09351234567', $customer->primaryPhone->phone);
    }

    public function test_customer_cannot_be_created_without_phone(): void
    {
        $this->actingAs($this->admin)->post('/customers', $this->payload([
            'phones' => [],
        ]))->assertSessionHasErrors('phones');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_duplicate_normalized_phones_in_same_request_are_rejected(): void
    {
        $this->actingAs($this->admin)->post('/customers', $this->payload([
            'phones' => [
                ['phone' => '09123456789'],
                ['phone' => '+989123456789'],
            ],
        ]))->assertSessionHasErrors('phones.1.phone');
    }

    public function test_phone_owned_by_another_customer_is_rejected(): void
    {
        $this->createCustomer('مالک شماره', '09123456789');

        $this->actingAs($this->admin)->post('/customers', $this->payload())
            ->assertSessionHasErrors('phones.0.phone');
    }

    public function test_customer_profile_displays_all_phones_and_primary_badge(): void
    {
        $customer = $this->createCustomer('پروفایل مشتری', '09121111111');
        $customer->phones()->create(['phone' => '09351111111', 'is_primary' => false]);

        $this->actingAs($this->admin)->get("/customers/{$customer->id}")
            ->assertOk()
            ->assertSee('09121111111')
            ->assertSee('09351111111')
            ->assertSee('اصلی');
    }

    public function test_customer_can_be_updated_and_phones_can_be_added_removed_and_reassigned(): void
    {
        $customer = $this->createCustomer('نام قدیمی', '09121111111');
        $customer->phones()->create(['phone' => '09351111111', 'is_primary' => false]);

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'name' => 'نام جدید',
            'phones' => [
                ['phone' => '09351111111'],
                ['phone' => '09122222222'],
            ],
            'primary_phone' => 1,
        ]))->assertRedirect("/customers/{$customer->id}");

        $customer->refresh();
        $this->assertSame('نام جدید', $customer->name);
        $this->assertDatabaseMissing('customer_phones', ['phone' => '09121111111']);
        $this->assertDatabaseHas('customer_phones', ['phone' => '09122222222', 'is_primary' => true]);
        $this->assertSame(2, $customer->phones()->count());
        $this->assertSame(1, $customer->phones()->where('is_primary', true)->count());
    }

    public function test_customer_cannot_be_left_without_phone_on_update(): void
    {
        $customer = $this->createCustomer('مشتری', '09121111111');

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'phones' => [],
        ]))->assertSessionHasErrors('phones');

        $this->assertDatabaseHas('customer_phones', ['customer_id' => $customer->id, 'phone' => '09121111111']);
    }

    public function test_customer_can_keep_its_own_phone_during_update_but_not_another_customers_phone(): void
    {
        $customer = $this->createCustomer('مشتری اول', '09121111111');
        $this->createCustomer('مشتری دوم', '09122222222');

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'phones' => [['phone' => '09121111111']],
        ]))->assertSessionDoesntHaveErrors();

        $this->actingAs($this->admin)->put("/customers/{$customer->id}", $this->payload([
            'phones' => [['phone' => '09122222222']],
        ]))->assertSessionHasErrors('phones.0.phone');
    }

    public function test_customer_is_soft_deleted_and_hidden_from_normal_list(): void
    {
        $customer = $this->createCustomer('مشتری حذف‌شدنی', '09121111111');

        $this->actingAs($this->admin)->delete("/customers/{$customer->id}")
            ->assertRedirect('/customers');

        $this->assertSoftDeleted($customer);
        $this->actingAs($this->admin)->get('/customers')->assertDontSee('مشتری حذف‌شدنی');
    }

    public function test_search_works_by_name_company_phone_and_city(): void
    {
        $customer = $this->createCustomer('دکتر محمدی', '09121112222', [
            'company_name' => 'فروشگاه آفتاب',
            'city' => 'گرگان',
        ]);
        $this->createCustomer('مشتری دیگر', '09351112222', ['company_name' => 'سپهر', 'city' => 'تهران']);

        foreach (['دکتر', 'آفتاب', '0912111', 'گرگان'] as $query) {
            $this->actingAs($this->admin)->get('/customers?q='.urlencode($query))
                ->assertOk()->assertSee($customer->name)->assertDontSee('مشتری دیگر');
        }
    }

    public function test_status_filter_works_together_with_search(): void
    {
        $this->createCustomer('مشتری فعال تهران', '09121111111', ['city' => 'تهران']);
        $this->createCustomer('مشتری غیرفعال تهران', '09122222222', ['city' => 'تهران', 'is_active' => false]);

        $this->actingAs($this->admin)->get('/customers?q='.urlencode('تهران').'&status=active')
            ->assertOk()
            ->assertSee('مشتری فعال تهران')
            ->assertDontSee('مشتری غیرفعال تهران');
    }

    public function test_dashboard_shows_real_active_customer_count_only_with_permission(): void
    {
        $this->createCustomer('فعال', '09121111111');
        $this->createCustomer('غیرفعال', '09122222222', ['is_active' => false]);

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('مشتریان فعال')->assertSee('1');

        $user = User::factory()->create();
        $user->givePermissionTo('dashboard.view');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('مشتریان فعال');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'مشتری آزمایشی',
            'company_name' => 'مجموعه آزمایشی',
            'city' => 'تهران',
            'address' => 'آدرس مشتری',
            'notes' => 'یادداشت داخلی',
            'is_active' => '1',
            'phones' => [['phone' => '09123456789']],
            'primary_phone' => 0,
        ], $overrides);
    }

    private function createCustomer(string $name, string $phone, array $attributes = []): Customer
    {
        $customer = Customer::factory()->create(array_merge(['name' => $name], $attributes));
        CustomerPhone::factory()->for($customer)->create(['phone' => $phone, 'is_primary' => true]);

        return $customer;
    }
}
