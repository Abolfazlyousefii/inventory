<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomerAction;
use App\Http\Controllers\CustomerController;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerLegacyMobileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->integer('user_id')->nullable();
            foreach (['action', 'subject_type', 'description', 'properties'] as $f) {
                $t->text($f)->nullable();
            }$t->integer('subject_id')->nullable();
            $t->timestamp('occurred_at')->nullable();
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('mobile')->unique();
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('customer_phones', function (Blueprint $t) {
            $t->id();
            $t->integer('customer_id');
            $t->string('phone')->unique();
            $t->boolean('is_primary');
            $t->timestamps();
        });
    }

    public function test_legacy_mobile_without_phone_row_is_rejected_before_insert(): void
    {
        DB::table('customers')->insert(['name' => 'Existing', 'mobile' => '09123456789']);
        $r = StoreCustomerRequest::create('/customers', 'POST', ['name' => 'New', 'mobile' => '۰۹۱۲۳۴۵۶۷۸۹']);
        $r->setContainer(app())->setRedirector(app('redirect'));
        (new \ReflectionMethod($r, 'prepareForValidation'))->invoke($r);
        $v = Validator::make($r->all(), $r->rules(), $r->messages());
        $this->assertTrue($v->fails(), 'Legacy duplicate mobile passed validation');
        $this->assertTrue($v->errors()->has('phones.0.phone'));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_phones', 0);
    }

    public function test_soft_deleted_customer_still_reserves_unique_mobile(): void
    {
        DB::table('customers')->insert(['name' => 'Old', 'mobile' => '09123456789', 'deleted_at' => now()]);
        $r = StoreCustomerRequest::create('/customers', 'POST', ['name' => 'New', 'mobile' => '+989123456789']);
        $r->setContainer(app())->setRedirector(app('redirect'));
        (new \ReflectionMethod($r, 'prepareForValidation'))->invoke($r);
        $v = Validator::make($r->all(), $r->rules(), $r->messages());
        $this->assertTrue($v->fails());
    }

    public function test_concurrent_duplicate_returns_validation_error_and_rolls_back(): void
    {
        DB::table('customers')->insert(['name' => 'Existing', 'mobile' => '09123456789']);
        $controller = app(CustomerController::class);
        try {
            (new \ReflectionMethod($controller, 'persistCustomer'))->invoke($controller, fn () => app(CreateCustomerAction::class)->execute(['name' => 'New', 'phones' => [['phone' => '09123456789']], 'primary_phone' => 0, 'is_active' => true]), 0);
            $this->fail('Duplicate must return validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phones.0.phone', $e->errors());
        }
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_phones', 0);
    }

    public function test_update_keeps_own_number_but_rejects_another_legacy_customer_number(): void
    {
        $c = Customer::create(['name' => 'Self', 'mobile' => '09121111111']);
        DB::table('customers')->insert(['name' => 'Other', 'mobile' => '09122222222']);
        foreach (['09121111111' => false, '09122222222' => true] as $phone => $fails) {
            $r = UpdateCustomerRequest::create('/customers/'.$c->id, 'PUT', ['name' => 'Self', 'phones' => [['phone' => (string) $phone]], 'primary_phone' => 0]);
            $route = new Route('PUT', 'customers/{customer}', fn () => null);
            $route->bind($r);
            $route->setParameter('customer', $c);
            $r->setRouteResolver(fn () => $route);
            (new \ReflectionMethod($r, 'prepareForValidation'))->invoke($r);
            $v = Validator::make($r->all(), $r->rules());
            $this->assertSame($fails, $v->fails());
        }
    }

    public function test_new_number_creates_customer_and_primary_phone(): void
    {
        $c = app(CreateCustomerAction::class)->execute(['name' => 'New', 'phones' => [['phone' => '09123456789']], 'primary_phone' => 0, 'is_active' => true]);
        $this->assertSame('09123456789', $c->mobile);
        $this->assertDatabaseHas('customer_phones',['customer_id' => $c->id, 'phone' => '09123456789', 'is_primary' => true]);
    }
}
