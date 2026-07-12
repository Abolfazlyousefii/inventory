<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherController;
use App\Http\Controllers\VoucherSalesReturnController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VoucherSalesReturnTest extends TestCase
{
    public function test_canonical_index_uses_voucher_sales_return_controller_and_view(): void
    {
        $route = Route::getRoutes()->match(request()->create('/vouchers/section/return-from-sale', 'GET'));

        $this->assertSame(VoucherSalesReturnController::class.'@index', $route->getActionName());
        $this->assertSame('vouchers.return-from-sale.index', $route->getName());
    }

    public function test_canonical_create_uses_voucher_sales_return_controller_and_view(): void
    {
        $route = Route::getRoutes()->match(request()->create('/vouchers/section/return-from-sale/create', 'GET'));

        $this->assertSame(VoucherSalesReturnController::class.'@create', $route->getActionName());
        $this->assertSame('vouchers.return-from-sale.create', $route->getName());
    }

    public function test_sales_returns_get_ui_routes_redirect_to_canonical_urls(): void
    {
        $this->withoutMiddleware();

        $this->get('/sales-returns')->assertRedirect('/vouchers/section/return-from-sale');
        $this->get('/sales-returns/create')->assertRedirect('/vouchers/section/return-from-sale/create');
    }

    public function test_other_voucher_sections_stay_on_generic_controller(): void
    {
        $route = Route::getRoutes()->match(request()->create('/vouchers/section/transfer', 'GET'));

        $this->assertSame(VoucherController::class.'@sectionIndex', $route->getActionName());
        $this->assertSame('vouchers.section.index', $route->getName());
    }

    public function test_canonical_views_render_expected_markers_and_text(): void
    {
        $this->withoutMiddleware();
        $user = User::factory()->make(['id' => 1]);

        $this->actingAs($user)
            ->get('/vouchers/section/return-from-sale')
            ->assertOk()
            ->assertViewIs('vouchers.return-from-sale.index')
            ->assertSee('data-module="new-sales-return"', false)
            ->assertSee('ثبت برگشت جدید')
            ->assertSee('خروجی Excel')
            ->assertSee('خروجی PDF');

        $this->actingAs($user)
            ->get('/vouchers/section/return-from-sale/create')
            ->assertOk()
            ->assertViewIs('vouchers.return-from-sale.create')
            ->assertSee('data-module="new-sales-return-create"', false)
            ->assertSee('برگشت داخلی')
            ->assertSee('سازه‌حساب');
    }
}
