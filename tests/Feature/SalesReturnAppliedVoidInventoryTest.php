<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnAppliedVoidInventoryTest extends TestCase
{
    private function serviceSource(): string
    {
        return file_get_contents(app_path('Services/SalesReturnAppliedAdjustmentService.php'));
    }

    public function test_void_applied_return_restores_destination_stock(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('sales_return_void_reversal', $service);
        $this->assertStringContainsString('WarehouseStockService::change($group', $service);
    }

    public function test_void_central_return_syncs_variant_and_product_stock(): void
    {
        $this->assertStringContainsString('WarehouseStockService::change', $this->serviceSource());
    }

    public function test_void_return_warehouse_does_not_change_central_variant_stock(): void
    {
        $this->assertStringNotContainsString('ProductVariant::', $this->serviceSource());
    }

    public function test_multiple_items_same_variant_are_aggregated_safely(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('groupBy(fn ($item) => implode', $service);
        $this->assertStringContainsString("sum('return_quantity')", $service);
    }

    public function test_insufficient_stock_rolls_back_void(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('assertReturnInventoryAvailable($doc);', $service);
        $this->assertStringContainsString('امکان ابطال این برگشت از فروش وجود ندارد', $service);
        $this->assertStringContainsString('DB::transaction(function () use ($document, $actorId, $reason)', $service);
    }

    public function test_void_debits_customer_once(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('CustomerLedger::firstOrCreate', $service);
        $this->assertStringContainsString("'type'=>'debit'", $service);
        $this->assertStringContainsString('این سند قبلاً ابطال شده است', $service);
    }
}
