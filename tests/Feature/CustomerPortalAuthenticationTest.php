<?php

namespace Tests\Feature;

use App\Contracts\OtpSender;
use App\Models\Customer;
use App\Models\CustomerLoginCode;
use App\Models\CustomerPhone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPortalAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private CapturingOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = new CapturingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    public function test_customer_with_password_can_login_using_any_registered_phone(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $customer->phones()->create(['phone' => '09351111111', 'is_primary' => false]);
        $customer->forceFill(['password' => Hash::make('SecurePass123')])->save();

        $this->post('/portal/login/password', [
            'phone' => '۰۹۳۵۱۱۱۱۱۱۱',
            'password' => 'SecurePass123',
        ])->assertRedirect('/portal');

        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_wrong_password_customer_without_password_and_inactive_customer_are_rejected(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $customer->forceFill(['password' => Hash::make('SecurePass123')])->save();

        $this->post('/portal/login/password', [
            'phone' => '09121111111',
            'password' => 'WrongPassword',
        ])->assertSessionHasErrors('password');
        $this->assertGuest('customer');

        $withoutPassword = $this->customerWithPhone('09122222222');
        $this->post('/portal/login/password', [
            'phone' => '09122222222',
            'password' => 'Anything123',
        ])->assertSessionHasErrors('password');
        $this->assertGuest('customer');

        $inactive = $this->customerWithPhone('09123333333', false);
        $inactive->forceFill(['password' => Hash::make('SecurePass123')])->save();
        $this->post('/portal/login/password', [
            'phone' => '09123333333',
            'password' => 'SecurePass123',
        ])->assertSessionHasErrors('password');
        $this->assertGuest('customer');
        $this->assertNotNull($withoutPassword);
    }

    public function test_registered_primary_or_secondary_phone_can_request_otp(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $customer->phones()->create(['phone' => '09351111111', 'is_primary' => false]);

        $this->post('/portal/login', ['phone' => '09351111111'])
            ->assertRedirect('/portal/verify');

        $this->assertSame('09351111111', $this->sender->phone);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sender->code);
        $this->assertDatabaseHas('customer_login_codes', ['customer_id' => $customer->id, 'phone' => '09351111111']);
        $this->assertDatabaseMissing('customer_login_codes', ['code_hash' => $this->sender->code]);
    }

    public function test_persian_phone_is_normalized_before_otp_request(): void
    {
        $this->customerWithPhone('09123456789');

        $this->post('/portal/login', ['phone' => '۰۹۱۲۳۴۵۶۷۸۹'])->assertRedirect('/portal/verify');

        $this->assertSame('09123456789', $this->sender->phone);
    }

    public function test_unknown_inactive_and_soft_deleted_customers_do_not_receive_otp(): void
    {
        $this->post('/portal/login', ['phone' => '09120000000'])->assertRedirect('/portal/verify');
        $this->assertNull($this->sender->code);

        $inactive = $this->customerWithPhone('09121111111', false);
        $this->post('/portal/login', ['phone' => '09121111111'])->assertRedirect('/portal/verify');
        $this->assertNull($this->sender->code);

        $deleted = $this->customerWithPhone('09122222222');
        $deleted->delete();
        $this->post('/portal/login', ['phone' => '09122222222'])->assertRedirect('/portal/verify');
        $this->assertNull($this->sender->code);
        $this->assertDatabaseCount('customer_login_codes', 0);
        $this->assertNotNull($inactive);
    }

    public function test_valid_otp_authenticates_the_correct_customer(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $this->post('/portal/login', ['phone' => '09121111111']);

        $this->post('/portal/verify', ['code' => $this->sender->code])
            ->assertRedirect('/portal');

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseMissing('customer_login_codes', ['customer_id' => $customer->id, 'consumed_at' => null]);
    }

    public function test_invalid_or_expired_otp_is_rejected(): void
    {
        $this->customerWithPhone('09121111111');
        $this->post('/portal/login', ['phone' => '09121111111']);

        $this->post('/portal/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('customer');

        CustomerLoginCode::query()->update(['expires_at' => now()->subSecond()]);
        $this->post('/portal/verify', ['code' => $this->sender->code])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_consumed_otp_cannot_be_reused(): void
    {
        $this->customerWithPhone('09121111111');
        $this->post('/portal/login', ['phone' => '09121111111']);
        $code = $this->sender->code;
        $this->post('/portal/verify', ['code' => $code])->assertRedirect('/portal');
        $this->post('/portal/logout')->assertRedirect('/portal/login');

        $this->withSession(['customer_login_phone' => '09121111111'])
            ->post('/portal/verify', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_otp_attempts_are_limited(): void
    {
        $this->customerWithPhone('09121111111');
        $this->post('/portal/login', ['phone' => '09121111111']);
        $validCode = $this->sender->code;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/portal/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post('/portal/verify', ['code' => $validCode])->assertSessionHasErrors('code');
        $this->assertGuest('customer');
    }

    public function test_otp_request_route_is_rate_limited(): void
    {
        $this->customerWithPhone('09121111111');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/portal/login', ['phone' => '09121111111'])->assertRedirect('/portal/verify');
        }
        $this->post('/portal/login', ['phone' => '09121111111'])->assertTooManyRequests();
    }

    public function test_customer_can_logout_and_inactive_session_is_terminated(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $this->actingAs($customer, 'customer')->post('/portal/logout')->assertRedirect('/portal/login');
        $this->assertGuest('customer');

        $customer->update(['is_active' => false]);
        $this->actingAs($customer, 'customer')->get('/portal')->assertRedirect('/portal/login');
        $this->assertGuest('customer');
    }

    public function test_customer_session_cannot_access_staff_routes(): void
    {
        $customer = $this->customerWithPhone('09121111111');

        auth('customer')->login($customer);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest();
    }

    public function test_staff_session_does_not_authenticate_customer_guard(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/portal')->assertRedirect('/portal/login');
        $this->assertAuthenticatedAs($staff);
        $this->assertGuest('customer');
    }

    public function test_each_guard_logout_preserves_the_other_authenticated_guard(): void
    {
        $customer = $this->customerWithPhone('09121111111');
        $staff = User::factory()->create();

        $this->actingAs($staff);
        auth('customer')->login($customer);
        $this->post('/portal/logout')->assertRedirect('/portal/login');
        $this->assertAuthenticatedAs($staff);
        $this->assertGuest('customer');

        auth('customer')->login($customer);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    private function customerWithPhone(string $phone, bool $active = true): Customer
    {
        $customer = Customer::factory()->create(['is_active' => $active]);
        CustomerPhone::factory()->for($customer)->create(['phone' => $phone, 'is_primary' => true]);

        return $customer;
    }
}

class CapturingOtpSender implements OtpSender
{
    public ?string $phone = null;

    public ?string $code = null;

    public function send(string $phone, string $code): void
    {
        $this->phone = $phone;
        $this->code = $code;
    }
}
