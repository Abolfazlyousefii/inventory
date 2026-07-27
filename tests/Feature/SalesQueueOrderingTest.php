<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SalesQueueOrderingTest extends TestCase
{
    private string $controller;
    private string $view;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $this->controller = file_get_contents($root.'/app/Http/Controllers/InvoiceController.php');
        $this->view = file_get_contents($root.'/resources/views/vouchers/sales/queue.blade.php');
    }

    public function test_initial_page_and_refresh_use_created_at_desc_with_id_tie_breaker(): void
    {
        $ordering = <<<'PHP'
->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
PHP;

        $this->assertSame(2, substr_count($this->controller, $ordering));
        $this->assertStringNotContainsString(
            "\$this->salesQueueQuery(false)->orderBy('status_changed_at')",
            $this->controller
        );
    }

    public function test_queue_data_uses_bounded_pagination_and_returns_metadata(): void
    {
        $this->assertStringContainsString(
            "\$perPage = max(1, min(\$request->integer('per_page', 20), 50));",
            $this->controller
        );
        $this->assertStringContainsString("\$page = max(1, \$request->integer('page', 1));", $this->controller);
        $this->assertStringContainsString("->paginate(\$perPage, ['*'], 'page', \$page)", $this->controller);
        $this->assertStringNotContainsString('->limit(100)->get()', $this->controller);

        foreach (['total', 'current_page', 'per_page', 'last_page'] as $key) {
            $this->assertStringContainsString("'{$key}' => \$invoices->", $this->controller);
        }
    }

    public function test_only_collection_statuses_remain_in_the_queue_contract(): void
    {
        preg_match(
            '/private function queueStatuses\(\): array\s*\{(?<body>.*?)\n\s*\}/s',
            $this->controller,
            $matches
        );
        $body = $matches['body'] ?? '';

        $this->assertStringContainsString('Invoice::STATUS_PENDING_COLLECTION', $body);
        $this->assertStringContainsString('Invoice::STATUS_WAREHOUSE_RECEIVED', $body);
        $this->assertStringContainsString('Invoice::STATUS_COLLECTING', $body);
        $this->assertStringNotContainsString('Invoice::STATUS_READY_TO_SHIP', $body);
        $this->assertStringNotContainsString('Invoice::STATUS_SHIPPED', $body);
    }

    public function test_auto_refresh_requests_and_replaces_only_the_current_page(): void
    {
        $this->assertStringContainsString('const currentPage = @json($invoices->currentPage());', $this->view);
        $this->assertStringContainsString('const perPage = @json($invoices->perPage());', $this->view);
        $this->assertStringContainsString("refreshUrl.searchParams.set('page', currentPage);", $this->view);
        $this->assertStringContainsString("refreshUrl.searchParams.set('per_page', perPage);", $this->view);
        $this->assertStringContainsString('id="collectionQueueCount"', $this->view);
        $this->assertStringContainsString('Number(data.total).toLocaleString()', $this->view);
    }

    public function test_desktop_and_mobile_render_the_same_paginated_collection(): void
    {
        $this->assertSame(2, substr_count($this->view, '@forelse($invoices as $inv)'));
        $this->assertStringContainsString('rows.map(renderCollectionDesktopRow)', $this->view);
        $this->assertStringContainsString('rows.map(renderCollectionMobileCard)', $this->view);
        $this->assertStringContainsString('{{ $invoices->links() }}', $this->view);
    }
}
