<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports a legacy Site invoice collision even when the invoice has no preinvoice link', function () {
    PreinvoiceOrder::query()->create([
        'uuid' => '02187',
        'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
        'customer_name' => 'Example',
        'customer_mobile' => '09120000000',
        'total_price' => 100_000,
    ]);
    Invoice::query()->create([
        'uuid' => '02187',
        'external_order_id' => 88,
        'subtotal' => 100_000,
        'total' => 100_000,
    ]);

    $this->artisan('invoices:number-audit', ['--dry-run' => true, '--preinvoice' => '02187'])
        ->expectsOutput('Legacy invoice number conflicts: 1')
        ->expectsOutput('Duplicate invoice uuid groups: 0')
        ->assertSuccessful();

    expect(Invoice::query()->where('uuid', '02187')->count())->toBe(1)
        ->and(PreinvoiceOrder::query()->where('uuid', '02187')->count())->toBe(1);
});
