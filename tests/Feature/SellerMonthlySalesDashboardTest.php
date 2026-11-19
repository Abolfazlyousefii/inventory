<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SellerMonthlySalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_monthly_sales_are_isolated_and_use_effective_invoice_owner(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        try {
            $sellerA = $this->seller();
            $sellerB = $this->seller();
            // A preinvoice can be converted to only one invoice.
            $this->invoice($this->preinvoice($sellerA), null, '2026-11-18 09:00:00', 1_250_000);
            $this->invoice($this->preinvoice($sellerB), $sellerA, '2026-11-18 09:30:00', 2_000_000);
            $this->invoice($this->preinvoice($sellerB), null, '2026-11-18 10:00:00', 9_000_000);
            $this->invoice($this->preinvoice($sellerA), null, '2026-10-01 10:00:00', 7_000_000);
            $this->invoice($this->preinvoice($sellerA), null, '2026-11-18 10:00:00', 5_000_000, Invoice::STATUS_NOT_SHIPPED);

            $this->actingAs($sellerA)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('عملکرد فروش من')
                ->assertSee('آبان ۱۴۰۵')
                ->assertSee('۳۲۵,۰۰۰')
                ->assertSee('۳</bdi>', false)
                ->assertDontSee('۹۰۰,۰۰۰');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_seller_does_not_see_monthly_sales_widget(): void
    {
        $user = User::factory()->create(['is_seller' => false]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('عملکرد فروش من');
    }

    public function test_last_jalali_day_has_zero_days_remaining(): void
    {
        Carbon::setTestNow('2026-11-21 10:00:00');
        try {
            $seller = $this->seller();
            $this->actingAs($seller)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('آبان ۱۴۰۵')
                ->assertSee('۰</bdi>', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seller(): User
    {
        $user = User::factory()->create([
            'is_seller' => true,
            'is_active' => true,
            'can_access_erp' => true,
        ]);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        return $user;
    }

    private function preinvoice(User $seller): PreinvoiceOrder
    {
        return PreinvoiceOrder::query()->create([
            'uuid' => (string) str()->uuid(),
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'Test customer',
            'customer_mobile' => '09120000000',
            'customer_address' => 'Tehran',
            'province_id' => 1,
            'shipping_id' => 0,
        ]);
    }

    private function invoice(PreinvoiceOrder $order, ?User $seller, string $date, int $amount, string $status = Invoice::STATUS_SHIPPED): Invoice
    {
        return Invoice::query()->create([
            'uuid' => (string) str()->uuid(),
            'preinvoice_order_id' => $order->id,
            'seller_id' => $seller?->id,
            'document_date' => $date,
            'customer_name' => 'Test customer',
            'subtotal' => $amount,
            'total' => $amount,
            'status' => $status,
        ]);
    }
}
