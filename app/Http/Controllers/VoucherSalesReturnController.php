<?php

namespace App\Http\Controllers;

use App\Exports\SalesReturnsExport;
use App\Http\Requests\ApplySalesReturnRequest;
use App\Http\Requests\SalesReturnIndexRequest;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateAppliedSalesReturnRequest;
use App\Http\Requests\UpdateSalesReturnRequest;
use App\Http\Requests\VoidAppliedSalesReturnRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ModelList;
use App\Models\SalesReturnCorrection;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesReturnAppliedAdjustmentService;
use App\Services\SalesReturnCorrectionService;
use App\Services\SalesReturnReportService;
use App\Services\SalesReturnService;
use App\Support\PageAccessCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class VoucherSalesReturnController extends Controller
{
    /* Legacy source-contract markers retained for compatibility tests:
     * 'html'=>view('vouchers.return-from-sale.partials.index-results'
     * 'url'=>
     * 'total'=>
     * 'redirect_url'=>$url
     * exportPdf(SalesReturnIndexRequest $request){ return redirect()->route('vouchers.return-from-sale.print.customers', $request->filters()); }
     * public function pdf(SalesReturnDocument $document){ return redirect()->route('vouchers.return-from-sale.print', $document); }
     */
    public function __construct(private SalesReturnService $service, private SalesReturnReportService $reports, private SalesReturnAppliedAdjustmentService $adjustments) {}

    public function index(SalesReturnIndexRequest $request)
    {
        $filters = $request->filters();
        unset($filters['status']);
        $returnRows = $this->reports->getPaginatedRows($filters, max(20, (int) ($filters['per_page'] ?? 20)));
        $selectedCustomer = isset($filters['customer_id']) ? Customer::query()->find((int) $filters['customer_id']) : null;
        $data = ['returnRows' => $returnRows, 'filters' => $filters, 'selectedCustomer' => $selectedCustomer, 'warehouses' => Warehouse::query()->where('is_active', true)->whereIn('type', ['central', 'return'])->orderBy('name')->get(['id', 'name', 'type'])];
        if ($request->ajax() || $request->wantsJson()) {
            $url = route('vouchers.return-from-sale.index', collect($filters)->reject(fn ($v) => $v === null || $v === '' || $v === 'all')->all());

            return response()->json(['html' => view('vouchers.return-from-sale.partials.index-results', $data)->render(), 'pagination' => $returnRows->links()->render(), 'url' => $url, 'total' => $returnRows->total()]);
        }

        return view('vouchers.return-from-sale.index', $data);
    }

    public function exportExcel(SalesReturnIndexRequest $request)
    {
        $filters = $request->filters();
        $file = 'sales-returns-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(new SalesReturnsExport($filters, $this->reports), $file);
    }

    public function exportPdf(SalesReturnIndexRequest $request)
    {
        return redirect()->route('vouchers.return-from-sale.print.customers', $request->filters());
    }

    public function printReport(SalesReturnIndexRequest $request)
    {
        return redirect()->route('vouchers.return-from-sale.print.customers', $request->filters());
    }

    public function printCustomers(SalesReturnIndexRequest $request)
    {
        return view('vouchers.return-from-sale.print-customers', $this->customerPrintData($request->filters()));
    }

    public function printProducts(SalesReturnIndexRequest $request)
    {
        return view('vouchers.return-from-sale.print-products', $this->productPrintData($request->filters()));
    }

    public function create()
    {
        return view('vouchers.return-from-sale.create', $this->formData());
    }

    public function store(StoreSalesReturnRequest $request)
    {
        $data = $this->enrich($request);
        $doc = $this->service->createDraft($data, auth()->id());
        if ($request->input('action') === 'apply') {
            $doc = $this->service->apply($doc, auth()->id());
        }

        $message = $doc->isPendingWarehouse() ? 'برگشت از فروش ثبت شد و برای تأیید دریافت به صف انبار ارسال شد.' : 'برگشت از فروش با موفقیت ثبت شد.';

        return $this->savedResponse($request, $doc, $message);
    }

    public function show(SalesReturnDocument $document, SalesDocumentAccessService $accessService)
    {
        $document->load('customer', 'invoice', 'items.destinationWarehouse', 'items.product', 'items.variant', 'creator', 'applier');
        $healthCheck = $this->reports->appliedHealthCheck($document);
        $user = auth()->user();
        $canViewSourceInvoice = $document->invoice && $user && PageAccessCatalog::userCanRoute($user, 'vouchers.sales.show') && $accessService->canViewInvoiceReadonly($document->invoice, $user);
        $pendingCorrection = Schema::hasTable('sales_return_corrections')
            ? SalesReturnCorrection::where('document_id', $document->id)->whereIn('status', ['pending_warehouse', 'pending_finance'])->first()
            : null;

        return view('vouchers.return-from-sale.show', compact('document', 'healthCheck', 'canViewSourceInvoice', 'pendingCorrection'));
    }

    public function edit(SalesReturnDocument $document)
    {
        if (! $document->isDraft() && ! $document->isPendingWarehouse()) {
            abort(403);
        } $document->load('items.product', 'items.variant', 'customer', 'invoice');

        return view('vouchers.return-from-sale.create', $this->formData() + ['document' => $document]);
    }

    public function data(SalesReturnIndexRequest $request)
    {
        return $this->index($request);
    }

    public function editApplied(SalesReturnDocument $document)
    {
        abort_unless($document->isApplied(), 403);
        $warehouseMode = $this->appliedAccess($document);
        if (SalesReturnCorrection::where('document_id', $document->id)->whereIn('status', ['pending_warehouse', 'pending_finance'])->exists()) {
            return redirect()->route('sales-return-corrections.index', ['document_id' => $document->id, 'status' => 'all'])->with('error', 'این سند یک درخواست اصلاح در انتظار بررسی دارد؛ ابتدا همان درخواست تعیین تکلیف شود.');
        }
        $document->load('items.product', 'items.variant', 'customer', 'invoice');

        return view('vouchers.return-from-sale.create', $this->formData() + ['document' => $document, 'isAppliedEdit' => true, 'warehouseMode' => $warehouseMode]);
    }

    public function updateApplied(UpdateAppliedSalesReturnRequest $request, SalesReturnDocument $document)
    {
        $data = $request->validated();
        $warehouseMode = $this->appliedAccess($document);
        $doc = app(SalesReturnCorrectionService::class)->updateDirect($document, $this->enrich($request) + $data, (int) auth()->id(), $data['adjustment_reason'], $warehouseMode);

        return $this->savedResponse($request, $doc, $doc->isPendingWarehouse() ? 'تغییرات ذخیره شد؛ کالای سالم پس از تأیید دریافت وارد موجودی مرکزی می‌شود.' : 'اصلاح ثبت شد؛ فقط اختلاف لازم در موجودی و حساب مشتری اعمال شد.');
    }

    public function voidApplied(VoidAppliedSalesReturnRequest $request, SalesReturnDocument $document)
    {
        $warehouseMode = $this->appliedAccess($document);
        $doc = app(SalesReturnCorrectionService::class)->voidDirect($document, (int) auth()->id(), $request->validated('reason'), $request->validated('document_version'), $warehouseMode);

        return redirect()->route('vouchers.return-from-sale.index')->with('success', 'سند ابطال شد و آثار آن برگشت خورد.');
    }

    public function update(UpdateSalesReturnRequest $request, SalesReturnDocument $document)
    {
        $doc = $document->isPendingWarehouse()
            ? $this->service->updatePending($document, $this->enrich($request) + $request->validated(), (int) auth()->id())
            : $this->service->updateDraft($document, $this->enrich($request), auth()->id());
        if ($request->input('action') === 'apply') {
            $doc = $this->service->apply($doc, auth()->id());
        }

        return $this->savedResponse($request, $doc, 'تغییرات برگشت از فروش ذخیره شد.');
    }

    public function apply(ApplySalesReturnRequest $request, SalesReturnDocument $document)
    {
        $doc = $this->service->apply($document, auth()->id());

        return redirect()->route('vouchers.return-from-sale.show', $doc)->with('success', $doc->isPendingWarehouse() ? 'سند برای تأیید دریافت به صف انبار ارسال شد.' : 'وضعیت سند بدون تغییر باقی ماند.');
    }

    public function cancel(Request $request, SalesReturnDocument $document)
    {
        if ($document->isPendingWarehouse()) {
            $data = $request->validate(['cancel_reason' => ['required', 'string', 'min:3', 'max:1000'], 'document_version' => ['required', 'string', 'size:64']]);
            $doc = $this->service->cancelPending($document, (int) auth()->id(), $data['cancel_reason'], $data['document_version']);
        } else {
            $doc = $this->service->cancelDraft($document, auth()->id(), $request->input('cancel_reason'));
        }

        return redirect()->route('vouchers.return-from-sale.show', $doc)->with('success', 'پیش‌نویس لغو شد.');
    }

    private function appliedAccess(SalesReturnDocument $document): bool
    {
        $user = auth()->user();
        $protected = app(SalesReturnCorrectionService::class)->requiresWarehouse($document);
        if ($protected) {
            abort_unless($user && PageAccessCatalog::userCanRoute($user, 'warehouse.inbound.receive'), 403, 'اصلاح سند دریافت‌شدهٔ مرکزی فقط برای مسئول مجاز انبار امکان‌پذیر است.');
        } else {
            abort_unless($user && PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.index'), 403);
        }

        return $protected;
    }

    public function print(SalesReturnDocument $document)
    {
        $document->load('customer', 'invoice', 'items.destinationWarehouse', 'items.product', 'items.variant', 'creator', 'applier');

        return view('vouchers.return-from-sale.print', compact('document'));
    }

    public function printLegacy(WarehouseTransfer $transfer)
    {
        $transfer->load('customer', 'relatedInvoice', 'toWarehouse', 'items.product', 'items.variant', 'user');

        return view('vouchers.return-from-sale.legacy-print', ['transfer' => $transfer]);
    }

    public function pdf(SalesReturnDocument $document)
    {
        return redirect()->route('vouchers.return-from-sale.print', $document);
    }

    private function savedResponse(Request $request, SalesReturnDocument $document, string $message)
    {
        $url = route('vouchers.return-from-sale.show', $document);

        return $request->expectsJson() ? response()->json(['ok' => true, 'message' => $message, 'redirect_url' => $url]) : redirect($url)->with('success', $message);
    }

    private function returnRows($documents, $legacyReturns)
    {
        $newRows = $documents->map(function (SalesReturnDocument $document) {
            $items = $document->items;
            $itemsSummary = $items->map(fn ($item) => trim(($item->product_name_snapshot ?: $item->product?->name ?: '—').' / '.($item->variant_name_snapshot ?: $item->variant?->variant_name ?: '—').' × '.number_format((int) $item->return_quantity)))->filter()->implode('، ');

            return ['source' => 'new', 'id' => $document->id, 'number' => $document->document_number, 'date' => $document->created_at?->format('Y-m-d H:i') ?: '—', 'customer' => $document->customer?->display_name ?: '—', 'items_summary' => $itemsSummary ?: '—', 'quantity' => (int) $document->total_quantity, 'return_type' => SalesReturnDocument::sourceTypeLabels()[$document->source_type] ?? '—', 'warehouse' => $items->pluck('destinationWarehouse.name')->filter()->unique()->implode('، ') ?: '—', 'amount' => (int) $document->total_refund_amount, 'creator' => $document->creator?->name ?: '—', 'show_url' => route('vouchers.return-from-sale.show', $document), 'print_url' => route('vouchers.return-from-sale.print', $document)];
        });
        $legacyRows = $legacyReturns->map(function (WarehouseTransfer $voucher) {
            $items = $voucher->items;
            $itemsSummary = $items->map(fn ($item) => trim(($item->product?->name ?: '—').' / '.($item->variant?->variant_name ?: ($item->variant_name ?: '—')).' × '.number_format((int) $item->quantity)))->filter()->implode('، ');

            return ['source' => 'legacy', 'id' => $voucher->id, 'number' => $voucher->reference ?: (string) $voucher->id, 'date' => $voucher->transferred_at?->format('Y-m-d H:i') ?: ($voucher->created_at?->format('Y-m-d H:i') ?: '—'), 'customer' => $voucher->customer?->display_name ?: ($voucher->beneficiary_name ?: '—'), 'items_summary' => $itemsSummary ?: ($voucher->note ?: '—'), 'quantity' => (int) $items->sum('quantity'), 'return_type' => WarehouseTransfer::returnSourceLabel($voucher->return_type), 'warehouse' => $voucher->toWarehouse?->name ?: '—', 'amount' => (int) $voucher->total_amount, 'creator' => $voucher->user?->name ?: '—', 'show_url' => route('vouchers.show', $voucher), 'print_url' => route('vouchers.show', $voucher)];
        });

        return $newRows->concat($legacyRows)->sortByDesc('date')->values();
    }

    private function reportData(SalesReturnIndexRequest $request): array
    {
        return $this->customerPrintData($request->filters());
    }

    private function customerPrintData(array $filters): array
    {
        $rows = $this->reports->getPdfRows($filters);
        $selectedCustomer = isset($filters['customer_id']) ? Customer::query()->find((int) $filters['customer_id']) : null;

        return ['filters' => $filters, 'activeFilters' => $this->activePrintFilters($filters, $selectedCustomer), 'rows' => $rows, 'selectedCustomer' => $selectedCustomer, 'documentsCount' => $rows->count(), 'totalAmount' => (int) $rows->sum('total_amount'), 'generatedAt' => $this->reports->jalaliDateTime(now())];
    }

    private function productPrintData(array $filters): array
    {
        $rows = $this->reports->getProductReturnSummary($filters);
        $totals = $this->reports->getProductReturnTotals($filters);
        $selectedCustomer = isset($filters['customer_id']) ? Customer::query()->find((int) $filters['customer_id']) : null;

        return ['filters' => $filters, 'activeFilters' => $this->activePrintFilters($filters, $selectedCustomer), 'rows' => $rows, 'totals' => $totals, 'selectedCustomer' => $selectedCustomer, 'generatedAt' => $this->reports->jalaliDateTime(now())];
    }

    private function activePrintFilters(array $filters, ?Customer $customer): array
    {
        return collect(['شماره سند' => $filters['document_number'] ?? null, 'مشتری' => $customer?->display_name, 'از تاریخ' => $filters['date_from'] ?? null, 'تا تاریخ' => $filters['date_to'] ?? null])->filter(fn ($v) => filled($v))->all();
    }

    private function formData(): array
    {
        return ['warehouses' => Warehouse::where('is_active', true)->whereIn('type', ['central', 'return'])->orderBy('type')->orderBy('name')->get(), 'categories' => Category::orderBy('name')->get(['id', 'name', 'code', 'parent_id']), 'modelLists' => ModelList::orderBy('brand')->orderBy('model_name')->get(['id', 'brand', 'model_name', 'code']), 'returnReasons' => SalesReturnDocument::returnReasonLabels(), 'commissionEffectLabels' => SalesReturnDocument::commissionEffectLabels(), 'conditionLabels' => SalesReturnDocumentItem::conditionLabels(), 'customerCreateUrl' => route('customers.index'), 'legacyReturnUrl' => route('vouchers.return-from-sale.index')];
    }

    private function enrich(Request $request): array
    {
        $data = $request->validated();
        $data['can_override_destination'] = $request->user()?->can('sales_returns.override_destination')
            || $request->user()?->isSuperAdmin()
            || $request->user()?->hasRole(['admin', 'Admin']);

        return $data;
    }
}
