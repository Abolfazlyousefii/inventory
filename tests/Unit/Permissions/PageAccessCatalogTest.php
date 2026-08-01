<?php

use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;

it('defines unique page keys and page permissions', function () {
    $pages = PageAccessCatalog::pages();

    expect($pages)->not->toBeEmpty()
        ->and(array_keys($pages))->toHaveCount(count(array_unique(array_keys($pages))))
        ->and(collect($pages)->pluck('permission')->all())->toHaveCount(count(array_unique(collect($pages)->pluck('permission')->all())));

    foreach ($pages as $key => $page) {
        expect($page['permission'])->toBe('page.'.$key)
            ->and($page['label'])->not->toBeEmpty()
            ->and($page['group'])->not->toBeEmpty()
            ->and($page['legacy_permissions'])->toBeArray()
            ->and($page['routes'])->toBeArray();
    }
});

it('maps every legacy route permission to one page permission', function () {
    $unmapped = collect(PermissionCatalog::routePermissions())
        ->filter(fn ($legacyPermission) => PageAccessCatalog::pagePermissionForLegacy($legacyPermission) === null)
        ->unique()->sort()->values()->all();

    expect($unmapped)->toBe([], json_encode($unmapped));
});

it('keeps sensitive preinvoice workflows separate', function () {
    expect(PageAccessCatalog::pagePermissionForLegacy('preinvoices.create'))->toBe('page.sales.preinvoices')
        ->and(PageAccessCatalog::pagePermissionForLegacy('preinvoices.warehouse.confirm'))->toBe('page.sales.preinvoice_warehouse_review')
        ->and(PageAccessCatalog::pagePermissionForLegacy('preinvoices.finance.confirm'))->toBe('page.sales.preinvoice_finance_review');
});
