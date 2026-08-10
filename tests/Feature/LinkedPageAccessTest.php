<?php

use App\Support\PageAccessCatalog;

it('lets source pages access explicitly linked workflow routes', function () {
    expect(PageAccessCatalog::permissionsForRoute('vouchers.sales.show'))
        ->toContain(
            'page.warehouse.issues',
            'page.warehouse.collection',
            'page.warehouse.shipping',
            'page.sales.preinvoices',
        )
        ->and(PageAccessCatalog::permissionsForRoute('preinvoice.print'))
        ->toContain(
            'page.sales.preinvoices',
            'page.sales.preinvoice_warehouse_review',
        )
        ->and(PageAccessCatalog::permissionsForRoute('invoices.show'))
        ->toContain(
            'page.sales.invoices',
            'page.sales.preinvoice_finance_review',
            'page.sales.preinvoices',
            'page.warehouse.issues',
        );
});

it('does not turn linked route access into full destination page access', function () {
    expect(PageAccessCatalog::permissionsForRoute('purchases.show'))
        ->toContain('page.products')
        ->and(PageAccessCatalog::permissionsForRoute('purchases.destroy'))
        ->not->toContain('page.products');
});
