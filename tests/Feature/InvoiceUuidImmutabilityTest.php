<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceUuidImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_uuid_cannot_change_after_create(): void
    {
        $invoice = Invoice::query()->create([
            'uuid' => '00001',
            'customer_name' => 'Test',
            'status' => Invoice::STATUS_COLLECTING,
            'subtotal' => 0,
            'total' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $invoice->update(['uuid' => '00002']);
    }
}
