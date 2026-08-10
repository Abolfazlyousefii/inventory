<?php

namespace Tests\Feature;

use Tests\TestCase;

class InvoiceLiveIndexTest extends TestCase
{
    private function controller(): string { return file_get_contents(app_path('Http/Controllers/InvoiceController.php')); }
    private function request(): string { return file_get_contents(app_path('Http/Requests/InvoiceLiveFilterRequest.php')); }
    private function indexViewSource(): string { return file_get_contents(resource_path('views/invoices/index.blade.php')); }
    private function routes(): string { return file_get_contents(base_path('routes/web.php')); }

    public function test_live_routes_and_permissions_are_registered(): void
    {
        $routes = $this->routes();
        $catalog = file_get_contents(app_path('Support/PermissionCatalog.php'));
        $this->assertStringContainsString("name('invoices.data')", $routes);
        $this->assertStringContainsString("name('invoices.customers.search')", $routes);
        $this->assertStringContainsString("'invoices.data' => 'invoices.view'", $catalog);
        $this->assertStringContainsString("'invoices.customers.search' => 'invoices.view'", $catalog);
    }

    public function test_index_is_simple_and_has_no_numbered_paginator_or_report_exports(): void
    {
        $view = $this->indexViewSource();
        $this->assertStringNotContainsString('->links(', $view);
        $this->assertStringNotContainsString('خروجی Excel', $view);
        $this->assertStringNotContainsString('خروجی CSV', $view);
        $this->assertStringNotContainsString('چاپ گزارش', $view);
        $this->assertStringContainsString("route('invoices.cancelled')", $view);
    }

    public function test_data_uses_bounded_cursor_pagination_and_stable_order(): void
    {
        $controller = $this->controller();
        $request = $this->request();
        $this->assertStringContainsString("'limit' => \$this->query('limit', 40)", $request);
        $this->assertStringContainsString("'min:10'", $request);
        $this->assertStringContainsString("'max:50'", $request);
        $this->assertStringContainsString("orderByDesc('invoices.created_at')->orderByDesc('invoices.id')", $controller);
        $this->assertStringContainsString('cursorPaginate(', $controller);
    }

    public function test_query_filters_exact_customer_and_excludes_cancelled_invoices(): void
    {
        $controller = $this->controller();
        $this->assertStringContainsString('Invoice::query()->active()', $controller);
        $this->assertStringContainsString("where('invoices.customer_id', (int) \$filters['customer_id'])", $controller);
        $this->assertStringNotContainsString("payments.cheque", substr($controller, strpos($controller, 'public function data'), strpos($controller, 'public function salesVouchers') - strpos($controller, 'public function data')));
    }

    public function test_invoice_and_preinvoice_codes_are_searched_with_exact_priority(): void
    {
        $controller = $this->controller();
        $this->assertStringContainsString('when invoices.uuid = ? then 0', $controller);
        $this->assertStringContainsString('preinvoice_orders.uuid = ?', $controller);
        $this->assertStringContainsString("orWhereHas('preinvoiceOrder'", $controller);
    }

    public function test_summary_is_optional_and_not_requested_during_load_more(): void
    {
        $controller = $this->controller();
        $script = file_get_contents(public_path('js/invoices-index.js'));
        $this->assertStringContainsString("boolean('include_summary')", $controller);
        $this->assertStringContainsString("params.set('include_summary', '1')", $script);
        $this->assertStringContainsString("if (append && cursor)", $script);
    }

    public function test_customer_endpoint_is_limited_and_returns_no_sensitive_fields(): void
    {
        $controller = $this->controller();
        $segment = substr($controller, strpos($controller, 'public function customersSearch'), strpos($controller, 'public function salesVouchers') - strpos($controller, 'public function customersSearch'));
        $this->assertStringContainsString('limit(20)', $segment);
        $this->assertStringContainsString("'crm_customer_id', 'first_name', 'last_name', 'mobile'", $segment);
        $this->assertStringNotContainsString('opening_balance', $segment);
        $this->assertStringNotContainsString('address', $segment);
    }

    public function test_responsive_views_and_permission_aware_actions_exist(): void
    {
        $css = file_get_contents(public_path('css/invoices-index.css'));
        $actions = file_get_contents(resource_path('views/invoices/partials/actions.blade.php'));
        $this->assertStringContainsString('@media(max-width:991.98px)', $css);
        foreach (['show', 'print', 'edit', 'cancel'] as $action) {
            $this->assertStringContainsString("['actions']['{$action}']", $actions);
        }
    }
}
