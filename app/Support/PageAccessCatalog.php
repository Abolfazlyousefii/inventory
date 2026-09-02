<?php

namespace App\Support;

use App\Models\User;

class PageAccessCatalog
{
    private const PAGE_ACTION_PERMISSIONS = [
        'warehouse.reservations' => [
            'warehouse_reservations.view',
            'warehouse_reservations.release',
        ],
    ];

    /** Explicit runtime ownership. Legacy permissions are migration metadata only. */
    private const ROUTE_OWNERS = [
        'dashboard' => ['dashboard'],
        'dashboard.monthly-report' => ['dashboard'],
        'global-search' => ['dashboard'],
        'commercial.invoice-reassignments.index' => ['commercial.invoice_reassignments'],
        'commercial.invoice-reassignments.search' => ['commercial.invoice_reassignments'],
        'commercial.invoice-reassignments.preview' => ['commercial.invoice_reassignments'],
        'commercial.invoice-reassignments.store' => ['commercial.invoice_reassignments'],
        'commercial.commissions.index' => ['commercial.commissions'],
        'commercial.commissions.tree' => ['commercial.commissions'],
        'commercial.commissions.rates.store' => ['commercial.commissions'],
        'commercial.commissions.rates.destroy' => ['commercial.commissions'],
        'commercial.commissions.rates.history' => ['commercial.commissions'],
        'commercial.commissions.campaigns.store' => ['commercial.commissions'],
        'commercial.commissions.campaigns.update' => ['commercial.commissions'],
        'commercial.commissions.campaigns.destroy' => ['commercial.commissions'],
        'commercial.commissions.settings.update' => ['commercial.commissions'],
        'commercial.commissions.settings.features.update' => ['commercial.commissions'],
        'commercial.commissions.periods.store' => ['commercial.commissions'],
        'commercial.commissions.periods.recalculate' => ['commercial.commissions'],
        'commercial.commissions.targets.update' => ['commercial.commissions'],
        'commercial.commissions.targets.copy-previous' => ['commercial.commissions'],
        'commercial.commissions.periods.review' => ['commercial.commissions'],
        'commercial.commissions.periods.close' => ['commercial.commissions'],
        'commercial.commissions.periods.paid' => ['commercial.commissions'],
        'commercial.commissions.sellers.show' => ['commercial.commissions'],
        'commercial.commissions.sellers.invoices.show' => ['commercial.commissions'],
        'commercial.commissions.documents.store' => ['commercial.commissions'],
        'commercial.commissions.documents.show' => ['commercial.commissions'],
        'commercial.commissions.documents.update' => ['commercial.commissions'],
        'commercial.commissions.documents.invoice-search' => ['commercial.commissions'],
        'commercial.commissions.documents.invoices.store' => ['commercial.commissions'],
        'commercial.commissions.documents.refresh-candidates' => ['commercial.commissions'],
        'commercial.commissions.documents.refresh-calculations' => ['commercial.commissions'],
        'commercial.commissions.documents.items.approve' => ['commercial.commissions'],
        'commercial.commissions.documents.items.reject' => ['commercial.commissions'],
        'commercial.commissions.documents.items.remove' => ['commercial.commissions'],
        'commercial.commissions.documents.corrections.approve' => ['commercial.commissions'],
        'commercial.commissions.documents.corrections.reject' => ['commercial.commissions'],
        'commercial.commissions.documents.items.breakdown' => ['commercial.commissions'],
        'commercial.commissions.documents.print' => ['commercial.commissions'],
        'commercial.commissions.documents.finalize' => ['commercial.commissions'],
        'commercial.commissions.adjustments.store' => ['commercial.commissions'],
        'commercial.commissions.documents.adjustments.approve' => ['commercial.commissions'],
        'commercial.commissions.documents.adjustments.reject' => ['commercial.commissions'],
        'commercial.commissions.settlements.show' => ['commercial.commissions'],
        'commercial.commissions.settlements.print' => ['commercial.commissions'],
        'commercial.commissions.settlements.payments.store' => ['commercial.commissions'],
        'commercial.commissions.settlements.payments.void' => ['commercial.commissions'],
        'products.index' => ['products'],
        'products.data' => ['products'],
        'products.create' => ['products'],
        'products.store' => ['products'],
        'products.price-changes.index' => ['products.price_changes'],
        'products.price-changes.create' => ['products.price_changes'],
        'products.price-changes.categories.root' => ['products.price_changes'],
        'products.price-changes.categories.children' => ['products.price_changes'],
        'products.price-changes.products.search' => ['products.price_changes'],
        'products.price-changes.products.variants' => ['products.price_changes'],
        'products.price-changes.scope-summary' => ['products.price_changes'],
        'products.price-changes.preview' => ['products.price_changes'],
        'products.price-changes.store' => ['products.price_changes'],
        'products.price-changes.show' => ['products.price_changes'],
        'products.price-changes.apply' => ['products.price_changes'],
        'products.price-changes.cancel' => ['products.price_changes'],
        'products.variants' => ['products'],
        'products.edit' => ['products'],
        'products.update' => ['products'],
        'products.warehouse-stock' => ['products', 'warehouse.stocks'],
        'products.image' => ['products'],
        'products.destroy' => ['products'],
        'products.sales-ledger' => ['products'],
        'products.purchase-ledger' => ['products'],
        'categories.index' => ['categories'],
        'categories.create' => ['categories'],
        'categories.store' => ['categories'],
        'categories.edit' => ['categories'],
        'categories.update' => ['categories'],
        'categories.destroy' => ['categories'],
        'categories.fixCodes' => ['categories'],
        'products.pricelist' => ['products'],
        'admin.product-exports.index' => ['products'],
        'admin.product-exports.data' => ['products'],
        'admin.product-exports.print' => ['products'],
        'admin.product-exports.download' => ['products'],
        'admin.product-exports.model-lists' => ['products'],
        'admin.product-exports.products.search' => ['products'],
        'admin.product-exports.categories.children' => ['products'],
        'admin.product-exports.export' => ['products'],
        'products.sync.crm' => ['products'],
        'product-deactivation-documents.index' => ['products'],
        'product-deactivation-documents.create' => ['products'],
        'product-deactivation-documents.products.search' => ['products'],
        'product-deactivation-documents.products.variants' => ['products'],
        'product-deactivation-documents.bulk.create' => ['products'],
        'product-deactivation-documents.bulk.categories.children' => ['products'],
        'product-deactivation-documents.bulk.preview' => ['products'],
        'product-deactivation-documents.bulk.store' => ['products'],
        'product-deactivation-documents.store' => ['products'],
        'product-deactivation-documents.show' => ['products'],
        'model-lists.index' => ['brands_models'],
        'model-lists.store' => ['brands_models'],
        'model-lists.update' => ['brands_models'],
        'model-lists.destroy' => ['brands_models'],
        'model-lists.assign-codes' => ['brands_models'],
        'model-lists.import-from-products' => ['brands_models'],
        'model-lists.import-phone-catalog' => ['brands_models'],
        'model-lists.quick-store' => ['brands_models'],
        'shipping-methods.index' => ['shipping_methods'],
        'shipping-methods.store' => ['shipping_methods'],
        'shipping-methods.update' => ['shipping_methods'],
        'shipping-methods.destroy' => ['shipping_methods'],
        'categories.quickStore' => ['categories'],
        'inventory-webhooks.index' => ['integrations.inventory'],
        'inventory-webhooks.update' => ['integrations.inventory'],
        'movements.create' => ['warehouse.stocks'],
        'movements.store' => ['warehouse.stocks'],
        'movements.index' => ['reports'],
        'sales-returns.index' => ['sales.returns'],
        'sales-returns.create' => ['sales.returns'],
        'sales-returns.store' => ['sales.returns'],
        'sales-returns.export.excel' => ['sales.returns'],
        'sales-returns.export.pdf' => ['sales.returns'],
        'sales-returns.print-report' => ['sales.returns'],
        'sales-returns.customers.search' => ['sales.returns'],
        'sales-returns.customers.invoices' => ['sales.returns'],
        'sales-returns.invoices.items' => ['sales.returns'],
        'sales-returns.products.search' => ['sales.returns'],
        'sales-returns.products.variants' => ['sales.returns'],
        'sales-returns.preview' => ['sales.returns'],
        'sales-returns.show' => ['sales.returns'],
        'sales-returns.edit' => ['sales.returns'],
        'sales-returns.update' => ['sales.returns'],
        'sales-returns.apply' => ['sales.returns'],
        'sales-returns.cancel' => ['sales.returns'],
        'sales-returns.print' => ['sales.returns'],
        'sales-returns.pdf' => ['sales.returns'],
        'vouchers.index' => ['warehouse.issues'],
        'vouchers.sales.index' => ['warehouse.issues'],
        'vouchers.sales.queue' => ['warehouse.collection'],
        'vouchers.sales.queue.data' => ['warehouse.collection'],
        'vouchers.sales.shipped' => ['warehouse.shipping'],
        'warehouse.shipping.index' => ['warehouse.shipping'],
        'warehouse.shipping.ship' => ['warehouse.shipping'],
        'warehouse-reservations.index' => ['warehouse.reservations'],
        'warehouse-reservations.health.export' => ['warehouse.reservations'],
        'warehouse-reservations.release' => ['warehouse.reservations'],
        'warehouse.inbound.index' => ['warehouse.inbound_queue'],
        'warehouse.inbound.show' => ['warehouse.inbound_queue'],
        'warehouse.inbound.receive' => ['warehouse.inbound_queue'],
        'vouchers.sales.queue.receive' => ['warehouse.collection'],
        'vouchers.sales.queue.start-collection' => ['warehouse.collection'],
        'vouchers.sales.queue.complete-collection' => ['warehouse.collection'],
        'vouchers.sales.queue.items' => ['warehouse.collection'],
        'vouchers.sales.products.categories' => ['warehouse.collection'],
        'vouchers.sales.products.by-category' => ['warehouse.collection'],
        'vouchers.sales.products.variants' => ['warehouse.collection'],
        'vouchers.sales.collection.edit' => ['warehouse.collection'],
        'vouchers.sales.collection.update' => ['warehouse.collection'],
        'vouchers.sales.edit' => ['warehouse.collection'],
        'vouchers.sales.show' => ['warehouse.issues'],
        'vouchers.sales.history' => ['warehouse.issues'],
        'vouchers.sales.update' => ['warehouse.collection'],
        'vouchers.sales.status' => ['warehouse.issues'],
        'vouchers.sales.print' => ['warehouse.issues'],
        'finance.invoices.reapprove' => ['finance.payments'],
        'finance.invoices.return-to-sales' => ['finance.payments'],
        'finance.cheques.registered' => ['finance.payments'],
        'finance.reports.index' => ['finance.accounts'],
        'finance.reports.sales-visitors' => ['finance.accounts'],
        'finance.reports.sales-visitors.commission-batches.store' => ['finance.accounts'],
        'finance.reports.sales-visitors.commission-batches.show' => ['finance.accounts'],
        'finance.reports.sales-visitors.commission-batches.export' => ['finance.accounts'],
        'finance.reports.sales-visitors.commission-batches.print' => ['finance.accounts'],
        'finance.seller-sales.index' => ['finance.seller_sales_documents'],
        'finance.seller-sales.create' => ['finance.seller_sales_documents'],
        'finance.seller-sales.available-invoices' => ['finance.seller_sales_documents'],
        'finance.seller-sales.store' => ['finance.seller_sales_documents'],
        'finance.seller-sales.show' => ['finance.seller_sales_documents'],
        'finance.seller-sales.edit' => ['finance.seller_sales_documents'],
        'finance.seller-sales.update' => ['finance.seller_sales_documents'],
        'finance.seller-sales.print' => ['finance.seller_sales_documents'],
        'vouchers.return-from-sale.index' => ['sales.returns'],
        'vouchers.return-from-sale.data' => ['sales.returns'],
        'vouchers.return-from-sale.create' => ['sales.returns'],
        'vouchers.return-from-sale.store' => ['sales.returns'],
        'vouchers.return-from-sale.customers.search' => ['sales.returns'],
        'vouchers.return-from-sale.customers.invoices' => ['sales.returns'],
        'vouchers.return-from-sale.invoices.items' => ['sales.returns'],
        'vouchers.return-from-sale.categories.index' => ['sales.returns'],
        'vouchers.return-from-sale.categories.products' => ['sales.returns'],
        'vouchers.return-from-sale.products.search' => ['sales.returns'],
        'vouchers.return-from-sale.products.variants' => ['sales.returns'],
        'vouchers.return-from-sale.preview' => ['sales.returns'],
        'vouchers.return-from-sale.export.excel' => ['sales.returns'],
        'vouchers.return-from-sale.export.pdf' => ['sales.returns'],
        'vouchers.return-from-sale.print-report' => ['sales.returns'],
        'vouchers.return-from-sale.print.customers' => ['sales.returns'],
        'vouchers.return-from-sale.print.products' => ['sales.returns'],
        'vouchers.return-from-sale.legacy.print' => ['sales.returns'],
        'vouchers.return-from-sale.show' => ['sales.returns'],
        'vouchers.return-from-sale.edit' => ['sales.returns'],
        'vouchers.return-from-sale.applied.edit' => ['sales.returns'],
        'vouchers.return-from-sale.applied.update' => ['sales.returns'],
        'vouchers.return-from-sale.applied.void' => ['sales.returns'],
        'vouchers.return-from-sale.update' => ['sales.returns'],
        'vouchers.return-from-sale.apply' => ['sales.returns'],
        'vouchers.return-from-sale.cancel' => ['sales.returns'],
        'vouchers.return-from-sale.print' => ['sales.returns'],
        'vouchers.return-from-sale.pdf' => ['sales.returns'],
        'vouchers.personnel.categories.children' => ['warehouse.issues'],
        'vouchers.personnel.products.search' => ['warehouse.issues'],
        'vouchers.personnel.products.variants' => ['warehouse.issues'],
        'vouchers.section.index' => ['warehouse.issues'],
        'vouchers.section.create' => ['warehouse.issues'],
        'vouchers.section.store' => ['warehouse.issues'],
        'vouchers.create' => ['warehouse.issues'],
        'vouchers.store' => ['warehouse.issues'],
        'vouchers.invoice.products' => ['warehouse.issues'],
        'vouchers.sale-delivery.index' => ['warehouse.issues'],
        'vouchers.sale-delivery.edit' => ['warehouse.issues'],
        'vouchers.sale-delivery.update' => ['warehouse.issues'],
        'vouchers.return.customer-invoices' => ['warehouse.issues'],
        'notifications.index' => ['dashboard'],
        'notifications.latest' => ['dashboard'],
        'notifications.unread-count' => ['dashboard'],
        'notifications.read' => ['dashboard'],
        'notifications.read-all' => ['dashboard'],
        'notifications.open' => ['dashboard'],
        'vouchers.show' => ['warehouse.issues'],
        'vouchers.edit' => ['warehouse.issues'],
        'vouchers.update' => ['warehouse.issues'],
        'vouchers.destroy' => ['warehouse.issues'],
        'warehouse.outputs' => ['warehouse.issues'],
        'asset.hub' => ['assets'],
        'asset.personnel.index' => ['assets'],
        'asset.personnel.create' => ['assets'],
        'asset.personnel.store' => ['assets'],
        'asset.personnel.show' => ['assets'],
        'asset.personnel.edit' => ['assets'],
        'asset.personnel.update' => ['assets'],
        'asset.personnel.toggle-status' => ['assets'],
        'asset.documents.index' => ['assets'],
        'asset.documents.create' => ['assets'],
        'asset.documents.store' => ['assets'],
        'asset.documents.show' => ['assets'],
        'asset.documents.view' => ['assets'],
        'asset.documents.print' => ['assets'],
        'asset.documents.signed-form.view' => ['assets'],
        'asset.documents.signed-form.download' => ['assets'],
        'asset.documents.edit' => ['assets'],
        'asset.documents.update' => ['assets'],
        'asset.documents.finalize' => ['assets'],
        'asset.documents.cancel' => ['assets'],
        'asset.codes.search' => ['assets'],
        'asset.codes.find' => ['assets'],
        'sales-havaleh.create-from-financial' => ['warehouse.issues'],
        'sales-havaleh.show' => ['warehouse.issues'],
        'sales-havaleh.view' => ['warehouse.issues'],
        'sales-havaleh.update' => ['warehouse.issues'],
        'sales-havaleh.status' => ['warehouse.issues'],
        'sales-havaleh.history' => ['warehouse.issues'],
        'payments.view' => ['finance.payments'],
        'warehouse-map.index' => ['warehouse.map'],
        'warehouse-map.locations.show' => ['warehouse.map'],
        'warehouse-map.categories.children' => ['warehouse.map'],
        'warehouse-map.categories.products' => ['warehouse.map'],
        'warehouse-map.products.variants' => ['warehouse.map'],
        'warehouse-map.history' => ['warehouse.map'],
        'warehouse-map.locations.store' => ['warehouse.map'],
        'warehouse-map.locations.update' => ['warehouse.map'],
        'warehouse-map.assign' => ['warehouse.map'],
        'warehouse-map.transfer' => ['warehouse.map'],
        'warehouses.index' => ['warehouses'],
        'warehouses.edit' => ['warehouses'],
        'warehouses.update' => ['warehouses'],
        'warehouses.destroy' => ['warehouses'],
        'warehouses.personnel.index' => ['warehouses'],
        'warehouses.personnel.store' => ['warehouses'],
        'warehouses.personnel.show' => ['warehouses'],
        'purchases.index' => ['warehouse.purchases'],
        'purchases.export' => ['warehouse.purchases'],
        'purchases.create' => ['warehouse.purchases'],
        'purchases.products.variants' => ['warehouse.purchases'],
        'purchases.store' => ['warehouse.purchases'],
        'purchases.show' => ['warehouse.purchases'],
        'purchases.edit' => ['warehouse.purchases'],
        'purchases.update' => ['warehouse.purchases'],
        'purchases.destroy' => ['warehouse.purchases'],
        'persons.index' => ['customers'],
        'persons.store' => ['customers'],
        'persons.update' => ['customers'],
        'suppliers.index' => ['suppliers'],
        'suppliers.store' => ['suppliers'],
        'stocktake.index' => ['warehouse.stocktake'],
        'stock-count-documents.index' => ['warehouse.stocktake'],
        'stock-count-documents.create' => ['warehouse.stocktake'],
        'stock-count-documents.store' => ['warehouse.stocktake'],
        'stock-count-documents.subcategories' => ['warehouse.stocktake'],
        'stock-count-documents.products' => ['warehouse.stocktake'],
        'stock-count-documents.variants' => ['warehouse.stocktake'],
        'stock-count-documents.show' => ['warehouse.stocktake'],
        'stock-count-documents.view' => ['warehouse.stocktake'],
        'stock-count-documents.edit' => ['warehouse.stocktake'],
        'stock-count-documents.update' => ['warehouse.stocktake'],
        'stock-count-documents.finalize' => ['warehouse.stocktake'],
        'stock-count-documents.cancel' => ['warehouse.stocktake'],
        'stock-count-documents.system-quantity' => ['warehouse.stocktake'],
        'preinvoice.create' => ['sales.preinvoices'],
        'preinvoice.draft.save' => ['sales.preinvoices'],
        'preinvoice.autosave' => ['sales.preinvoices'],
        'preinvoice.autosave.latest' => ['sales.preinvoices'],
        'preinvoice.autosave.discard' => ['sales.preinvoices'],
        'preinvoice.reservations.heartbeat' => ['sales.preinvoices'],
        'preinvoice.reservations.release-token' => ['sales.preinvoices'],
        'warehouse.reviews.index' => ['sales.preinvoice_warehouse_review'],
        'warehouse.reviews.show' => ['sales.preinvoice_warehouse_review'],
        'warehouse.reviews.print' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.warehouse.index' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.warehouse.review' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.warehouse.save' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.warehouse.approve' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.warehouse.reject' => ['sales.preinvoice_warehouse_review'],
        'preinvoice.draft.index' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.edit' => ['sales.preinvoices'],
        'preinvoice.draft.update' => ['sales.preinvoices'],
        'preinvoice.draft.finance' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.finance.edit' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.finance.update' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.finance.save-and-finalize' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.finalize' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.return' => ['sales.preinvoice_finance_review'],
        'preinvoice.draft.cancel' => ['sales.preinvoice_finance_review'],
        'preinvoice.all.index' => ['sales.preinvoice_finance_review', 'sales.preinvoice_warehouse_review'],
        'preinvoice.my.index' => ['sales.preinvoices'],
        'preinvoice.my.show' => ['sales.preinvoices'],
        'preinvoice.print' => ['sales.preinvoices'],
        'preinvoice.api.product-finder' => ['sales.preinvoices'],
        'preinvoice.api.product-finder.categories' => ['sales.preinvoices'],
        'preinvoice.api.products' => ['sales.preinvoices'],
        'preinvoice.api.product' => ['sales.preinvoices'],
        'preinvoice.api.reservations.sync' => ['sales.preinvoices'],
        'preinvoice.api.reservations.release' => ['sales.preinvoices'],
        'preinvoice.api.area' => ['sales.preinvoices'],
        'api.customers.search' => ['customers', 'sales.preinvoices'],
        'api.customers.store' => ['customers', 'sales.preinvoices'],
        'api.customers.show' => ['customers', 'sales.preinvoices'],
        'customers.index' => ['customers'],
        'customers.create' => ['customers'],
        'customers.show' => ['customers'],
        'customers.edit' => ['customers'],
        'customers.store' => ['customers'],
        'customers.update' => ['customers'],
        'customers.destroy' => ['customers'],
        'customers.import' => ['customers'],
        'archive.index' => ['sales.invoices'],
        'archive.preinvoices.show' => ['sales.preinvoices'],
        'archive.invoices.show' => ['sales.invoices'],
        'invoices.index' => ['sales.invoices'],
        'invoices.data' => ['sales.invoices'],
        'invoices.customers.search' => ['sales.invoices', 'customers'],
        'invoices.cancelled' => ['sales.invoices'],
        'invoices.bulk.reassign-seller' => ['sales.invoices'],
        'invoices.print' => ['sales.invoices'],
        'invoices.edit' => ['sales.invoices'],
        'invoices.update' => ['sales.invoices'],
        'invoices.history' => ['sales.invoices'],
        'invoices.show' => ['sales.invoices'],
        'invoices.status' => ['sales.invoices'],
        'invoices.reassign-seller' => ['sales.invoices'],
        'invoices.cancel' => ['sales.invoices'],
        'invoices.cancel.undo' => ['sales.invoices'],
        'invoices.payments.store' => ['finance.payments'],
        'invoices.notes.store' => ['sales.invoices'],
        'cheques.store' => ['finance.payments'],
        'account-statements.index' => ['finance.accounts'],
        'account-statements.payments.store' => ['finance.accounts'],
        'account-statements.documents.invoices.show' => ['finance.accounts'],
        'account-statements.documents.returns.show' => ['finance.accounts'],
        'account-statements.documents.payments.show' => ['finance.accounts'],
        'account-statements.show' => ['finance.accounts'],
        'activity-logs.index' => ['activity_logs'],
        'users.index' => ['users'],
        'users.sync' => ['users'],
        'admin.bug-investigator.index' => ['activity_logs'],
        'admin.bug-investigator.create' => ['activity_logs'],
        'admin.bug-investigator.store' => ['activity_logs'],
        'admin.bug-investigator.rerun' => ['activity_logs'],
        'admin.bug-investigator.show' => ['activity_logs'],
        'admin.permissions.index' => ['roles'],
        'admin.permissions.update' => ['roles'],
        'admin.roles.index' => ['roles'],
        'admin.roles.create' => ['roles'],
        'admin.roles.store' => ['roles'],
        'admin.roles.edit' => ['roles'],
        'admin.roles.update' => ['roles'],
        'admin.roles.destroy' => ['roles'],
        'finance.cheques.index' => ['finance.payments'],
    ];

    /**
     * Cross-page routes that are part of another page's workflow.
     *
     * These grants are deliberately route-level rather than page-level so a user
     * can follow the linked workflow without inheriting every operation of the
     * destination module.
     *
     * @var array<string, array<int, string>>
     */
    private const LINKED_ROUTE_ACCESS = [
        // Warehouse workflows need read-only access to the sale document.
        'warehouse.collection' => [
            'vouchers.sales.show',
            'vouchers.sales.print',
        ],
        'warehouse.shipping' => [
            'vouchers.sales.show',
            'vouchers.sales.print',
        ],

        // Warehouse review may print the reviewed preinvoice.
        'sales.preinvoice_warehouse_review' => [
            'preinvoice.print',
        ],

        // Finance review needs the resulting invoice and finance-only workflow actions.
        'sales.preinvoice_finance_review' => [
            'invoices.show',
            'invoices.print',
            'finance.invoices.reapprove',
            'finance.invoices.return-to-sales',
        ],

        // Sellers may follow their own converted preinvoice to its read-only invoice/havaleh.
        // Record-level ownership checks remain enforced by the controllers.
        'sales.preinvoices' => [
            'invoices.show',
            'invoices.print',
            'vouchers.sales.show',
            'vouchers.sales.print',
        ],

        // Product forms embed quick-create helpers from configuration pages.
        'products' => [
            'purchases.show',
            'categories.quickStore',
            'model-lists.quick-store',
        ],

        // Warehouse issue users can inspect/print the corresponding invoice, but not edit it.
        'warehouse.issues' => [
            'invoices.show',
            'invoices.print',
        ],

        // Invoice editing uses these product lookup endpoints internally.
        'sales.invoices' => [
            'vouchers.sales.products.categories',
            'vouchers.sales.products.by-category',
            'vouchers.sales.products.variants',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function pages(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $definitions = [
            'warehouse.reservations' => ['انبارداری', 'رزرو موجودی', ['warehouse.reservations']],
            'dashboard' => ['داشبورد', 'داشبورد', ['dashboard', 'notifications']],
            'products' => ['کالاهای آریا', 'کالاها', ['products']],
            'products.price_changes' => ['کالاهای آریا', 'تغییر قیمت کالا', ['products.price_changes']],
            'categories' => ['پیکربندی', 'دسته‌بندی کالا', ['categories']],
            'brands_models' => ['پیکربندی', 'برندها و مدل‌ها', ['brands', 'model_lists']],
            'units' => ['پیکربندی', 'واحدهای کالا', ['units']],
            'shipping_methods' => ['پیکربندی', 'روش‌های ارسال', ['shipping_methods']],
            'warehouses' => ['انبارداری', 'انبارها و پرسنل انبار', ['warehouses']],
            'warehouse.stocks' => ['انبارداری', 'موجودی انبار', ['inventory']],
            'warehouse.stocktake' => ['انبارداری', 'انبارگردانی', ['inventory.count']],
            'warehouse.purchases' => ['انبارداری', 'خرید کالا', ['stock_in']],
            'warehouse.issues' => ['انبارداری', 'خروج کالا و حواله', ['stock_out', 'issues']],
            'warehouse.collection' => ['انبارداری', 'جمع‌آوری سفارش', ['warehouse.collection']],
            'warehouse.shipping' => ['انبارداری', 'ارسال سفارش', ['warehouse.shipping']],
            'warehouse.inbound_queue' => ['انبارداری', 'صف ورودی موجودی', []],
            'warehouse.transfers' => ['انبارداری', 'انتقال بین انبار', ['transfers']],
            'warehouse.receipts' => ['انبارداری', 'رسید انبار', ['receipts']],
            'warehouse.map' => ['انبارداری', 'نقشه انبار', ['warehouse_map']],
            'assets' => ['انبارداری', 'امین اموال', ['assets']],
            'sales.preinvoices' => ['بازرگانی و فروش', 'ثبت و مدیریت پیش‌فاکتورها', ['preinvoices']],
            'commercial.commissions' => ['بازرگانی و فروش', 'پورسانت فروشندگان', []],
            'commercial.invoice_reassignments' => ['بازرگانی و فروش', 'انتقال فروشنده فاکتور', []],
            'sales.preinvoice_warehouse_review' => ['بازرگانی و فروش', 'بررسی انبار پیش‌فاکتور', ['preinvoices.warehouse']],
            'sales.preinvoice_finance_review' => ['مالی', 'تأیید مالی پیش‌فاکتور', ['preinvoices.finance']],
            'sales.invoices' => ['بازرگانی و فروش', 'فاکتورهای فروش', ['invoices', 'notes']],
            'sales.returns' => ['بازرگانی و فروش', 'برگشت از فروش', ['sales_returns']],
            'customers' => ['بازرگانی و فروش', 'مشتریان', ['customers']],
            'suppliers' => ['بازرگانی و فروش', 'تأمین‌کنندگان', ['suppliers']],
            'finance.payments' => ['مالی', 'پرداخت‌ها و چک‌ها', ['payments', 'cheques']],
            'finance.accounts' => ['مالی', 'گردش حساب و گزارش مالی', ['account_statements', 'finance.reports']],
            'finance.seller_sales_documents' => ['مالی', 'گزارش پورسانت فروشندگان', ['seller_sales_documents']],
            'reports' => ['گزارش‌ها', 'گزارش‌های مدیریتی', ['reports']],
            'tickets' => ['مدیریت سیستم', 'تیکت‌ها', ['tickets']],
            'users' => ['مدیریت سیستم', 'کاربران', ['users']],
            'roles' => ['مدیریت سیستم', 'نقش‌ها و دسترسی صفحات', ['roles', 'permissions']],
            'settings' => ['مدیریت سیستم', 'تنظیمات سیستم', ['settings']],
            'activity_logs' => ['مدیریت سیستم', 'لاگ فعالیت‌ها', ['logs']],
            'integrations.inventory' => ['یکپارچه‌سازی‌ها', 'همگام‌سازی موجودی', ['inventory_webhooks']],
        ];

        $pages = [];
        foreach ($definitions as $key => $definition) {
            [$group, $label, $legacyPrefixes] = $definition;
            $permission = 'page.'.$key;
            $legacy = collect(PermissionCatalog::groups())->flatMap(fn (array $permissions) => array_keys($permissions))
                ->filter(fn (string $name) => self::matchesAnyPrefix($name, $legacyPrefixes))
                ->values()->all();
            $pageRoutes = collect(self::ROUTE_OWNERS)
                ->filter(fn (array $owners) => in_array($key, $owners, true))
                ->keys()->values()->all();
            $preferredRoute = self::preferredLandingRoute($key);
            if ($preferredRoute !== null && in_array($preferredRoute, $pageRoutes, true)) {
                $pageRoutes = array_values(array_unique([$preferredRoute, ...$pageRoutes]));
            }
            $pages[$key] = [
                'key' => $key,
                'permission' => $permission,
                'label' => $label,
                'group' => $group,
                'description' => 'دسترسی کامل به صفحه یا فرآیند «'.$label.'» و عملیات وابسته آن',
                'routes' => $pageRoutes,
                'legacy_permissions' => $legacy,
                'action_permissions' => self::PAGE_ACTION_PERMISSIONS[$key] ?? [],
                'order' => (array_search($key, array_keys($definitions), true) + 1) * 10,
                'sensitive' => in_array($key, ['users', 'roles', 'settings', 'activity_logs', 'integrations.inventory', 'commercial.commissions', 'commercial.invoice_reassignments', 'sales.preinvoice_finance_review', 'sales.preinvoice_warehouse_review', 'finance.accounts', 'finance.seller_sales_documents', 'finance.payments', 'warehouse.stocktake', 'warehouse.inbound_queue'], true),
                // Migration is intentionally stricter than runtime route compatibility:
                // action-only permissions never imply entry to a whole page.
                'migration_grant_permissions' => self::migrationGrantPermissions($key, $legacy),
                'system_only' => false,
            ];
        }

        return $cached = $pages;
    }

    public static function permissionForRoute(?string $routeName): ?string
    {
        return self::permissionsForRoute($routeName)[0] ?? null;
    }

    /** @return array<int, string> */
    public static function permissionsForRoute(?string $routeName): array
    {
        if (! $routeName) {
            return [];
        }

        $owners = self::ROUTE_OWNERS[$routeName] ?? [];

        foreach (self::LINKED_ROUTE_ACCESS as $sourcePage => $linkedRoutes) {
            if (in_array($routeName, $linkedRoutes, true)) {
                $owners[] = $sourcePage;
            }
        }

        return collect($owners)
            ->unique()
            ->map(fn (string $key): string => 'page.'.$key)
            ->values()
            ->all();
    }

    /** @return array<string, array<int, string>> */
    public static function routeOwners(): array
    {
        return collect(self::ROUTE_OWNERS)
            ->map(fn (array $keys): array => array_map(fn (string $key): string => 'page.'.$key, $keys))
            ->all();
    }

    /** @return array<string, array<int, string>> */
    public static function sharedRoutes(): array
    {
        return array_filter(self::routeOwners(), fn (array $permissions): bool => count($permissions) > 1);
    }

    /** @return array<int, string> */
    public static function authenticatedRouteAllowlist(): array
    {
        return [
            'access.unassigned', 'session.csrf-token',
            'locations.provinces.index', 'locations.provinces.cities',
            'profile.edit', 'profile.update', 'profile.destroy',
            'verification.notice', 'verification.verify', 'verification.send',
            'password.confirm', 'password.update', 'logout',
        ];
    }

    public static function pagePermissionForLegacy(string $legacyPermission): ?string
    {
        if (in_array($legacyPermission, [
            'sales_returns.override_invoice_status',
            'sales_returns.override_destination',
            'sales_returns.create_product',
        ], true)) {
            return null;
        }

        $explicit = self::legacyMigrationDispositions()[$legacyPermission]['page_permission'] ?? null;
        if ($explicit) {
            return $explicit;
        }
        if (str_starts_with($legacyPermission, 'products.price_changes.')) {
            return 'page.products.price_changes';
        }
        if (str_starts_with($legacyPermission, 'preinvoices.finance.')) {
            return 'page.sales.preinvoice_finance_review';
        }
        if (str_starts_with($legacyPermission, 'preinvoices.warehouse.')) {
            return 'page.sales.preinvoice_warehouse_review';
        }
        foreach (self::pages() as $page) {
            if (in_array($legacyPermission, $page['legacy_permissions'], true)) {
                return $page['permission'];
            }
        }

        return null;
    }

    public static function legacyMigrationDispositions(): array
    {
        $map = [
            'account_statements.adjust' => 'page.finance.accounts', 'finance.reports.view' => 'page.finance.accounts',
            'permissions.assign_roles' => 'page.roles',
            'products.price_changes.apply' => 'page.products.price_changes', 'products.price_changes.cancel' => 'page.products.price_changes', 'products.price_changes.create' => 'page.products.price_changes',
            'sales_returns.edit_applied' => 'page.sales.returns', 'sales_returns.void_applied' => 'page.sales.returns',
            'warehouse.collection.adjust_price' => 'page.warehouse.collection', 'warehouse.collection.edit' => 'page.warehouse.collection', 'warehouse.collection.queue.view' => 'page.warehouse.collection', 'warehouse.collection.receive' => 'page.warehouse.collection', 'warehouse.collection.start' => 'page.warehouse.collection', 'warehouse.collection.submit_reapproval' => 'page.warehouse.collection', 'warehouse.collection.view' => 'page.warehouse.collection',
            'warehouse.reservations.release' => 'page.warehouse.reservations', 'warehouse.reservations.view' => 'page.warehouse.reservations',
            'warehouse.shipping.queue.view' => 'page.warehouse.shipping', 'warehouse.shipping.ship' => 'page.warehouse.shipping', 'warehouse.shipping.view' => 'page.warehouse.shipping',
        ];
        $result = collect($map)->map(fn ($page) => ['status' => 'mapped', 'page_permission' => $page])->all();
        foreach (['posts.create', 'posts.delete', 'posts.edit', 'posts.view', 'unions.create', 'unions.delete', 'unions.edit', 'unions.view'] as $permission) {
            $result[$permission] = ['status' => 'no_real_page', 'page_permission' => null];
        }

        return $result;
    }

    public static function migrationDecision(string $legacyPermission): ?array
    {
        $pagePermission = self::pagePermissionForLegacy($legacyPermission);
        if ($pagePermission === null) {
            return null;
        }
        $page = collect(self::pages())->firstWhere('permission', $pagePermission);
        $sensitive = (bool) ($page['sensitive'] ?? false);
        $explicit = in_array($legacyPermission, $page['migration_grant_permissions'] ?? [], true);

        return ['page_permission' => $pagePermission, 'decision' => $sensitive ? 'review_required' : ($explicit ? 'grant' : 'ambiguous'), 'risk' => $sensitive ? 'high' : ($explicit ? 'low' : 'medium')];
    }

    public static function page(string $key): ?array
    {
        return self::pages()[$key] ?? null;
    }

    public static function userCan(User $user, string $pagePermission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        $page = collect(self::pages())->firstWhere('permission', $pagePermission);
        if (! $page) {
            return false;
        }
        $rolePermissions = $user->getPermissionsViaRoles()->pluck('key')->filter()->all();

        return in_array($pagePermission, $rolePermissions, true);
    }

    public static function userCanRoute(User $user, ?string $routeName): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return collect(self::permissionsForRoute($routeName))
            ->contains(fn (string $permission): bool => self::userCan($user, $permission));
    }

    public static function permissions(): array
    {
        return collect(self::pages())->pluck('permission')->values()->all();
    }

    private static function matchesAnyPrefix(string $permission, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($permission === $prefix || str_starts_with($permission, $prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    private static function migrationGrantPermissions(string $key, array $legacy): array
    {
        $explicit = [
            'users' => ['users.view'],
            'roles' => ['roles.view', 'permissions.view'],
            'settings' => ['settings.view'],
            'activity_logs' => ['logs.view'],
            'integrations.inventory' => ['inventory_webhooks.view'],
            'sales.preinvoice_finance_review' => ['preinvoices.finance.view'],
            'sales.preinvoice_warehouse_review' => ['preinvoices.warehouse.view', 'preinvoices.warehouse.reviews.view'],
            'finance.accounts' => ['account_statements.view', 'finance.reports.view'],
            'finance.payments' => ['payments.view', 'cheques.view'],
            'warehouse.stocktake' => ['inventory.count.view'],
        ];
        if (isset($explicit[$key])) {
            return array_values(array_intersect($legacy, $explicit[$key]));
        }

        return collect($legacy)->filter(fn (string $permission) => str_ends_with($permission, '.view')
            || str_ends_with($permission, '.index')
            || preg_match('/(^|\.)(manage|reports)$/', $permission) === 1
            || in_array($permission, ['dashboard', 'products', 'customers.manage', 'suppliers.manage', 'inventory', 'stock_in', 'stock_out'], true)
        )->values()->all();
    }

    private static function preferredLandingRoute(string $key): ?string
    {
        return [
            'dashboard' => 'dashboard', 'products' => 'products.index', 'products.price_changes' => 'products.price-changes.index',
            'categories' => 'categories.index', 'brands_models' => 'model-lists.index', 'shipping_methods' => 'shipping-methods.index',
            'warehouses' => 'warehouses.index', 'warehouse.stocks' => 'products.index', 'warehouse.stocktake' => 'stock-count-documents.index',
            'warehouse.purchases' => 'purchases.index', 'warehouse.issues' => 'vouchers.index', 'warehouse.collection' => 'vouchers.sales.queue',
            'warehouse.shipping' => 'warehouse.shipping.index', 'warehouse.reservations' => 'warehouse-reservations.index', 'warehouse.inbound_queue' => 'warehouse.inbound.index', 'warehouse.map' => 'warehouse-map.index', 'assets' => 'asset.hub',
            'sales.preinvoices' => 'preinvoice.create', 'commercial.commissions' => 'commercial.commissions.index', 'commercial.invoice_reassignments' => 'commercial.invoice-reassignments.index', 'sales.preinvoice_warehouse_review' => 'warehouse.reviews.index',
            'sales.preinvoice_finance_review' => 'preinvoice.draft.finance', 'sales.invoices' => 'invoices.index',
            'sales.returns' => 'vouchers.return-from-sale.index', 'customers' => 'customers.index', 'suppliers' => 'suppliers.index',
            'finance.payments' => 'finance.cheques.index', 'finance.accounts' => 'account-statements.index', 'finance.seller_sales_documents' => 'finance.seller-sales.index', 'reports' => 'finance.reports.index',
            'users' => 'users.index', 'roles' => 'admin.roles.index', 'activity_logs' => 'activity-logs.index',
            'integrations.inventory' => 'inventory-webhooks.index',
        ][$key] ?? null;
    }
}
