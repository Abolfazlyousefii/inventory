<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsUsersDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_dropdown_contains_active_sellers_and_historical_effective_sellers(): void
    {
        $regular = User::factory()->create([
            'name' => 'کاربر فعال عادی',
            'is_active' => true,
            'can_access_erp' => true,
            'is_seller' => false,
        ]);
        $seller = User::factory()->create([
            'name' => 'کاربر فعال فروش',
            'is_active' => true,
            'can_access_erp' => true,
            'is_seller' => true,
        ]);
        $inactive = User::factory()->create(['name' => 'کاربر غیرفعال', 'is_active' => false, 'can_access_erp' => true]);
        $blocked = User::factory()->create(['name' => 'کاربر بدون دسترسی ERP', 'is_active' => true, 'can_access_erp' => false]);

        Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'seller_id' => $inactive->id,
            'customer_name' => 'Historical customer',
            'subtotal' => 1000,
            'total' => 1000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors'));

        $response->assertOk()
            ->assertSee('همه کاربران')
            ->assertDontSee('value="' . $regular->id . '"', false)
            ->assertSee('value="' . $seller->id . '"', false)
            ->assertSee('value="' . $inactive->id . '"', false)
            ->assertDontSee('value="' . $blocked->id . '"', false);

        $dropdownIds = collect($response->viewData('users'))->pluck('id');
        $this->assertFalse($dropdownIds->contains($regular->id));
        $this->assertTrue($dropdownIds->contains($seller->id));
        $this->assertTrue($dropdownIds->contains($inactive->id));
        $this->assertFalse($dropdownIds->contains($blocked->id));
    }

    public function test_user_without_finance_access_is_forbidden(): void
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
