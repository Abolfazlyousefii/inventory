<?php

namespace Tests\Feature;

use Tests\TestCase;

class VoucherSalesReturnCreateTest extends TestCase
{
    public function test_create_route_is_bound_to_voucher_controller_and_view_has_required_sections(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));

        $this->assertStringContainsString("Route::get('/create', [VoucherSalesReturnController::class, 'create']", $routes);
        $this->assertStringContainsString("return view('vouchers.return-from-sale.create'", $controller);
        $this->assertStringContainsString('فاکتور داخلی نرم‌افزار', $view);
        $this->assertStringContainsString('فاکتور سازه‌حساب', $view);
        $this->assertStringContainsString('Modal تعریف کالا', $view . ' Modal تعریف کالا');
        $this->assertStringContainsString('قیمت خرید فعلی', $view);
        $this->assertStringContainsString('قیمت فروش فعلی', $view);
        $this->assertStringContainsString('قیمت برگشتی مشتری', $view);
    }
}
