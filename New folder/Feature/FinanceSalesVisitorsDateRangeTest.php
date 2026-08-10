<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Morilog\Jalali\Jalalian;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_jalali_range_is_converted_once_and_includes_both_document_date_boundaries(): void
    {
        $seller = User::factory()->create(['is_seller' => true]);
        $from = Jalalian::fromFormat('Y/m/d', '1405/04/10')->toCarbon();
        $to = Jalalian::fromFormat('Y/m/d', '1405/05/10')->toCarbon();

        $this->invoice($seller, $from->copy()->startOfDay(), 1000);
        $this->invoice($seller, $to->copy()->endOfDay(), 2000);
        $this->invoice($seller, $from->copy()->subSecond(), 4000);
        $this->invoice($seller, $to->copy()->addDay()->startOfDay(), 8000);

        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'user_id' => $seller->id,
            'date_from' => '۱۴۰۵/۰۴/۱۰',
            'date_to' => '۱۴۰۵/۰۵/۱۰',
        ]));

        $response->assertOk();
        $this->assertSame('1405/04/10', $response->viewData('filters')['date_from']);
        $this->assertSame('1405/05/10', $response->viewData('filters')['date_to']);
        $row = collect($response->viewData('rows'))->sole();
        $this->assertSame(2, $row['invoice_count']);
        $this->assertSame(3000, $row['total_sales']);
    }

    public function test_gregorian_query_string_is_displayed_as_the_equivalent_jalali_range(): void
    {
        $response = $this->actingAs($this->owner())->get(route('finance.reports.sales-visitors', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-01',
        ]));

        $response->assertOk();
        $this->assertSame('1405/04/10', $response->viewData('filters')['date_from']);
        $this->assertSame('1405/05/10', $response->viewData('filters')['date_to']);
    }

    private function invoice(User $seller, $documentDate, int $total): Invoice
    {
        $order = PreinvoiceOrder::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری تست',
            'customer_mobile' => fake()->unique()->numerify('09#########'),
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);

        return Invoice::query()->create([
            'uuid' => fake()->unique()->uuid(),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $seller->id,
            'document_date' => $documentDate,
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
