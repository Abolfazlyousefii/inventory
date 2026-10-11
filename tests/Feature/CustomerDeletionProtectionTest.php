<?php
namespace Tests\Feature;

use App\Models\{Customer, User};
use App\Http\Middleware\{EnsureActiveUser, RoutePermissionMiddleware, EnsurePageAccess, CheckRoleOrRoutePermission};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Event};
use Mockery;
use Tests\TestCase;

class CustomerDeletionProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Event::fake();
        $this->withoutMiddleware([EnsureActiveUser::class, RoutePermissionMiddleware::class, EnsurePageAccess::class, CheckRoleOrRoutePermission::class]);
        Schema::create('customers',function(Blueprint $t){$t->id();foreach(['first_name','last_name','name','mobile','address','postal_code','extra_description'] as $f)$t->string($f)->nullable();$t->integer('province_id')->nullable();$t->integer('city_id')->nullable();$t->softDeletes();$t->timestamps();});
        Schema::create('suppliers',function(Blueprint $t){$t->id();foreach(['name','phone','province','city','address','postal_code','additional_notes'] as $f)$t->string($f)->nullable();$t->softDeletes();$t->timestamps();});
        foreach(['customer_ledgers','invoices','preinvoice_orders','invoice_payments','warehouse_transfers'] as $table)Schema::create($table,function(Blueprint $t){$t->id();$t->integer('customer_id');});
        DB::table('customers')->insert(['id'=>1,'first_name'=>'مشتری اصلی','mobile'=>'09120000001','deleted_at'=>null]);
        $user=Mockery::mock(User::class)->makePartial();$user->forceFill(['id'=>108,'name'=>'مدیر']);
        $user->shouldReceive('isSuperAdmin')->andReturn(true);$user->shouldReceive('hasAnyRole')->andReturn(true);$user->shouldReceive('can')->andReturn(true);$this->actingAs($user);
    }
    public function test_even_administrator_cannot_delete_customer_with_or_without_financial_history(): void
    {
        $this->deleteJson(route('customers.destroy',1))->assertForbidden();
        $this->assertNull(Customer::withTrashed()->findOrFail(1)->deleted_at);
        DB::table('invoices')->insert(['customer_id'=>1]);
        $this->deleteJson(route('customers.destroy',1))->assertForbidden();
        $this->assertSame(1,Customer::count());$this->assertSame(1,DB::table('invoices')->count());
    }
    public function test_person_role_change_cannot_delete_customer_or_partially_create_supplier(): void
    {
        $this->put(route('persons.update','customer-1'),['name'=>'تغییر نام','mobile'=>'09120000001','types'=>['supplier']])->assertSessionHasErrors('types');
        $this->assertSame('مشتری اصلی',Customer::findOrFail(1)->first_name);
        $this->assertSame(0,DB::table('suppliers')->count());$this->assertNull(Customer::withTrashed()->findOrFail(1)->deleted_at);
    }
    public function test_existing_customer_can_still_be_updated_without_removing_customer_role(): void
    {
        $this->put(route('persons.update','customer-1'),['name'=>'نام جدید','mobile'=>'09120000001','types'=>['customer'],'address'=>'آدرس جدید'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('نام جدید',Customer::findOrFail(1)->first_name);$this->assertSame('آدرس جدید',Customer::findOrFail(1)->address);
        $this->assertNull(Customer::withTrashed()->findOrFail(1)->deleted_at);
    }
}
