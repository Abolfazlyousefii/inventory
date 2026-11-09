<?php

namespace App\Http\Controllers;

use App\Models\{ActivityLog, Customer};
use App\Services\CustomerBalanceAdjustmentService;
use App\Support\{AccountStatementAccess, PermissionCatalog};
use Illuminate\Http\Request;

class CustomerBalanceAdjustmentController extends Controller
{
    public function __construct(private readonly CustomerBalanceAdjustmentService $service) {}

    private function authorizeChange(): void
    {
        abort_unless(auth()->user() && !AccountStatementAccess::readOnly(auth()->user())
            && PermissionCatalog::userHasPermission(auth()->user(), 'account_statements.adjust'), 403);
    }

    public function edit(Customer $customer, ActivityLog $adjustment)
    {
        $this->authorizeChange();
        $ledger = $this->service->ledger($customer, $adjustment);
        $version = $this->service->version($adjustment, $ledger);
        return view('account-statements.adjustment-edit', compact('customer', 'adjustment', 'ledger', 'version'));
    }

    public function update(Customer $customer, ActivityLog $adjustment, Request $request)
    {
        $this->authorizeChange();
        if (is_string($request->input('amount'))) {
            $amount = strtr($request->input('amount'), array_combine(
                preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
                str_split('01234567890123456789')));
            $request->merge(['amount' => preg_replace('/[\s,٬،]+/u', '', $amount)]);
        }
        $data = $request->validate([
            'type' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $this->service->change($customer, $adjustment, $data);
        return redirect()->route('account-statements.show', $customer)->with('success', 'سند اصلاحی و مانده حساب به‌روزرسانی شد.');
    }

    public function destroy(Customer $customer, ActivityLog $adjustment, Request $request)
    {
        $this->authorizeChange();
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['required', 'string', 'size:64']]);
        $this->service->change($customer, $adjustment, $data, true);
        return redirect()->route('account-statements.show', $customer)->with('success', 'اثر سند اصلاحی حذف شد؛ سابقه آن محفوظ است.');
    }
}
