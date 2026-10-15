<?php

namespace Tests\Feature;

use App\Models\CustomerInvoicePaymentSetting;
use App\Models\User;
use App\Services\CustomerInvoicePaymentSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerInvoicePaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::factory()->create();
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');

        return $user;
    }

    private function details(): array
    {
        return [
            'name' => 'Sample Recipient',
            'national_id' => '0012345678',
            'iban' => 'IR123456789012345678901234',
            'account' => '000123456789',
            'card' => '1234-5678-9012-3456',
        ];
    }

    public function test_authorized_administrator_can_edit_encrypted_payment_details_from_configuration(): void
    {
        $this->actingAs($this->administrator())
            ->get(route('admin.invoice-payment-settings.edit'))
            ->assertOk()->assertSee('مشخصات واریز فاکتور');

        $this->put(route('admin.invoice-payment-settings.update'), $this->details())
            ->assertRedirect(route('admin.invoice-payment-settings.edit'))
            ->assertSessionHas('success');

        $this->assertSame(1, CustomerInvoicePaymentSetting::query()->count());
        $this->assertSame($this->details(), app(CustomerInvoicePaymentSettingsService::class)->details());
        $raw = DB::table('customer_invoice_payment_settings')->first();
        $this->assertNotSame($this->details()['iban'], $raw->iban);
        $this->assertNotSame($this->details()['national_id'], $raw->national_id);

        $this->get(route('admin.invoice-payment-settings.edit'))
            ->assertOk()
            ->assertSee('Sample Recipient')
            ->assertSee('IR123456789012345678901234');
    }

    public function test_view_and_update_are_forbidden_for_user_without_settings_edit_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.invoice-payment-settings.edit'))
            ->assertForbidden();

        $this->put(route('admin.invoice-payment-settings.update'), $this->details())
            ->assertForbidden();

        $this->assertSame(0, CustomerInvoicePaymentSetting::query()->count());
    }

    public function test_invalid_bank_identifiers_cannot_overwrite_saved_details(): void
    {
        $this->actingAs($this->administrator());
        $this->put(route('admin.invoice-payment-settings.update'), $this->details())
            ->assertSessionHasNoErrors();

        $invalid = $this->details();
        $invalid['iban'] = 'INVALID';
        $invalid['card'] = '123';
        $invalid['national_id'] = 'xx';

        $this->put(route('admin.invoice-payment-settings.update'), $invalid)
            ->assertSessionHasErrors(['iban', 'card', 'national_id']);

        $this->assertSame($this->details(), app(CustomerInvoicePaymentSettingsService::class)->details());
    }

    public function test_database_configuration_overrides_private_env_fallback_and_is_used_by_invoice_print_service(): void
    {
        config()->set('customer_invoice_payment', [
            'name' => 'Environment fallback',
            'national_id' => '',
            'iban' => '',
            'account' => '',
            'card' => '',
        ]);

        $this->assertSame('Environment fallback', app(CustomerInvoicePaymentSettingsService::class)->details()['name']);

        $this->actingAs($this->administrator());
        $this->put(route('admin.invoice-payment-settings.update'), $this->details())
            ->assertSessionHasNoErrors();

        $this->assertSame('Sample Recipient', app(CustomerInvoicePaymentSettingsService::class)->details()['name']);

        $invoice = new \App\Models\Invoice([
            'uuid' => 'TEST-PAYMENT-SETTINGS',
            'customer_name' => 'Test customer',
            'total' => 0,
            'status' => \App\Models\Invoice::STATUS_SHIPPED,
        ]);
        $invoice->setRelation('items', new \Illuminate\Support\Collection());
        $invoice->setRelation('payments', new \Illuminate\Support\Collection());
        $invoice->setRelation('customer', null);
        $invoice->setRelation('seller', null);
        $invoice->setRelation('preinvoiceOrder', null);
        $invoice->setRelation('shippingMethod', null);

        $printer = app(\App\Services\SalesPrintDocumentService::class);
        $printData = $printer->invoiceData($invoice, 'customer');
        $this->assertSame($this->details(), $printData['customerPayment']);
        $this->assertNull($printer->invoiceData($invoice, 'warehouse')['customerPayment']);
    }
}
