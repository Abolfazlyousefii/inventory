<?php

$all = [
    'page.dashboard','page.products','page.products.price_changes','page.categories','page.brands_models','page.units','page.shipping_methods','page.warehouses','page.warehouse.stocks','page.warehouse.stocktake','page.warehouse.purchases','page.warehouse.issues','page.warehouse.collection','page.warehouse.shipping','page.warehouse.reservations','page.warehouse.transfers','page.warehouse.receipts','page.warehouse.map','page.assets','page.sales.preinvoices','page.commercial.commissions','page.sales.preinvoice_warehouse_review','page.sales.preinvoice_finance_review','page.sales.invoices','page.sales.returns','page.customers','page.suppliers','page.finance.payments','page.finance.accounts','page.finance.seller_sales_documents','page.reports','page.tickets','page.users','page.roles','page.settings','page.activity_logs','page.integrations.inventory',
];

$defaults = [
    'Owner' => $all,
    'Admin' => $all,
    'Manager' => array_values(array_diff($all, ['page.users','page.roles','page.settings','page.activity_logs','page.integrations.inventory'])),
    'InternalManager' => ['page.dashboard','page.products','page.products.price_changes','page.categories','page.brands_models','page.units','page.warehouses','page.warehouse.stocks','page.warehouse.purchases','page.warehouse.issues','page.warehouse.transfers','page.warehouse.receipts','page.warehouse.map','page.sales.preinvoices','page.sales.invoices','page.sales.returns','page.customers','page.suppliers','page.reports'],
    'ITManager' => ['page.dashboard','page.users','page.roles','page.settings','page.activity_logs','page.integrations.inventory','page.tickets'],
    'ITUser' => ['page.dashboard','page.integrations.inventory','page.tickets'],
    'SaleManager' => ['page.dashboard','page.products','page.customers','page.sales.preinvoices','page.sales.invoices','page.sales.returns','page.reports','page.tickets'],
    'Sales' => ['page.dashboard','page.products','page.customers','page.sales.preinvoices','page.sales.invoices','page.sales.returns'],
    'SaleUser' => ['page.dashboard','page.products','page.customers','page.sales.preinvoices'],
    'Marketer' => ['page.dashboard','page.products','page.customers','page.sales.preinvoices'],
    'StorageManager' => ['page.dashboard','page.products','page.categories','page.brands_models','page.units','page.warehouses','page.warehouse.stocks','page.warehouse.stocktake','page.warehouse.purchases','page.warehouse.issues','page.warehouse.collection','page.warehouse.shipping','page.warehouse.reservations','page.warehouse.transfers','page.warehouse.receipts','page.warehouse.map','page.assets','page.sales.preinvoice_warehouse_review'],
    'StorageUser' => ['page.dashboard','page.products','page.warehouse.stocks','page.warehouse.purchases','page.warehouse.issues','page.warehouse.collection','page.warehouse.shipping','page.warehouse.reservations','page.warehouse.transfers','page.warehouse.receipts','page.warehouse.map','page.sales.preinvoice_warehouse_review'],
    'Accountant' => ['page.dashboard','page.customers','page.sales.invoices','page.sales.preinvoice_finance_review','page.finance.payments','page.finance.accounts','page.finance.seller_sales_documents','page.reports'],
    'customer_review' => ['page.dashboard','page.customers'],
    'User' => ['page.dashboard'],
    'Guest' => ['page.dashboard'],
];

return [
    'roles' => collect($defaults)->map(fn (array $pages, string $role) => [
        'page_permissions' => $pages,
        'confirmed' => true,
        'disabled' => false,
        'super_admin' => $role === 'Owner',
        'intentionally_empty' => false,
    ])->all(),
    'required_before_apply' => array_keys($defaults),
];
