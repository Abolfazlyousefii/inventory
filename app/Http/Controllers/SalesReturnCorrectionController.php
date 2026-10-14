<?php

namespace App\Http\Controllers;

use App\Models\SalesReturnCorrection;
use App\Models\Warehouse;
use App\Services\SalesReturnCorrectionService;
use App\Support\PageAccessCatalog;
use Illuminate\Http\Request;

class SalesReturnCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canWarehouse = PageAccessCatalog::userCanRoute($user, 'warehouse.inbound.receive');
        $canFinance = PageAccessCatalog::userCanRoute($user, 'finance.invoices.reapprove');
        $canWarranty = PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.index');
        abort_unless($canWarehouse || $canFinance || $canWarranty, 403);
        $filters = $request->validate(['status' => ['nullable', 'in:pending_warehouse,pending_finance,applied,rejected,all'], 'document_id' => ['nullable', 'integer', 'min:1']]);
        $corrections = SalesReturnCorrection::with(['document:id,document_number', 'requester:id,name', 'warehouseApprover:id,name', 'financeApprover:id,name', 'rejecter:id,name'])
            ->when(isset($filters['document_id']), fn ($q) => $q->where('document_id', $filters['document_id']))
            ->when(($filters['status'] ?? 'pending_warehouse') !== 'all', fn ($q) => $q->where('status', $filters['status'] ?? 'pending_warehouse'))
            ->latest('id')->paginate(15)->withQueryString();
        $warehouseNames = Warehouse::pluck('name', 'id');

        return view('vouchers.return-from-sale.corrections', compact('corrections', 'filters', 'canWarehouse', 'canFinance', 'warehouseNames'));
    }

    public function warehouse(Request $request, SalesReturnCorrection $correction, SalesReturnCorrectionService $service)
    {
        abort_unless(PageAccessCatalog::userCanRoute($request->user(), 'warehouse.inbound.receive'), 403);
        $service->approve($correction, 'warehouse', (int) $request->user()->id, $this->note($request));

        return back()->with('success', 'بررسی انبار ثبت شد؛ درخواست برای تأیید مالی آماده است. موجودی هنوز تغییر نکرده است.');
    }

    public function finance(Request $request, SalesReturnCorrection $correction, SalesReturnCorrectionService $service)
    {
        abort_unless(PageAccessCatalog::userCanRoute($request->user(), 'finance.invoices.reapprove'), 403);
        $service->approve($correction, 'finance', (int) $request->user()->id, $this->note($request));

        return back()->with('success', 'اصلاح با تأیید انبار و مالی ثبت شد؛ فقط اختلاف موجودی و حساب اعمال شد.');
    }

    public function reject(Request $request, SalesReturnCorrection $correction, SalesReturnCorrectionService $service)
    {
        $route = $correction->status === 'pending_warehouse' ? 'warehouse.inbound.receive' : 'finance.invoices.reapprove';
        abort_unless((int) $correction->requested_by === (int) $request->user()->id || PageAccessCatalog::userCanRoute($request->user(), $route), 403);
        $service->reject($correction, (int) $request->user()->id, $this->note($request), $correction->status);

        return back()->with('success', 'درخواست رد شد؛ موجودی و حساب مشتری تغییر نکرد.');
    }

    private function note(Request $request): string
    {
        return $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']])['note'];
    }
}
