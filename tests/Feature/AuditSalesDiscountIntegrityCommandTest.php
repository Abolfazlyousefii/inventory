<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('audits without writing by default and accepts the explicit dry-run option', function () {
    $this->artisan('sales:audit-discount-integrity')
        ->expectsOutputToContain('Mode: dry-run')
        ->assertSuccessful();

    $this->artisan('sales:audit-discount-integrity', ['--dry-run' => true])
        ->expectsOutputToContain('Mode: dry-run')
        ->assertSuccessful();
});

it('supports a targeted invoice repair invocation without hardcoded document numbers', function () {
    $command = file_get_contents(app_path('Console/Commands/AuditSalesDiscountIntegrity.php'));

    expect($command)
        ->toContain('{--invoice-number=')
        ->toContain('{--repair')
        ->toContain('lockForUpdate()')
        ->toContain('invoice_collection_revision_items')
        ->toContain('proportionalLineDiscount')
        ->not->toContain("'00614'")
        ->not->toContain('"00614"');
});
