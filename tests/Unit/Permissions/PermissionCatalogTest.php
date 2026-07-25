<?php

use App\Services\Permissions\PermissionDependencyResolver;
use App\Support\PermissionCatalog;

it('normalizes transitive permission dependencies', function () {
    $keys = app(PermissionDependencyResolver::class)->normalize(['products.edit']);
    expect($keys)->toContain('products.edit', 'products.show', 'products.view');
});

it('normalizes critical invoice dependencies', function () {
    $keys = app(PermissionDependencyResolver::class)->normalize(['invoices.cancel']);
    expect($keys)->toContain('invoices.cancel', 'invoices.show', 'invoices.view')
        ->and(PermissionCatalog::registry()['invoices.cancel']['risk'])->toBe('critical');
});

it('keeps legacy permissions out of assignable keys', function () {
    expect(PermissionCatalog::activeKeys())->not->toContain('preinvoices.warehouse.view')
        ->and(PermissionCatalog::registry()['preinvoices.warehouse.view']['deprecated'])->toBeTrue();
});

it('keeps collection and shipping permissions independent', function () {
    expect(PermissionCatalog::registry())->toHaveKeys([
        'warehouse.collection.queue.view',
        'warehouse.shipping.queue.view',
        'warehouse.shipping.ship',
    ]);
});
