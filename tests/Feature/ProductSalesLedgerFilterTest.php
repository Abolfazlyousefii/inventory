<?php

use App\Http\Controllers\ProductSalesLedgerController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\SalesDocumentAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function salesLedgerFixture(): Product
{
    $category = Category::create(['name' => 'Ledger']);
    $product = Product::withoutEvents(fn () => Product::create(['category_id' => $category->id, 'name' => 'کالای تست', 'sku' => 'LEDGER-TEST', 'stock' => 20, 'price' => 1000]));
    $customer = Customer::create(['first_name' => 'علي', 'last_name' => 'كريمي', 'mobile' => '09121112233']);
    foreach ([['LEDGER-1', $customer->id, 'علي كريمي', '09121112233', 2, 'shipped'], ['LEDGER-2', null, 'علی کریمی', '09121112233', 3, 'shipped'], ['OTHER', null, 'رضا محمدی', '09350000000', 7, 'shipped'], ['CANCELLED', $customer->id, 'علي كريمي', '09121112233', 9, 'not_shipped']] as [$uuid, $customerId, $name, $mobile, $quantity, $status]) {
        $invoice = Invoice::create(['uuid' => $uuid, 'customer_id' => $customerId, 'customer_name' => $name, 'customer_mobile' => $mobile, 'status' => $status, 'total' => $quantity * 1000, 'subtotal' => $quantity * 1000]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => $quantity, 'price' => 1000]);
    }
    return $product;
}

it('searches customer snapshots and linked customer names with Persian Arabic letters and digits', function (string $search) {
    $product = salesLedgerFixture();
    $request = Request::create('/ledger', 'GET', ['customer_search' => $search]);
    $data = app(ProductSalesLedgerController::class)->index($request, $product, app(SalesDocumentAccessService::class))->getData();
    expect($data['ledgerItems']->total())->toBe(2)
        ->and((int) $data['summary']->total_quantity)->toBe(5)
        ->and((int) $data['summary']->total_amount)->toBe(5000);
})->with(['علی کریمی', 'علي كريمي', '۰۹۱۲۱۱۱۲۲۳۳']);

it('combines customer search with existing filters and exports only matching sales', function () {
    $product = salesLedgerFixture();
    $request = Request::create('/ledger', 'GET', ['customer_search' => 'کریمی', 'invoice_uuid' => 'LEDGER-1', 'export' => 'csv']);
    $response = app(ProductSalesLedgerController::class)->index($request, $product, app(SalesDocumentAccessService::class));
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();
    expect($csv)->toContain('LEDGER-1')->not->toContain('LEDGER-2')->not->toContain('CANCELLED')->not->toContain('OTHER');
});

it('returns an empty ledger for an unmatched customer', function () {
    $product = salesLedgerFixture();
    $request = Request::create('/ledger', 'GET', ['customer_search' => 'ناموجود']);
    $data = app(ProductSalesLedgerController::class)->index($request, $product, app(SalesDocumentAccessService::class))->getData();
    expect($data['ledgerItems']->total())->toBe(0)->and((int) $data['summary']->total_amount)->toBe(0);
});

it('matches updated linked customer names while retaining exact customer filtering', function () {
    $product = salesLedgerFixture();
    $customer = Customer::first();
    $customer->update(['first_name' => 'حسن', 'last_name' => 'اکبری']);
    $request = Request::create('/ledger', 'GET', ['customer_search' => 'حسن اکبري', 'customer_id' => $customer->id]);
    $data = app(ProductSalesLedgerController::class)->index($request, $product, app(SalesDocumentAccessService::class))->getData();
    expect($data['ledgerItems']->total())->toBe(1)->and((int) $data['summary']->total_quantity)->toBe(2);
});

it('renders the ledger and keeps filtered totals across pagination', function () {
    $product = salesLedgerFixture();
    $invoice = Invoice::where('uuid', 'LEDGER-1')->first();
    for ($i = 0; $i < 27; $i++) {
        InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 1000]);
    }
    $request = Request::create('/ledger', 'GET', ['customer_search' => 'کریمی']);
    app()->instance('request', $request);
    $view = app(ProductSalesLedgerController::class)->index($request, $product, app(SalesDocumentAccessService::class));
    $data = $view->getData();
    expect($data['ledgerItems']->total())->toBe(29)->and($data['ledgerItems']->count())->toBe(25)
        ->and((int) $data['summary']->total_quantity)->toBe(32)->and((int) $data['summary']->total_amount)->toBe(32000);
    $html = $view->with('errors', new \Illuminate\Support\ViewErrorBag())->render();
    expect($html)->toContain('sl-customer-search', 'کارتکس فروش', 'product-sales-ledger.css');
    if (getenv('SALES_LEDGER_QA_RENDER')) {
        file_put_contents(storage_path('app/stock-ledger-qa/sales-ledger-preview.html'), $html);
    }
});

