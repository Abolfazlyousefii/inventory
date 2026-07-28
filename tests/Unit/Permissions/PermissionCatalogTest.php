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

it('resolves canonical and aliased role labels without using arrays as keys', function () {
    expect(PermissionCatalog::canonicalRoleKey('Admin'))->toBe('system_admin')
        ->and(PermissionCatalog::roleLabel('Admin'))->toBe('مدیر سیستم')
        ->and(PermissionCatalog::canonicalRoleKey('Sales'))->toBe('sales_user')
        ->and(PermissionCatalog::roleLabel('Sales'))->toBe('فروشنده')
        ->and(PermissionCatalog::isLegacyRole('Admin'))->toBeTrue();
});

it('falls back safely for unknown roles', function () {
    expect(PermissionCatalog::canonicalRoleKey('LegacyCustomRole'))->toBeNull()
        ->and(PermissionCatalog::roleLabel('LegacyCustomRole'))->toBe('LegacyCustomRole')
        ->and(PermissionCatalog::isLegacyRole('LegacyCustomRole'))->toBeTrue();
});

it('rejects unknown and deprecated dependencies at the backend boundary', function () {
    $resolver = app(PermissionDependencyResolver::class);

    expect(fn () => $resolver->normalize(['not.registered']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resolver->normalize(['preinvoices.warehouse.view']))->toThrow(InvalidArgumentException::class);
});
