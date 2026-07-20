<?php

namespace Tests\Feature;

use App\Http\Requests\SalesReturnIndexRequest;
use App\Models\SalesReturnDocument;
use App\Services\SalesReturnQueryService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SalesReturnIndexStabilizationTest extends TestCase
{
    public function test_status_filter_is_not_rendered(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $this->assertStringNotContainsString('name="status"', $blade);
    }

    public function test_persian_jalali_dates_are_normalized(): void
    {
        $request = SalesReturnIndexRequest::create('/x', 'GET', ['date_from' => '۱۴۰۵/۰۴/۲۸']);
        $request->setContainer(app());
        $request->validateResolved();
        $this->assertSame('1405/04/28', $request->filters()['date_from']);
    }

    public function test_invalid_date_returns_persian_validation_error(): void
    {
        $request = new SalesReturnIndexRequest();
        $rules = $request->rules();
        $validator = Validator::make(['date_from' => '1405/4/28'], $rules, $request->messages());
        $this->assertTrue($validator->fails());
        $this->assertSame('تاریخ را به‌شکل ۱۴۰۵/۰۴/۲۸ وارد کنید.', $validator->errors()->first('date_from'));
    }

    public function test_ajax_index_returns_results_partial(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));
        $this->assertStringContainsString("'html'=>view('vouchers.return-from-sale.partials.index-results'", $controller);
        $this->assertStringContainsString("'url'=>", $controller);
        $partial = file_get_contents(resource_path('views/vouchers/return-from-sale/partials/index-results.blade.php'));
        $this->assertStringContainsString('<table', $partial);
        $this->assertStringContainsString('links()', $partial);
    }

    public function test_ajax_filter_preserves_customer_id(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));
        $this->assertStringContainsString('route(\'vouchers.return-from-sale.index\'', $controller);
        $this->assertStringContainsString('collect($filters)', $controller);
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $this->assertStringContainsString("'[name=\"customer_id\"]'", $blade);
    }

    public function test_draft_row_has_edit_and_cancel_actions(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $partial = file_get_contents(resource_path('views/vouchers/return-from-sale/partials/index-results.blade.php'));
        $this->assertStringContainsString("'can_edit' => \$document->isDraft() ?", $service);
        $this->assertStringContainsString('ویرایش', $partial);
        $this->assertStringContainsString('حذف پیش‌نویس', $partial);
    }

    public function test_applied_row_does_not_have_draft_actions(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $partial = file_get_contents(resource_path('views/vouchers/return-from-sale/partials/index-results.blade.php'));
        $this->assertStringContainsString("'edit_url' => \$document->isDraft() ?", $service);
        $this->assertStringContainsString('applied.edit', $service);
    }

    public function test_legacy_row_is_read_only(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $partial = file_get_contents(resource_path('views/vouchers/return-from-sale/partials/index-results.blade.php'));
        $this->assertStringContainsString("'can_edit' => false", $service);
        $this->assertStringContainsString('قدیمی', $partial);
    }

    public function test_source_switch_has_explicit_panels(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringContainsString('data-source-panel="internal_invoice"', $blade);
        $this->assertStringContainsString('data-source-panel="sazeh_hesab"', $blade);
    }

    public function test_source_switch_updates_hidden_value(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));
        $this->assertStringContainsString('function applySourceType(type, options = {})', $blade);
        $this->assertStringContainsString('sourceTypeInput.value=type', $blade);
        $this->assertStringNotContainsString('if(type===state.type)return', $blade);
    }
}
