<?php

namespace App\Http\Controllers;

use App\Http\Requests\MutateFinancialAccountDocumentRequest;
use App\Http\Requests\StoreFinancialAccountDocumentRequest;
use App\Models\Customer;
use App\Models\FinancialAccountDocument;
use App\Services\FinancialAccountDocumentService;
use App\Services\PersonAccountStatementService;
use App\Support\AccountStatementAccess;
use App\Support\PermissionCatalog;
use Illuminate\Validation\ValidationException;

class FinancialAccountDocumentController extends Controller
{
    public function edit(FinancialAccountDocument $document)
    {
        $actor = auth()->user();
        abort_unless($actor && ! AccountStatementAccess::readOnly($actor)
            && PermissionCatalog::userHasPermission($actor, 'account_statements.adjust'), 403);
        abort_if($document->cancelled_at, 404);
        $backUrl = $this->statementUrl($document);

        return view('account-statements.financial-document-edit', compact('document', 'backUrl'));
    }

    public function update(FinancialAccountDocument $document, MutateFinancialAccountDocumentRequest $request, FinancialAccountDocumentService $service)
    {
        $service->revise($document, $request->validated(), $request->user()->id);

        return redirect($this->statementUrl($document))->with('success', 'سند مالی و مانده حساب به‌روز شد.');
    }

    public function destroy(FinancialAccountDocument $document, MutateFinancialAccountDocumentRequest $request, FinancialAccountDocumentService $service)
    {
        $service->revise($document, $request->validated(), $request->user()->id, true);

        return redirect($this->statementUrl($document))->with('success', 'اثر سند مالی از مانده حساب حذف شد؛ سابقه محفوظ است.');
    }

    private function statementUrl(FinancialAccountDocument $document): string
    {
        return $document->customer_id ? route('account-statements.show', $document->customer_id)
            : route('persons.account', 'supplier-only-'.$document->supplier_id);
    }

    public function storeCustomer(Customer $customer, StoreFinancialAccountDocumentRequest $request, FinancialAccountDocumentService $service)
    {
        if ($request->filled('account_role') && $request->input('account_role') !== 'customer') {
            throw ValidationException::withMessages(['account_role' => 'این صفحه مربوط به دفتر مشتری است.']);
        }
        $service->create($customer, $request->validated(), $request->user()->id);

        return redirect()->route('account-statements.show', $customer)->with('success', 'سند مالی در گردش همین شخص ثبت شد.');
    }

    public function storePerson(string $personKey, StoreFinancialAccountDocumentRequest $request, PersonAccountStatementService $people, FinancialAccountDocumentService $service)
    {
        $person = $people->resolve($personKey);
        $role = $request->input('account_role');
        if (! $role && $person['is_customer'] && $person['is_supplier']) {
            throw ValidationException::withMessages(['account_role' => 'دفتر مشتری یا تأمین‌کننده را برای این سند مشخص کنید.']);
        }
        $role ??= $person['is_supplier'] ? 'supplier' : 'customer';
        $account = $person[$role] ?? null;
        if (! $account) {
            throw ValidationException::withMessages(['account_role' => 'این نقش برای شخص موردنظر وجود ندارد.']);
        }
        $service->create($account, $request->validated(), $request->user()->id);

        return redirect()->route('persons.account', $personKey)->with('success', 'سند مالی در گردش همین شخص ثبت شد.');
    }
}
