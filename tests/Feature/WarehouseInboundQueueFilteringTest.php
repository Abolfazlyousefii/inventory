<?php

namespace Tests\Feature;

use App\Http\Controllers\WarehouseInboundController;
use App\Http\Requests\ReceiveWarehouseInboundReceiptRequest;
use App\Models\WarehouseInboundReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class WarehouseInboundQueueFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_status_and_numeric_receipt_id_filters_are_exact(): void
    {
        $pending = $this->receipt(WarehouseInboundReceipt::SOURCE_SALES_RETURN, WarehouseInboundReceipt::STATUS_PENDING, 'SR-100', 'Customer A');
        $received = $this->receipt(WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL, WarehouseInboundReceipt::STATUS_RECEIVED, 'INV-200', 'Customer B');
        $discrepancy = $this->receipt(WarehouseInboundReceipt::SOURCE_INVOICE_ADJUSTMENT, WarehouseInboundReceipt::STATUS_DISCREPANCY, 'INV-300', 'Customer C');

        $this->assertSame([$pending->id], $this->ids(['source_type' => WarehouseInboundReceipt::SOURCE_SALES_RETURN, 'status' => 'all']));
        $this->assertSame([$received->id], $this->ids(['status' => WarehouseInboundReceipt::STATUS_RECEIVED]));
        $this->assertContains($discrepancy->id, $this->ids(['q' => (string) $discrepancy->id, 'status' => 'all']));
        $this->assertSame([$received->id], $this->ids(['q' => 'INV-200', 'status' => 'all']));
        $this->assertSame([$pending->id], $this->ids(['q' => 'Customer A', 'status' => 'all']));
    }

    public function test_pagination_keeps_active_filters_on_page_two(): void
    {
        foreach (range(1, 21) as $number) {
            $this->receipt(WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL, WarehouseInboundReceipt::STATUS_PENDING, 'PAGE-'.$number, 'Paged');
        }

        $request = $this->request(['source_type' => WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL, 'status' => 'pending', 'page' => 2]);
        $view = app(WarehouseInboundController::class)->index($request);
        $receipts = $view->getData()['receipts'];

        $this->assertSame(2, $receipts->currentPage());
        $this->assertSame(1, $receipts->count());
        $this->assertStringContainsString('source_type=invoice_cancel', $receipts->url(2));
        $this->assertStringContainsString('status=pending', $receipts->url(2));
    }

    public function test_receive_request_rejects_negative_decimal_and_non_numeric_quantities_but_accepts_zero(): void
    {
        $rules = (new ReceiveWarehouseInboundReceiptRequest)->rules();

        foreach ([-1, 1.5, 'abc'] as $invalid) {
            $validator = Validator::make(['items' => [[
                'id' => 1,
                'accepted_quantity' => $invalid,
                'received_warehouse_id' => 999999,
            ]]], $rules);
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey('items.0.accepted_quantity', $validator->errors()->toArray());
        }

        $validator = Validator::make(['items' => [[
            'id' => 1,
            'accepted_quantity' => 0,
            'received_warehouse_id' => 999999,
        ]]], $rules);
        $this->assertArrayNotHasKey('items.0.accepted_quantity', $validator->errors()->toArray());
    }

    private function ids(array $query): array
    {
        $view = app(WarehouseInboundController::class)->index($this->request($query));

        return collect($view->getData()['receipts']->items())->pluck('id')->all();
    }

    private function request(array $query): Request
    {
        $request = Request::create('/warehouse/inbound-queue', 'GET', $query);
        $this->app->instance('request', $request);
        return $request;
    }

    private function receipt(string $source, string $status, string $sourceNumber, string $customer): WarehouseInboundReceipt
    {
        return WarehouseInboundReceipt::query()->create([
            'receipt_number' => 'WIR-'.fake()->unique()->numerify('######'),
            'source_type' => $source,
            'source_id' => fake()->unique()->numberBetween(1000, 999999),
            'operation_key' => 'filter-test',
            'source_number_snapshot' => $sourceNumber,
            'customer_name_snapshot' => $customer,
            'status' => $status,
            'expected_quantity' => 1,
            'accepted_quantity' => $status === WarehouseInboundReceipt::STATUS_PENDING ? 0 : 1,
        ]);
    }
}
