<?php

use App\Http\Controllers\SalesReturnLookupController;
use App\Models\Invoice;
use Illuminate\Http\Request;

it('does not send a document id when loading invoice items for a new return', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)
        ->toContain('const params=new URLSearchParams')
        ->toContain('const editingDocumentId=@json($document?->id)')
        ->toContain("if(editingDocumentId!==null")
        ->toContain("params.set('document_id',String(editingDocumentId))")
        ->not->toContain('encodeURIComponent(@json($document?->id))');
});

it('rejects malformed return document ids with a validation error instead of a missing document', function () {
    foreach (['null', 'undefined', '0', '-1', 'invalid', '1.5', ['invalid']] as $invalidId) {
        $request = Request::create('/vouchers/section/return-from-sale/invoices/1/items', 'GET', [
            'document_id' => $invalidId,
        ]);

        $response = app(SalesReturnLookupController::class)->invoiceItems(new Invoice(), $request);

        expect($response->getStatusCode())->toBe(422)
            ->and($response->getData(true))->toHaveKey('message');
    }
});

it('shows an error in the invoice-items dialog instead of leaving the loading indicator stuck', function () {
    $view = file_get_contents(resource_path('views/vouchers/return-from-sale/create.blade.php'));

    expect($view)
        ->toContain("const message=payload.message||'خطا در دریافت اقلام فاکتور'")
        ->toContain("if(body)body.innerHTML='<tr><td colspan=\"8\" class=\"text-center text-danger\">'+esc(message)+'</td></tr>'");
});
