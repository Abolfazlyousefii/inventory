<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReturnDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_sazeh_draft_stores_new_product_payload_without_creating_real_product(): void
    {
        $user = User::factory()->create();
        Warehouse::create(['name' => 'انبار مرکزی', 'type' => 'central', 'is_active' => true]);
        Warehouse::create(['name' => 'انبار مرجوعی', 'type' => 'return', 'is_active' => true]);
        $customer = Customer::create(['first_name' => 'Ali', 'last_name' => 'Test', 'mobile' => '0912']);
        $beforeProducts = \DB::table('products')->count();
        $beforeVariants = \DB::table('product_variants')->count();

        $document = app(SalesReturnService::class)->createDraft([
            'source_type' => SalesReturnDocument::SOURCE_SAZEH_HESAB,
            'customer_id' => $customer->id,
            'external_invoice_number' => 'SZ-1',
            'external_invoice_date' => '2026-07-12',
            'items' => [[
                'return_quantity' => 1,
                'item_condition' => 'healthy',
                'refund_unit_price' => 1000,
                'new_product_payload' => ['product_name' => 'New', 'variant_name' => 'V1', 'category_id' => 1, 'purchase_price' => 500, 'sell_price' => 1500],
            ]],
        ], $user);

        $this->assertTrue($document->isDraft());
        $this->assertSame($beforeProducts, \DB::table('products')->count());
        $this->assertSame($beforeVariants, \DB::table('product_variants')->count());
    }

    public function test_cancel_draft_does_not_delete_items(): void
    {
        $this->markTestIncomplete('Covered in phase-one integration environment with warehouses and permissions seeded.');
    }
}
