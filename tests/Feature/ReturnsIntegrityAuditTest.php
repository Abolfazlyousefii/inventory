<?php

use App\Services\ReturnsIntegrityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('requires dry run and never exposes an apply operation', function (): void {
    $this->artisan('returns:audit')->assertExitCode(1);
});

it('reports missing historical documents without creating any warehouse or ledger', function (): void {
    $tables = ['warehouse_stocks', 'warehouses', 'sales_return_documents', 'purchase_returns', 'stock_movements', 'customer_ledgers', 'supplier_ledgers'];
    $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
    $report = app(ReturnsIntegrityAuditService::class)->report('432', '00004');
    expect($report['data_changed'])->toBeFalse()
        ->and($report['sales_return']['document'])->toBeNull()
        ->and($report['repair_preview']['status'])->toBe('needs_physical_confirmation')
        ->and($report['repair_preview']['financial_credit_to_post'])->toBe(0);
    expect(array_map(fn ($t) => DB::table($t)->count(), $tables))->toBe($before);
    $this->artisan('returns:audit --dry-run --json')->assertSuccessful();
});
