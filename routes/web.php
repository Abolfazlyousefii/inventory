<?php

use App\Http\Controllers\AccountStatementController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\BugInvestigatorController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserPermissionController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AssetDocumentController;
use App\Http\Controllers\AssetPersonnelController;
use App\Http\Controllers\AssetTrusteeController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\CustomerApiController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\SellerCommissionDocumentController;
use App\Http\Controllers\InventoryWebhookController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceNoteController;
use App\Http\Controllers\InvoicePaymentController;
use App\Http\Controllers\ModelListController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PreinvoiceApiController;
use App\Http\Controllers\PreinvoiceController;
use App\Http\Controllers\PriceChangeDocumentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductDeactivationDocumentController;
use App\Http\Controllers\ProductExportController;
use App\Http\Controllers\ProductPurchaseLedgerController;
use App\Http\Controllers\ProductSalesLedgerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesHavalehController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\SalesReturnLookupController;
use App\Http\Controllers\ShippingMethodController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockMovementReportController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\VoucherSalesReturnController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseMapController;
use App\Http\Controllers\WarehouseReviewController;
use App\Http\Controllers\WarehouseShippingController;
use App\Services\Sync\SiteCustomersSyncService;
use App\Services\Sync\SiteImageSyncService;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/access-unassigned', fn () => response()->view('errors.access-unassigned'))
    ->middleware('auth')
    ->name('access.unassigned');

Route::get('/session/csrf-token', function () {
    return response()->json([
        'ok' => true,
        'csrf_token' => csrf_token(),
    ]);
})->middleware('auth')->name('session.csrf-token');

Route::middleware(['auth', 'route.permission'])->group(function () {

    Route::get('/locations/provinces', [PreinvoiceApiController::class, 'provinces'])->name('locations.provinces.index');
    Route::get('/locations/provinces/{province}/cities', [PreinvoiceApiController::class, 'cities'])->name('locations.provinces.cities');

    // Dashboard + profile
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/monthly-report', [DashboardController::class, 'monthlyReport'])->name('dashboard.monthly-report');
    Route::get('/global-search', [DashboardController::class, 'globalSearch'])->name('global-search');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Products + categories
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/data', [ProductController::class, 'data'])->name('products.data');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::prefix('products/price-changes')->name('products.price-changes.')->group(function () {
        Route::get('/', [PriceChangeDocumentController::class, 'index'])->name('index');
        Route::get('/create', [PriceChangeDocumentController::class, 'create'])->name('create');
        Route::get('/categories/root', [PriceChangeDocumentController::class, 'rootCategories'])->name('categories.root');
        Route::get('/categories/{category}/children', [PriceChangeDocumentController::class, 'categoryChildren'])->whereNumber('category')->name('categories.children');
        Route::get('/products/search', [PriceChangeDocumentController::class, 'productSearch'])->name('products.search');
        Route::get('/products/{product}/variants', [PriceChangeDocumentController::class, 'productVariants'])->whereNumber('product')->name('products.variants');
        Route::post('/scope-summary', [PriceChangeDocumentController::class, 'scopeSummary'])->name('scope-summary');
        Route::post('/preview', [PriceChangeDocumentController::class, 'preview'])->name('preview');
        Route::post('/', [PriceChangeDocumentController::class, 'store'])->name('store');
        Route::get('/{document}', [PriceChangeDocumentController::class, 'show'])->name('show');
        Route::post('/{document}/apply', [PriceChangeDocumentController::class, 'apply'])->name('apply');
        Route::post('/{document}/cancel', [PriceChangeDocumentController::class, 'cancel'])->name('cancel');
    });

    Route::get('/products/{product}/variants', [ProductController::class, 'variants'])->whereNumber('product')->name('products.variants');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
    Route::get('/products/{product}/warehouse-stock', [ProductController::class, 'warehouseStock'])->whereNumber('product')->name('products.warehouse-stock');
    Route::get('/products/{product}/image', [ProductController::class, 'image'])->whereNumber('product')->name('products.image');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('products.destroy');
    Route::get('/products/{product}/sales-ledger', [ProductSalesLedgerController::class, 'index'])->whereNumber('product')->name('products.sales-ledger');
    Route::get('/products/{product}/purchase-ledger', [ProductPurchaseLedgerController::class, 'purchaseLedger'])->whereNumber('product')->name('products.purchase-ledger');
    Route::resource('categories', CategoryController::class)->except(['show', 'destroy']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('/categories/fix-codes', [CategoryController::class, 'fixCodes'])->name('categories.fixCodes');

    Route::get('/products/pricelist', [ProductController::class, 'priceList'])->name('products.pricelist');

    Route::prefix('admin/product-exports')->name('admin.product-exports.')->group(function () {
        Route::get('/', [ProductExportController::class, 'index'])->name('index');
        Route::get('/data', [ProductExportController::class, 'filter'])->name('data');
        Route::get('/print', [ProductExportController::class, 'print'])->name('print');
        Route::get('/download', [ProductExportController::class, 'download'])->name('download');
        Route::get('/model-lists', [ProductExportController::class, 'modelLists'])->name('model-lists');
        Route::get('/products/search', [ProductExportController::class, 'searchProducts'])->name('products.search');
        Route::get('/categories/{category}/children', [ProductExportController::class, 'children'])->whereNumber('category')->name('categories.children');
        Route::get('/export', [ProductExportController::class, 'export'])->name('export');
    });

    Route::post('/products/sync-crm', [ProductController::class, 'syncCrm'])->name('products.sync.crm');
    Route::get('/product-deactivation-documents', [ProductDeactivationDocumentController::class, 'index'])->name('product-deactivation-documents.index');
    Route::get('/product-deactivation-documents/create', [ProductDeactivationDocumentController::class, 'create'])->name('product-deactivation-documents.create');
    Route::post('/product-deactivation-documents', [ProductDeactivationDocumentController::class, 'store'])->name('product-deactivation-documents.store');
    Route::get('/product-deactivation-documents/{productDeactivationDocument}', [ProductDeactivationDocumentController::class, 'show'])->name('product-deactivation-documents.show');

    // Model Lists
    Route::get('/model-lists', [ModelListController::class, 'index'])->name('model-lists.index');
    Route::post('/model-lists', [ModelListController::class, 'store'])->name('model-lists.store');
    Route::put('/model-lists/{modelList}', [ModelListController::class, 'update'])->name('model-lists.update');
    Route::delete('/model-lists/{modelList}', [ModelListController::class, 'destroy'])->name('model-lists.destroy');

    Route::post('/model-lists/assign-codes', [ModelListController::class, 'assignCodes'])->name('model-lists.assign-codes');
    Route::post('/model-lists/import-from-products', [ModelListController::class, 'importFromProducts'])->name('model-lists.import-from-products');
    Route::post('/model-lists/import-phone-catalog', [ModelListController::class, 'importPhoneCatalog'])->name('model-lists.import-phone-catalog');
    Route::post('/model-lists/quick-store', [ModelListController::class, 'quickStore'])->name('model-lists.quick-store');

    // Shipping methods
    Route::get('/shipping-methods', [ShippingMethodController::class, 'index'])->name('shipping-methods.index');
    Route::post('/shipping-methods', [ShippingMethodController::class, 'store'])->name('shipping-methods.store');
    Route::put('/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'update'])->name('shipping-methods.update');
    Route::delete('/shipping-methods/{shippingMethod}', [ShippingMethodController::class, 'destroy'])->name('shipping-methods.destroy');

    // Quick category store
    Route::post('/categories/quick-store', [CategoryController::class, 'quickStore'])->name('categories.quickStore');


    Route::get('/inventory-webhooks', [InventoryWebhookController::class, 'index'])->name('inventory-webhooks.index');
    Route::put('/inventory-webhooks', [InventoryWebhookController::class, 'update'])->name('inventory-webhooks.update');

    // Stock movements
    Route::get('/products/{product}/movements/create', [StockMovementController::class, 'create'])->whereNumber('product')->name('movements.create');
    Route::post('/products/{product}/movements', [StockMovementController::class, 'store'])->whereNumber('product')->name('movements.store');
    Route::get('/movements', [StockMovementReportController::class, 'index'])->name('movements.index');


    Route::get('/sales-returns', fn () => redirect()->route('vouchers.return-from-sale.index'))->name('sales-returns.index');
    Route::get('/sales-returns/create', fn () => redirect()->route('vouchers.return-from-sale.create'))->name('sales-returns.create');
    Route::post('/sales-returns', [SalesReturnController::class, 'store'])->name('sales-returns.store');
    Route::get('/sales-returns/export/excel', [SalesReturnController::class, 'exportExcel'])->name('sales-returns.export.excel');
    Route::get('/sales-returns/export/pdf', [SalesReturnController::class, 'exportPdf'])->name('sales-returns.export.pdf');
    Route::get('/sales-returns/print', [SalesReturnController::class, 'printReport'])->name('sales-returns.print-report');
    Route::get('/sales-returns/customers/search', [SalesReturnLookupController::class, 'customers'])->name('sales-returns.customers.search');
    Route::get('/sales-returns/customers/{customer}/invoices', [SalesReturnLookupController::class, 'customerInvoices'])->whereNumber('customer')->name('sales-returns.customers.invoices');
    Route::get('/sales-returns/invoices/{invoice}/items', [SalesReturnLookupController::class, 'invoiceItems'])->whereNumber('invoice')->name('sales-returns.invoices.items');
    Route::get('/sales-returns/products/search', [SalesReturnLookupController::class, 'products'])->name('sales-returns.products.search');
    Route::get('/sales-returns/products/{product}/variants', [SalesReturnLookupController::class, 'variants'])->whereNumber('product')->name('sales-returns.products.variants');
    Route::post('/sales-returns/preview', [SalesReturnLookupController::class, 'preview'])->name('sales-returns.preview');
    Route::get('/sales-returns/{document}', fn (\App\Models\SalesReturnDocument $document) => redirect()->route('vouchers.return-from-sale.show', $document))->whereNumber('document')->name('sales-returns.show');
    Route::get('/sales-returns/{document}/edit', fn (\App\Models\SalesReturnDocument $document) => redirect()->route('vouchers.return-from-sale.edit', $document))->whereNumber('document')->name('sales-returns.edit');
    Route::patch('/sales-returns/{document}', [SalesReturnController::class, 'update'])->whereNumber('document')->name('sales-returns.update');
    Route::post('/sales-returns/{document}/apply', [SalesReturnController::class, 'apply'])->whereNumber('document')->name('sales-returns.apply');
    Route::post('/sales-returns/{document}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('document')->name('sales-returns.cancel');
    Route::get('/sales-returns/{document}/print', [SalesReturnController::class, 'print'])->whereNumber('document')->name('sales-returns.print');
    Route::get('/sales-returns/{document}/pdf', [SalesReturnController::class, 'pdf'])->whereNumber('document')->name('sales-returns.pdf');

    // Vouchers
Route::get('/vouchers', [VoucherController::class, 'hub'])->name('vouchers.index');

Route::get('/vouchers/sales', [InvoiceController::class, 'salesVouchers'])->name('vouchers.sales.index');
Route::get('/vouchers/sales/queue', [InvoiceController::class, 'salesQueue'])->name('vouchers.sales.queue');
Route::get('/vouchers/sales/queue/data', [InvoiceController::class, 'salesQueueData'])->name('vouchers.sales.queue.data');
Route::get('/vouchers/sales/shipped', [InvoiceController::class, 'salesShipped'])->name('vouchers.sales.shipped');
Route::get('/warehouse/shipping', [WarehouseShippingController::class, 'index'])->name('warehouse.shipping.index');
Route::post('/warehouse/shipping/{invoice:uuid}/ship', [WarehouseShippingController::class, 'ship'])->name('warehouse.shipping.ship');
Route::post('/vouchers/sales/queue/{uuid}/receive', [InvoiceController::class, 'receiveSalesQueueInvoice'])->name('vouchers.sales.queue.receive');
Route::post('/vouchers/sales/queue/{uuid}/start-collection', [InvoiceController::class, 'startSalesQueueCollection'])->name('vouchers.sales.queue.start-collection');
Route::post('/vouchers/sales/queue/{uuid}/complete-collection', [InvoiceController::class, 'completeSalesQueueCollection'])->name('vouchers.sales.queue.complete-collection');
Route::put('/vouchers/sales/queue/{uuid}/items', [InvoiceController::class, 'updateSalesQueueItems'])->name('vouchers.sales.queue.items');
Route::get('/vouchers/sales/products/categories', [InvoiceController::class, 'salesProductCategories'])->name('vouchers.sales.products.categories');
Route::get('/vouchers/sales/products/by-category', [InvoiceController::class, 'salesProductsByCategory'])->name('vouchers.sales.products.by-category');
Route::get('/vouchers/sales/products/{product}/variants', [InvoiceController::class, 'salesProductVariants'])->name('vouchers.sales.products.variants');
Route::get('/vouchers/sales/{uuid}/collection-edit', [InvoiceController::class, 'salesVoucherEdit'])->name('vouchers.sales.collection.edit');
Route::patch('/vouchers/sales/{uuid}/collection-update', [InvoiceController::class, 'salesVoucherUpdate'])->name('vouchers.sales.collection.update');
Route::get('/vouchers/sales/{uuid}', [InvoiceController::class, 'salesVoucherEdit'])->name('vouchers.sales.edit');
Route::get('/vouchers/sales/{uuid}/view', [InvoiceController::class, 'salesVoucherShow'])->name('vouchers.sales.show');
Route::get('/vouchers/sales/{uuid}/history', [InvoiceController::class, 'salesVoucherHistory'])->name('vouchers.sales.history');
Route::put('/vouchers/sales/{uuid}', [InvoiceController::class, 'salesVoucherUpdate'])->name('vouchers.sales.update');
Route::post('/vouchers/sales/{uuid}/status', [InvoiceController::class, 'updateStatus'])->name('vouchers.sales.status');
Route::get('/vouchers/sales/{uuid}/print', [InvoiceController::class, 'print'])->name('vouchers.sales.print');
Route::post('/finance/invoices/{uuid}/reapprove', [InvoiceController::class, 'financeReapproveInvoice'])->name('finance.invoices.reapprove');
Route::post('/finance/invoices/{uuid}/return-to-sales', [InvoiceController::class, 'financeReturnInvoiceToSales'])->name('finance.invoices.return-to-sales');
Route::get('/finance/registered-cheques', [ChequeController::class, 'index'])->name('finance.cheques.registered');

Route::prefix('finance/reports')
    ->name('finance.reports.')
    ->group(function () {
        Route::get('/', [FinanceReportController::class, 'index'])->name('index');
        Route::get('/sales-visitors', [FinanceReportController::class, 'salesVisitors'])->name('sales-visitors');
        Route::post('/sales-visitors/commission-batches', [FinanceReportController::class, 'storeCommissionBatch'])->name('sales-visitors.commission-batches.store');
        Route::get('/sales-visitors/commission-batches/{batch}', [FinanceReportController::class, 'showCommissionBatch'])->name('sales-visitors.commission-batches.show');
        Route::get('/sales-visitors/commission-batches/{batch}/export', [FinanceReportController::class, 'exportCommissionBatch'])->name('sales-visitors.commission-batches.export');
        Route::get('/sales-visitors/commission-batches/{batch}/print', [FinanceReportController::class, 'printCommissionBatch'])->name('sales-visitors.commission-batches.print');
    });

Route::prefix('finance/reports/seller-commission-documents')->name('finance.seller-sales.')->middleware(['auth','page.access:finance.seller_sales_documents'])->group(function(){
    Route::get('/',[SellerCommissionDocumentController::class,'index'])->name('index');
    Route::get('/create',[SellerCommissionDocumentController::class,'create'])->name('create');
    Route::get('/available-invoices',[SellerCommissionDocumentController::class,'availableInvoices'])->name('available-invoices');
    Route::post('/',[SellerCommissionDocumentController::class,'store'])->name('store');
    Route::get('/{document}',[SellerCommissionDocumentController::class,'show'])->name('show');
    Route::get('/{document}/edit',[SellerCommissionDocumentController::class,'edit'])->name('edit');
    Route::put('/{document}',[SellerCommissionDocumentController::class,'update'])->name('update');
    Route::get('/{document}/print',[SellerCommissionDocumentController::class,'print'])->name('print');
});


Route::prefix('vouchers/section/return-from-sale')
    ->name('vouchers.return-from-sale.')
    ->group(function () {
        Route::get('/', [VoucherSalesReturnController::class, 'index'])->name('index');
        Route::get('/data', [VoucherSalesReturnController::class, 'data'])->name('data');
        Route::get('/create', [VoucherSalesReturnController::class, 'create'])->name('create');
        Route::post('/', [VoucherSalesReturnController::class, 'store'])->name('store');
        Route::get('/customers/search', [SalesReturnLookupController::class, 'customers'])->name('customers.search');
        Route::get('/customers/{customer}/invoices', [SalesReturnLookupController::class, 'customerInvoices'])->whereNumber('customer')->name('customers.invoices');
        Route::get('/invoices/{invoice}/items', [SalesReturnLookupController::class, 'invoiceItems'])->whereNumber('invoice')->name('invoices.items');
        Route::get('/categories', [SalesReturnLookupController::class, 'categories'])->name('categories.index');
        Route::get('/categories/{category}/products', [SalesReturnLookupController::class, 'categoryProducts'])->whereNumber('category')->name('categories.products');
        Route::get('/products/search', [SalesReturnLookupController::class, 'products'])->name('products.search');
        Route::get('/products/{product}/variants', [SalesReturnLookupController::class, 'variants'])->whereNumber('product')->name('products.variants');
        Route::post('/preview', [SalesReturnLookupController::class, 'preview'])->name('preview');
        Route::get('/export/excel', [VoucherSalesReturnController::class, 'exportExcel'])->name('export.excel');
        Route::get('/export/pdf', [VoucherSalesReturnController::class, 'exportPdf'])->name('export.pdf');
        Route::get('/print', [VoucherSalesReturnController::class, 'printReport'])->name('print-report');
        Route::get('/print/customers', [VoucherSalesReturnController::class, 'printCustomers'])->name('print.customers');
        Route::get('/print/products', [VoucherSalesReturnController::class, 'printProducts'])->name('print.products');
        Route::get('/legacy/{transfer}/print', [VoucherSalesReturnController::class, 'printLegacy'])->whereNumber('transfer')->name('legacy.print');
        Route::get('/{document}', [VoucherSalesReturnController::class, 'show'])->whereNumber('document')->name('show');
        Route::get('/{document}/edit', [VoucherSalesReturnController::class, 'edit'])->whereNumber('document')->name('edit');
        Route::get('/{document}/applied-edit', [VoucherSalesReturnController::class, 'editApplied'])->whereNumber('document')->name('applied.edit');
        Route::patch('/{document}/applied-update', [VoucherSalesReturnController::class, 'updateApplied'])->whereNumber('document')->name('applied.update');
        Route::post('/{document}/void', [VoucherSalesReturnController::class, 'voidApplied'])->whereNumber('document')->name('applied.void');
        Route::patch('/{document}', [VoucherSalesReturnController::class, 'update'])->whereNumber('document')->name('update');
        Route::post('/{document}/apply', [VoucherSalesReturnController::class, 'apply'])->whereNumber('document')->name('apply');
        Route::post('/{document}/cancel', [VoucherSalesReturnController::class, 'cancel'])->whereNumber('document')->name('cancel');
        Route::get('/{document}/print', [VoucherSalesReturnController::class, 'print'])->whereNumber('document')->name('print');
        Route::get('/{document}/pdf', [VoucherSalesReturnController::class, 'pdf'])->whereNumber('document')->name('pdf');
    });

Route::get('/vouchers/section/{type}', [VoucherController::class, 'sectionIndex'])->name('vouchers.section.index');
Route::get('/vouchers/section/{type}/create', [VoucherController::class, 'sectionCreate'])->name('vouchers.section.create');
Route::post('/vouchers/section/{type}', [VoucherController::class, 'sectionStore'])->name('vouchers.section.store');

Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create');
Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');

Route::get('/vouchers/invoice/{uuid}/products', [VoucherController::class, 'invoiceProducts'])->name('vouchers.invoice.products');

Route::get('/vouchers/sale-delivery', [VoucherController::class, 'saleDeliveryIndex'])->name('vouchers.sale-delivery.index');
Route::get('/vouchers/sale-delivery/{uuid}/edit', [VoucherController::class, 'saleDeliveryEdit'])->name('vouchers.sale-delivery.edit');
Route::put('/vouchers/sale-delivery/{uuid}', [VoucherController::class, 'saleDeliveryUpdate'])->name('vouchers.sale-delivery.update');

Route::get('/vouchers/return/customers/{customer}/invoices', [VoucherController::class, 'customerInvoices'])->name('vouchers.return.customer.invoices');

Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('/notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');

Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->name('vouchers.show');
Route::get('/vouchers/{voucher}/edit', [VoucherController::class, 'edit'])->name('vouchers.edit');
Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');
    Route::get('/warehouse-outputs', [VoucherController::class, 'outputs'])->name('warehouse.outputs');

    // Asset trustee module (امین اموال)
    Route::prefix('warehouse/asset-trustee')->name('asset.')->group(function () {
        Route::get('/', [AssetTrusteeController::class, 'hub'])->name('hub');

        Route::get('/personnel', [AssetPersonnelController::class, 'index'])->name('personnel.index');
        Route::get('/personnel/create', [AssetPersonnelController::class, 'create'])->name('personnel.create');
        Route::post('/personnel', [AssetPersonnelController::class, 'store'])->name('personnel.store');
        Route::get('/personnel/{personnel}', [AssetPersonnelController::class, 'show'])->name('personnel.show');
        Route::get('/personnel/{personnel}/edit', [AssetPersonnelController::class, 'edit'])->name('personnel.edit');
        Route::put('/personnel/{personnel}', [AssetPersonnelController::class, 'update'])->name('personnel.update');
        Route::patch('/personnel/{personnel}/toggle-status', [AssetPersonnelController::class, 'toggleStatus'])->name('personnel.toggle-status');

        Route::get('/documents', [AssetDocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/create', [AssetDocumentController::class, 'create'])->name('documents.create');
        Route::post('/documents', [AssetDocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [AssetDocumentController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/view', [AssetDocumentController::class, 'view'])->name('documents.view');
        Route::get('/documents/{document}/print', [AssetDocumentController::class, 'print'])->name('documents.print');
        Route::get('/documents/{document}/signed-form', [AssetDocumentController::class, 'signedFormView'])->name('documents.signed-form.view');
        Route::get('/documents/{document}/signed-form/download', [AssetDocumentController::class, 'signedFormDownload'])->name('documents.signed-form.download');
        Route::get('/documents/{document}/edit', [AssetDocumentController::class, 'edit'])->name('documents.edit');
        Route::put('/documents/{document}', [AssetDocumentController::class, 'update'])->name('documents.update');
        Route::patch('/documents/{document}/finalize', [AssetDocumentController::class, 'finalize'])->name('documents.finalize');
        Route::patch('/documents/{document}/cancel', [AssetDocumentController::class, 'cancel'])->name('documents.cancel');

        Route::get('/search', [AssetDocumentController::class, 'codeSearchPage'])->name('codes.search');
        Route::get('/codes/{code}', [AssetDocumentController::class, 'findByCode'])->name('codes.find');
    });


    // Sales Havaleh APIs
    Route::post('/sales-havaleh/create-from-financial/{financialId}', [SalesHavalehController::class, 'createFromFinancial'])->name('sales-havaleh.create-from-financial');
    Route::get('/sales-havaleh/{invoice}', [SalesHavalehController::class, 'show'])->name('sales-havaleh.show');
    Route::get('/sales-havaleh/{invoice}/view', [SalesHavalehController::class, 'view'])->name('sales-havaleh.view');
    Route::put('/sales-havaleh/{invoice}', [SalesHavalehController::class, 'update'])->name('sales-havaleh.update');
    Route::patch('/sales-havaleh/{invoice}/status', [SalesHavalehController::class, 'patchStatus'])->name('sales-havaleh.status');
    Route::get('/sales-havaleh/{invoice}/history', [SalesHavalehController::class, 'history'])->name('sales-havaleh.history');
    Route::get('/payments/{payment}/view', [AccountStatementController::class, 'showPayment'])->name('payments.view');

    // Warehouse Map
    Route::prefix('warehouse-map')->name('warehouse-map.')->group(function () {
        Route::get('/', [WarehouseMapController::class, 'index'])->name('index');
        Route::get('/locations/{location}', [WarehouseMapController::class, 'showLocation'])->name('locations.show');
        Route::get('/categories/{category}/children', [WarehouseMapController::class, 'categoryChildren'])->name('categories.children');
        Route::get('/categories/{category}/products', [WarehouseMapController::class, 'categoryProducts'])->name('categories.products');
        Route::get('/products/{product}/variants', [WarehouseMapController::class, 'productVariants'])->name('products.variants');
        Route::get('/history', [WarehouseMapController::class, 'history'])->name('history');

        Route::group([], function () {
            Route::post('/locations', [WarehouseMapController::class, 'storeLocation'])->name('locations.store');
            Route::put('/locations/{location}', [WarehouseMapController::class, 'updateLocation'])->name('locations.update');
            Route::post('/assign', [WarehouseMapController::class, 'assign'])->name('assign');
            Route::post('/transfer', [WarehouseMapController::class, 'transfer'])->name('transfer');
        });
    });

    // Warehouses
    Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');

    Route::get('/warehouses/{warehouse}/personnel', [WarehouseController::class, 'personnelIndex'])->name('warehouses.personnel.index');
    Route::post('/warehouses/{warehouse}/personnel', [WarehouseController::class, 'personnelStore'])->name('warehouses.personnel.store');
    Route::get('/warehouses/{warehouse}/personnel/{personnel}', [WarehouseController::class, 'personnelShow'])->name('warehouses.personnel.show');

    // Purchases
    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/export', [PurchaseController::class, 'exportExcel'])->name('purchases.export');
    Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::get('/purchases/products/{product}/variants', [PurchaseController::class, 'productVariants'])->name('purchases.products.variants');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
    Route::get('/purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->name('purchases.edit');
    Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
    Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');

    // Persons
    Route::get('/persons', [PersonController::class, 'index'])->name('persons.index');
    Route::post('/persons', [PersonController::class, 'store'])->name('persons.store');
    Route::put('/persons/{personKey}', [PersonController::class, 'update'])->name('persons.update');

    // Suppliers
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');

    // Stocktake / Stock Count Documents
    Route::get('/stocktake', [StocktakeController::class, 'index'])->name('stocktake.index');
    Route::get('/stock-count-documents', [StocktakeController::class, 'index'])->name('stock-count-documents.index');
    Route::get('/stock-count-documents/create', [StocktakeController::class, 'create'])->name('stock-count-documents.create');
    Route::post('/stock-count-documents', [StocktakeController::class, 'store'])->name('stock-count-documents.store');
    Route::get('/stock-count-documents/subcategories', [StocktakeController::class, 'subcategories'])->name('stock-count-documents.subcategories');
    Route::get('/stock-count-documents/products/search', [StocktakeController::class, 'products'])->name('stock-count-documents.products');
    Route::get('/stock-count-documents/products/{product}/variants', [StocktakeController::class, 'variants'])->name('stock-count-documents.variants');
    Route::get('/stock-count-documents/{stockCountDocument}', [StocktakeController::class, 'show'])->name('stock-count-documents.show');
    Route::get('/stock-count-documents/{stockCountDocument}/view', [StocktakeController::class, 'view'])->name('stock-count-documents.view');
    Route::get('/stock-count-documents/{stockCountDocument}/edit', [StocktakeController::class, 'edit'])->name('stock-count-documents.edit');
    Route::put('/stock-count-documents/{stockCountDocument}', [StocktakeController::class, 'update'])->name('stock-count-documents.update');
    Route::patch('/stock-count-documents/{stockCountDocument}/finalize', [StocktakeController::class, 'finalize'])->name('stock-count-documents.finalize');
    Route::patch('/stock-count-documents/{stockCountDocument}/cancel', [StocktakeController::class, 'cancel'])->name('stock-count-documents.cancel');
    Route::get('/stock-count-documents-system-quantity', [StocktakeController::class, 'systemQuantity'])->name('stock-count-documents.system-quantity');

    // Preinvoice pages
    Route::get('/preinvoice/create', [PreinvoiceController::class, 'create'])->name('preinvoice.create');
    Route::post('/preinvoice/draft', [PreinvoiceController::class, 'saveDraft'])->name('preinvoice.draft.save');
    Route::post('/preinvoice/autosave', [PreinvoiceController::class, 'autosave'])->name('preinvoice.autosave');
    Route::get('/preinvoice/autosave/latest', [PreinvoiceController::class, 'latestAutosave'])->name('preinvoice.autosave.latest');
    Route::post('/preinvoice/autosave/{uuid}/discard', [PreinvoiceController::class, 'discardAutosave'])->name('preinvoice.autosave.discard');
    Route::post('/preinvoice/reservations/heartbeat', [PreinvoiceController::class, 'heartbeatReservations'])->name('preinvoice.reservations.heartbeat');
    Route::post('/preinvoice/reservations/release-token', [PreinvoiceController::class, 'releaseReservationToken'])->name('preinvoice.reservations.release-token');

    Route::prefix('warehouse/reviews')->name('warehouse.reviews.')->group(function () {
        Route::get('/', [WarehouseReviewController::class, 'index'])->name('index');
        Route::get('/{preinvoiceOrder:uuid}', [WarehouseReviewController::class, 'show'])->name('show');
        Route::get('/{preinvoiceOrder:uuid}/print', [WarehouseReviewController::class, 'print'])->name('print');
    });

    Route::get('/preinvoice/warehouse', [PreinvoiceController::class, 'warehouseQueue'])->name('preinvoice.warehouse.index');
    Route::get('/preinvoice/warehouse/{uuid}', [PreinvoiceController::class, 'warehouseReview'])->name('preinvoice.warehouse.review');
    Route::put('/preinvoice/warehouse/{uuid}', [PreinvoiceController::class, 'warehouseSave'])->name('preinvoice.warehouse.save');
    Route::post('/preinvoice/warehouse/{uuid}/approve', [PreinvoiceController::class, 'warehouseApprove'])->name('preinvoice.warehouse.approve');
    Route::post('/preinvoice/warehouse/{uuid}/reject', [PreinvoiceController::class, 'warehouseReject'])->name('preinvoice.warehouse.reject');
    Route::get('/preinvoice/drafts', [PreinvoiceController::class, 'draftIndex'])->name('preinvoice.draft.index');
    Route::get('/preinvoice/drafts/{uuid}/edit', [PreinvoiceController::class, 'editDraft'])->name('preinvoice.draft.edit');
    Route::put('/preinvoice/drafts/{uuid}', [PreinvoiceController::class, 'updateDraft'])->name('preinvoice.draft.update');
    Route::get('/preinvoice/drafts/{uuid}/finance', [PreinvoiceController::class, 'finance'])->name('preinvoice.draft.finance');
    Route::get('/preinvoice/drafts/{uuid}/finance/edit', [PreinvoiceController::class, 'financeEdit'])->name('preinvoice.draft.finance.edit');
    Route::put('/preinvoice/drafts/{uuid}/finance', [PreinvoiceController::class, 'financeUpdate'])->name('preinvoice.draft.finance.update');
    Route::post('/preinvoice/drafts/{uuid}/finance/save-and-finalize', [PreinvoiceController::class, 'financeSaveAndFinalize'])->name('preinvoice.draft.finance.save-and-finalize');
    Route::post('/preinvoice/drafts/{uuid}/finalize', [PreinvoiceController::class, 'finalize'])->name('preinvoice.draft.finalize');
    Route::post('/preinvoice/drafts/{uuid}/return', [PreinvoiceController::class, 'financeReturn'])->name('preinvoice.draft.return');
    Route::post('/preinvoice/drafts/{uuid}/cancel', [PreinvoiceController::class, 'financeCancel'])->name('preinvoice.draft.cancel');
    Route::get('/preinvoice/all', [PreinvoiceController::class, 'allIndex'])->name('preinvoice.all.index');
    Route::get('/preinvoice/my', [PreinvoiceController::class, 'myIndex'])->name('preinvoice.my.index');
    Route::get('/preinvoice/my/{uuid}', [PreinvoiceController::class, 'myShow'])->name('preinvoice.my.show');
    Route::get('/preinvoice/{uuid}/print', [ArchiveController::class, 'showPreinvoice'])->name('preinvoice.print');

    // Preinvoice APIs
    Route::prefix('preinvoice/api')->group(function () {
        Route::get('/product-finder', [PreinvoiceApiController::class, 'productFinder'])->name('preinvoice.api.product-finder');
        Route::get('/product-finder/categories', [PreinvoiceApiController::class, 'productFinderCategories'])->name('preinvoice.api.product-finder.categories');
        Route::get('/products', [PreinvoiceApiController::class, 'products'])->name('preinvoice.api.products');
        Route::get('/products/{product}', [PreinvoiceApiController::class, 'product'])->name('preinvoice.api.product');
        Route::post('/reservations/sync', [PreinvoiceApiController::class, 'syncDraftReservation'])->name('preinvoice.api.reservations.sync');
        Route::post('/reservations/release', [PreinvoiceApiController::class, 'releaseDraftReservation'])->name('preinvoice.api.reservations.release');
        Route::get('/area', [PreinvoiceApiController::class, 'area'])->name('preinvoice.api.area');
        Route::get('/customers', [CustomerApiController::class, 'search'])->name('api.customers.search');
        Route::post('/customers', [CustomerApiController::class, 'store'])->name('api.customers.store');
        Route::get('/customers/{customer}', [CustomerApiController::class, 'show'])->name('api.customers.show');
    });

    // Customers
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    Route::post('/customers/import', [CustomerController::class, 'import'])->name('customers.import');
    Route::redirect('/archive', '/invoices')->name('archive.index');
    Route::get('/archive/preinvoices/{uuid}', [ArchiveController::class, 'showPreinvoice'])->name('archive.preinvoices.show');
    Route::get('/archive/invoices/{uuid}', [ArchiveController::class, 'showInvoice'])->name('archive.invoices.show');
    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/data', [InvoiceController::class, 'data'])->name('invoices.data');
        Route::get('/customers/search', [InvoiceController::class, 'customersSearch'])->name('invoices.customers.search');
        Route::get('/cancelled', [InvoiceController::class, 'cancelled'])->name('invoices.cancelled');
        Route::post('/bulk/reassign-seller', [InvoiceController::class, 'bulkReassignSeller'])->name('invoices.bulk.reassign-seller');
        Route::get('/{uuid}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::get('/{uuid}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/{uuid}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::get('/{uuid}/history', [InvoiceController::class, 'history'])->name('invoices.history');
        Route::get('/{uuid}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/{uuid}/status', [InvoiceController::class, 'updateStatus'])->name('invoices.status');
        Route::post('/{uuid}/reassign-seller', [InvoiceController::class, 'reassignSeller'])->name('invoices.reassign-seller');
        Route::post('/{uuid}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('/{uuid}/cancel/undo', [InvoiceController::class, 'undoCancel'])->name('invoices.cancel.undo');
        Route::post('/{uuid}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
        Route::post('/{uuid}/notes', [InvoiceNoteController::class, 'store'])->name('invoices.notes.store');
        Route::post('/payments/{payment}/cheque', [ChequeController::class, 'store'])->name('cheques.store');
    });

    // Account statements (گردش حساب اشخاص)
    Route::get('/account-statements', [AccountStatementController::class, 'index'])->name('account-statements.index');
    Route::post('/account-statements/{customer}/payments', [InvoicePaymentController::class, 'storeForCustomer'])->name('account-statements.payments.store');
    Route::get('/account-statements/documents/invoices/{uuid}', [AccountStatementController::class, 'showInvoice'])->name('account-statements.documents.invoices.show');
    Route::get('/account-statements/documents/returns/{voucher}', [AccountStatementController::class, 'showReturnFromSale'])->name('account-statements.documents.returns.show');
    Route::get('/account-statements/documents/payments/{payment}', [AccountStatementController::class, 'showPayment'])->name('account-statements.documents.payments.show');
    Route::get('/account-statements/{customer}', [AccountStatementController::class, 'show'])->name('account-statements.show');

    // Activity logs
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    // Users (External CRM)
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/sync', [UserController::class, 'sync'])->name('users.sync');

    Route::prefix('admin/bug-investigator')->name('admin.bug-investigator.')->group(function () {
        Route::get('/', [BugInvestigatorController::class, 'index'])->name('index');
        Route::get('/create', [BugInvestigatorController::class, 'create'])->name('create');
        Route::post('/', [BugInvestigatorController::class, 'store'])->name('store');
        Route::post('/{bugCase}/rerun', [BugInvestigatorController::class, 'rerun'])->name('rerun');
        Route::get('/{bugCase}', [BugInvestigatorController::class, 'show'])->name('show');
    });

    Route::get('/admin/permissions', [UserPermissionController::class, 'index'])->name('admin.permissions.index');
    Route::put('/admin/permissions/{user}', [UserPermissionController::class, 'update'])->name('admin.permissions.update');
    Route::resource('/admin/roles', RoleController::class)->except(['show'])->names('admin.roles');
});
Route::post('model-lists/import-phone-catalog', [ModelListController::class, 'importPhoneCatalog'])
    ->middleware(['auth', 'route.permission'])
    ->name('model-lists.import-phone-catalog');



// اگر این دو route را از قبل نداری، داخل routes/web.php اضافه کن.
// اگر route مشابه داری، فقط مطمئن شو URL ها با همین دو آدرس یکی باشند.

Route::get('/vouchers/return/customers/{customer}/invoices', [VoucherController::class, 'customerInvoices'])
    ->middleware(['auth', 'route.permission'])
    ->name('vouchers.return.customer-invoices');

Route::get('/vouchers/invoice/{uuid}/products', [VoucherController::class, 'invoiceProducts'])
    ->middleware(['auth', 'route.permission'])
    ->name('vouchers.invoice.products');

Route::get('/finance/cheques', [ChequeController::class, 'index'])
    ->middleware(['auth', 'route.permission'])
    ->name('finance.cheques.index');

require __DIR__.'/auth.php';
