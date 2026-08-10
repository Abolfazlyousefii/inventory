<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceReportsDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_contains_only_the_seller_documents_card_and_no_dashboard_data(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        $response = $this->actingAs($owner)->get(route('finance.reports.index'));

        $response->assertOk()
            ->assertSee('class="finance-reports-grid"', false)
            ->assertSee('اسناد فروش و پورسانت فروشندگان')
            ->assertSee('ثبت و مدیریت فاکتورهای منتخب هر فروشنده برای محاسبات واحد مالی')
            ->assertSee(route('finance.seller-sales.index'), false)
            ->assertDontSee(route('finance.reports.sales-visitors'), false)
            ->assertDontSee('داشبورد گزارش مالی')
            ->assertDontSee('finance-report-stats', false)
            ->assertDontSee('name="date_from"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'class="finance-report-directory-card"'));
        $this->assertStringContainsString(
            '.finance-reports-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr));',
            file_get_contents(public_path('css/finance-reports.css'))
        );
    }
}
