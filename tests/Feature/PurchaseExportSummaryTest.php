<?php

namespace Tests\Feature;

use App\Exports\PurchasesExport;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseExportSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_export_query_returns_one_row_per_purchase_not_items(): void
    {
        $supplier = Supplier::query()->create(['name' => 'تامین‌کننده تست']);
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-07-01 09:00:00',
            'total_amount' => 123456,
        ]);
        PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_name' => 'کالا ۱', 'product_code' => 'P1', 'quantity' => 1, 'buy_price' => 1000, 'sell_price' => 1500, 'line_total' => 1000]);
        PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_name' => 'کالا ۲', 'product_code' => 'P2', 'quantity' => 2, 'buy_price' => 2000, 'sell_price' => 2500, 'line_total' => 4000]);

        $export = new PurchasesExport(['supplier_id' => $supplier->id]);
        $rows = $export->query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame(123456, (int) $export->map($rows->first())[4]);
    }
}
