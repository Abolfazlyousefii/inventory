<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsUsersDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_dropdown_contains_all_active_erp_users_independent_of_seller_role_or_invoices(): void
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

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors'));

        $response->assertOk()
            ->assertSee('همه کاربران')
            ->assertSee('value="' . $regular->id . '"', false)
            ->assertSee('کاربر فعال عادی')
            ->assertSee('value="' . $seller->id . '"', false)
            ->assertDontSee('value="' . $inactive->id . '"', false)
            ->assertDontSee('value="' . $blocked->id . '"', false);

        $dropdownIds = collect($response->viewData('users'))->pluck('id');
        $this->assertTrue($dropdownIds->contains($regular->id));
        $this->assertTrue($dropdownIds->contains($seller->id));
        $this->assertFalse($dropdownIds->contains($inactive->id));
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
