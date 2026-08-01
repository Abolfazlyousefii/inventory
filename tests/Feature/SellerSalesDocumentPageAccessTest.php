<?php

use App\Support\PageAccessCatalog;

it('registers seller sales documents as the thirty sixth page with finance defaults', function () {
    expect(PageAccessCatalog::pages())->toHaveCount(36)
        ->and(PageAccessCatalog::page('finance.seller_sales_documents')['permission'])->toBe('page.finance.seller_sales_documents')
        ->and(PageAccessCatalog::page('finance.seller_sales_documents')['routes'])->toHaveCount(8)
        ->and(config('role_page_defaults.roles.Accountant.page_permissions'))->toContain('page.finance.seller_sales_documents')
        ->and(config('role_page_defaults.roles.Manager.page_permissions'))->toContain('page.finance.seller_sales_documents')
        ->and(config('role_page_defaults.roles.Sales.page_permissions'))->not->toContain('page.finance.seller_sales_documents')
        ->and(config('role_page_defaults.roles.StorageUser.page_permissions'))->not->toContain('page.finance.seller_sales_documents');
});
