<?php

use App\Services\Inventory\InventoryCorruptionAuditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('rejects every apply request before audit calculations or database access', function (array $options): void {
    $audit = Mockery::mock(InventoryCorruptionAuditService::class);
    $audit->shouldNotReceive('rows');
    $audit->shouldNotReceive('centralWarehouseId');
    $audit->shouldNotReceive('inactiveDraftReservationIds');
    app()->instance(InventoryCorruptionAuditService::class, $audit);

    // Throw before executing SQL, including CREATE TABLE for legacy backups.
    // No query means existing adjustment/return stock, reservations and prices
    // cannot be overwritten, irrespective of their values or origin.
    DB::connection()->beforeExecuting(function (): void {
        throw new RuntimeException('Apply rejection must not execute SQL.');
    });

    $exitCode = Artisan::call('inventory:repair-corruption', $options);

    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('APPLY mode is disabled')
        ->and($output)->toContain('traceable adjustment documents');
})->with([
    'apply without selector' => [['--apply' => true]],
    'all products' => [['--apply' => true, '--all' => true]],
    'one product' => [['--apply' => true, '--product' => '24']],
    'force' => [['--apply' => true, '--all' => true, '--force' => true]],
    'backup' => [['--apply' => true, '--all' => true, '--backup' => true]],
    'all legacy bypass flags' => [['--apply' => true, '--all' => true, '--backup' => true, '--force' => true]],
    'product with legacy bypass flags' => [['--apply' => true, '--product' => '24', '--backup' => true, '--force' => true]],
    'dry-run does not override apply rejection' => [['--apply' => true, '--all' => true, '--dry-run' => true, '--backup' => true, '--force' => true]],
]);

it('retains read-only diagnostics with an explicit non-authoritative warning', function (): void {
    $audit = Mockery::mock(InventoryCorruptionAuditService::class);
    $audit->shouldReceive('rows')->once()->with(24)->andReturn(collect());
    app()->instance(InventoryCorruptionAuditService::class, $audit);

    $exitCode = Artisan::call('inventory:repair-corruption', ['--product' => '24']);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('DIAGNOSTIC ONLY')
        ->and($output)->toContain('not authoritative')
        ->and($output)->not->toContain('planned_for_update');
});

it('still requires a selector for diagnostics', function (): void {
    $audit = Mockery::mock(InventoryCorruptionAuditService::class);
    $audit->shouldNotReceive('rows');
    app()->instance(InventoryCorruptionAuditService::class, $audit);

    expect(Artisan::call('inventory:repair-corruption'))->toBe(1)
        ->and(Artisan::output())->toContain('Use --product=<id> or --all');
});
