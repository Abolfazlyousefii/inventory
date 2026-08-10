<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceReportViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_sees_document_module_in_reports_directory(): void
    {
        $response = $this->actingAs($this->owner())
            ->get(route('finance.reports.index'));

        $response->assertOk()
            ->assertSee('گزارش‌های مالی')
            ->assertSee('finance-reports-grid', false)
            ->assertSee('اسناد فروش و پورسانت فروشندگان')
            ->assertDontSee('داشبورد گزارش مالی')
            ->assertDontSee('جمع پرداخت‌شده')
            ->assertSee(route('finance.seller-sales.index'), false)
            ->assertDontSee(route('finance.reports.sales-visitors'), false)
            ->assertSee('css/finance-reports.css', false)
            ->assertSee('id="appSidebar"', false);
    }

    public function test_user_without_an_allowed_finance_role_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('finance.reports.index'))
            ->assertForbidden();
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
