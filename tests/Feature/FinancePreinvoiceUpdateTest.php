<?php

use App\Http\Controllers\PreinvoiceController;
use App\Http\Requests\FinanceUpdatePreinvoiceRequest;
use App\Models\PreinvoiceOrderReview;
use Illuminate\Support\Facades\File;

it('keeps finance save separated from finalize workflow', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $start = strpos($controller, 'public function financeUpdate');
    $end = strpos($controller, 'public function finance(string $uuid)', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)->toContain('DB::transaction')
        ->and($method)->toContain("redirect()->route('preinvoice.draft.finance'")
        ->and($method)->not->toContain('finalize(')
        ->and($method)->not->toContain('Invoice::create')
        ->and($method)->not->toContain('InvoiceItem::create')
        ->and($method)->not->toContain('consumeOfficialReservationsForOrder')
        ->and($method)->not->toContain("'status'");
});

it('persists finance edits on original preinvoice item fields', function () {
    $controller = File::get(app_path('Http/Controllers/PreinvoiceController.php'));
    $start = strpos($controller, 'public function financeUpdate');
    $end = strpos($controller, 'public function finance(string $uuid)', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)->toContain("'quantity'")
        ->and($method)->toContain("'price'")
        ->and($method)->toContain("'line_discount_amount'")
        ->and($method)->toContain('SalesDocumentTotals::calculate')
        ->and($method)->toContain("'action' => 'finance_edited'");
});

it('uses a varchar-compatible review action and save-only request action', function () {
    $review = new PreinvoiceOrderReview();
    $request = new FinanceUpdatePreinvoiceRequest();

    expect($review->getFillable())->toContain('action')
        ->and($request->rules()['action'])->toContain('in:save');
});
