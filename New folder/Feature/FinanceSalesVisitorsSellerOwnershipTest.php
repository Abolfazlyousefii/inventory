<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsSellerOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_effective_seller_priority_and_not_crm_id(): void
    {
        $seller = User::factory()->create([
            'name' => 'فروشنده داخلی',
            'crm_user_id' => '987654',
            'is_seller' => true,
        ]);
        $other = User::factory()->create(['is_seller' => true]);
        $preinvoice = PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $other->id,
            'seller_id' => $seller->id,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => '09122222222',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        ]);

        $this->invoice($seller->id, null, 1000);
        $this->invoice($other->id, null, 2000);
        $this->invoice(null, $preinvoice->id, 3000);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
        ]));

        $response->assertOk()
            ->assertSee('value="' . $seller->id . '"', false)
            ->assertDontSee('value="987654"', false);
        $row = collect($response->viewData('rows'))->sole();
        $this->assertSame($seller->id, $row['user_id']);
        $this->assertSame(2, $row['invoice_count']);
        $this->assertSame(4000, $row['total_sales']);

        $sellerResponse = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $other->id,
        ]));
        $otherRow = collect($sellerResponse->viewData('rows'))->sole();
        $this->assertSame(1, $otherRow['invoice_count']);
        $this->assertSame(2000, $otherRow['total_sales']);

        $crmResponse = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => 987654,
        ]));
        $this->assertTrue(collect($crmResponse->viewData('rows'))->isEmpty());
    }

    public function test_preinvoice_conversion_transfers_only_the_explicit_seller_and_official_date(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/PreinvoiceController.php'));

        $this->assertStringContainsString("'seller_id' => \$order->seller_id,", $source);
        $this->assertStringContainsString("'document_date' => \$order->display_document_date,", $source);
        $this->assertStringNotContainsString("'seller_id' => \$order->seller_id ?: \$order->created_by", $source);
    }

    private function invoice(?int $sellerId, ?int $preinvoiceId, int $total): Invoice
    {
        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'seller_id' => $sellerId,
            'preinvoice_order_id' => $preinvoiceId,
            'document_date' => '2026-07-15 10:00:00',
            'customer_name' => 'مشتری تست',
            'subtotal' => $total,
            'total' => $total,
            'status' => Invoice::STATUS_SHIPPED,
        ]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));

        return $owner;
    }
}
