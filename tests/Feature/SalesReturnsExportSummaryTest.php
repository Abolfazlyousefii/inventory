<?php

namespace Tests\Feature;

use App\Exports\SalesReturnsExport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnsExportSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_returns_export_uses_one_summary_row_per_return_and_filters_customer_name(): void
    {
        $customer = Customer::query()->create(['first_name' => 'محمد', 'last_name' => 'رضایی']);
        $other = Customer::query()->create(['first_name' => 'علی', 'last_name' => 'کریمی']);
        $warehouse = Warehouse::query()->create(['name' => 'انبار مرجوعی', 'type' => 'return', 'is_active' => true]);
        $product = Product::query()->create(['name' => 'کالای تست']);

        $matching = WarehouseTransfer::query()->create([
            'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'customer_id' => $customer->id,
            'to_warehouse_id' => $warehouse->id,
            'reference' => 'RET-100',
            'transferred_at' => '2026-07-01 10:00:00',
            'total_amount' => 3000,
        ]);
        WarehouseTransferItem::query()->create(['warehouse_transfer_id' => $matching->id, 'product_id' => $product->id, 'quantity' => 1, 'line_total' => 1000, 'return_kind' => 'healthy', 'destination_warehouse_id' => $warehouse->id]);
        WarehouseTransferItem::query()->create(['warehouse_transfer_id' => $matching->id, 'product_id' => $product->id, 'quantity' => 2, 'line_total' => 2000, 'return_kind' => 'damaged', 'destination_warehouse_id' => $warehouse->id]);

        WarehouseTransfer::query()->create([
            'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'customer_id' => $other->id,
            'to_warehouse_id' => $warehouse->id,
            'reference' => 'RET-200',
            'transferred_at' => '2026-07-02 10:00:00',
            'total_amount' => 9000,
        ]);

        $rows = SalesReturnsExport::baseQuery(['customer_name' => 'محمد'])->get();

        $this->assertCount(1, $rows);
        $this->assertSame('RET-100', SalesReturnsExport::documentNumber($rows->first()));
        $this->assertSame(3000, SalesReturnsExport::totalAmount($rows->first()));
        $this->assertSame('انبار مرجوعی', SalesReturnsExport::destinationWarehouseLabel($rows->first()));
        $this->assertSame('سالم و مرجوعی', SalesReturnsExport::returnKindLabel($rows->first()));
        $this->assertSame(1000, SalesReturnsExport::healthyAmount($rows->first()));
        $this->assertSame(2000, SalesReturnsExport::damagedAmount($rows->first()));

        $mixedRows = SalesReturnsExport::baseQuery(['return_kind' => 'mixed'])->get();
        $this->assertCount(1, $mixedRows);
        $this->assertSame('RET-100', SalesReturnsExport::documentNumber($mixedRows->first()));
    }
}
