<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnLiveIndexTest extends TestCase
{
    public function test_index_only_renders_four_live_filters(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $this->assertStringContainsString('name="document_number"', $blade);
        $this->assertStringContainsString('data-customer-picker', file_get_contents(resource_path('views/vouchers/return-from-sale/partials/customer-filter-picker.blade.php')));
        $this->assertStringContainsString('name="date_from"', $blade);
        $this->assertStringContainsString('name="date_to"', $blade);
        $this->assertStringContainsString('salesReturnClearFilters', $blade);
        $this->assertStringNotContainsString('name="status"', $blade);
        $this->assertStringNotContainsString('moreFilters', $blade);
        $this->assertStringNotContainsString('type="submit">اعمال', $blade);
    }

    public function test_live_search_finds_new_document_by_number(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString('d.document_number', $service);
        $this->assertStringContainsString('exact_rank', $service);
    }

    public function test_live_search_finds_legacy_document_by_reference(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString('w.reference', $service);
        $this->assertStringContainsString('warehouse_transfers as w', $service);
    }

    public function test_customer_live_filter_returns_only_selected_customer(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString("where('d.customer_id'", $service);
        $this->assertStringContainsString("where('w.customer_id'", $service);
    }

    public function test_persian_jalali_date_and_time_range_is_inclusive(): void
    {
        $request = file_get_contents(app_path('Http/Requests/SalesReturnIndexRequest.php'));
        $queryService = file_get_contents(app_path('Services/SalesReturnQueryService.php'));
        $reportService = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString('normalizeJalaliDate', $request);
        $this->assertStringContainsString('H:i:s', $request);
        $this->assertStringContainsString('dateBoundary', $reportService);
        $this->assertStringContainsString('startOfDay', $queryService);
        $this->assertStringContainsString('endOfDay', $queryService);
    }

    public function test_invalid_date_has_persian_error(): void
    {
        $request = file_get_contents(app_path('Http/Requests/SalesReturnIndexRequest.php'));
        $this->assertStringContainsString('تاریخ را به‌شکل ۱۴۰۵/۰۴/۲۸ وارد کنید.', $request);
    }

    public function test_ajax_response_contains_html_url_and_total(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/VoucherSalesReturnController.php'));
        $this->assertStringContainsString("'html'=>view('vouchers.return-from-sale.partials.index-results'", $controller);
        $this->assertStringContainsString("'url'=>", $controller);
        $this->assertStringContainsString("'total'=>", $controller);
    }

    public function test_ajax_pagination_preserves_filters(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $this->assertStringContainsString('pagination a', $blade);
        $this->assertStringContainsString('replace(indexUrl,dataUrl)', $blade);
    }

    public function test_database_pagination_does_not_load_all_rows(): void
    {
        $service = file_get_contents(app_path('Services/SalesReturnReportService.php'));
        $this->assertStringContainsString('unionAll', $service);
        $this->assertStringContainsString('paginate($perPage)', $service);
        $this->assertStringNotContainsString('forPage($page', $service);
    }

    public function test_clear_filter_returns_first_page(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));
        $this->assertStringContainsString('salesReturnClearFilters', $blade);
        $this->assertStringContainsString('liveFetch(dataUrl)', $blade);
    }
}
