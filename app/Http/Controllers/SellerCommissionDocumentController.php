<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailableSellerCommissionInvoicesRequest;
use App\Http\Requests\StoreSellerCommissionDocumentRequest;
use App\Http\Requests\UpdateSellerCommissionDocumentRequest;
use App\Models\SellerSalesDocument;
use App\Models\User;
use App\Services\Finance\SellerCommissionDocumentService;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\CommissionInvoiceFilterRequest;
use App\Http\Requests\CommissionPreviewRequest;
use App\Services\Commissions\CommissionReportService;

class SellerCommissionDocumentController extends Controller
{
    public function __construct(private readonly SellerCommissionDocumentService $service, private readonly CommissionReportService $reportService) {}

    public function index(Request $request): View
    {
        $filters = [
            'document_number' => $request->string('document_number')->toString(),
            'user_id' => $request->integer('user_id') ?: null,
            'date_from' => $this->normalizeDate($request->query('date_from')),
            'date_to' => $this->normalizeDate($request->query('date_to')),
        ];

        return view('finance.seller-commission-documents.index', [
            'documents' => $this->service->paginateDocuments($filters),
            'users' => $this->users(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('finance.seller-commission-documents.report-form');
    }

    public function reportInvoices(CommissionInvoiceFilterRequest $request): JsonResponse
    {
        $p = $this->reportService->availableInvoices($request->validated('date_from'), $request->validated('date_to'), $request->validated('search'));
        return response()->json(['data' => collect($p->items())->map(fn ($i) => $this->invoicePayload($i)), 'current_page'=>$p->currentPage(), 'last_page'=>$p->lastPage(), 'total'=>$p->total()]);
    }

    public function manualInvoice(CommissionInvoiceFilterRequest $request): JsonResponse
    {
        $invoice = $this->reportService->findInvoice($request->validated('invoice_number'));
        if (! $invoice) {
            return response()->json(null, 404);
        }
        $payload = $this->invoicePayload($invoice);
        $from = $this->normalizeDate($request->validated('date_from'));
        $to = $this->normalizeDate($request->validated('date_to'));
        $payload['outside_range'] = $from && $to
            ? $payload['date_iso'] < $from || $payload['date_iso'] > $to
            : false;

        return response()->json($payload);
    }

    public function preview(CommissionPreviewRequest $request): View
    {
        $preview = $this->reportService->preview($request->validated('invoice_ids'));
        $names = User::whereIn('id', $preview['rows']->map(fn ($r) => $r['calculation']->ledgerAttributes['seller_id'])->unique())->pluck('name', 'id');
        $summary = $preview['rows']->groupBy(fn ($row) => $row['calculation']->ledgerAttributes['seller_id'])
            ->map(fn ($rows, $sellerId) => [
                'seller' => $names[$sellerId] ?? '—',
                'invoice_count' => $rows->pluck('invoice.id')->unique()->count(),
                'sales' => $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['net_amount_snapshot']),
                'base_commission' => $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['base_commission_amount']),
                'campaign_commission' => $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['campaign_commission_amount']),
                'final_commission' => $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['total_commission_amount']),
            ]);
        $invoiceRows = $preview['rows']->groupBy(fn ($row) => $row['invoice']->id)->map(function ($rows) use ($names): array {
            $invoice = $rows->first()['invoice'];
            $sales = $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['net_amount_snapshot']);
            $commission = $rows->sum(fn ($row) => (int) $row['calculation']->ledgerAttributes['total_commission_amount']);
            $sellerId = $rows->first()['calculation']->ledgerAttributes['seller_id'];
            return ['number' => $invoice->uuid, 'customer' => $invoice->customer_name ?: $invoice->customer?->display_name ?: '—', 'seller' => $names[$sellerId] ?? '—', 'sales' => $sales, 'rate' => $sales > 0 ? $commission * 100 / $sales : 0, 'commission' => $commission];
        })->values();
        if ($summary->count() !== 1) {
            throw ValidationException::withMessages(['invoice_ids' => 'برای هر گزارش فقط فاکتورهای یک فروشنده را انتخاب کنید.']);
        }
        $sellerId = (int) $preview['rows']->first()['calculation']->ledgerAttributes['seller_id'];
        $dateFrom = $request->validated('date_from');
        $dateTo = $request->validated('date_to');
        $invoiceIds = array_map('intval', $request->validated('invoice_ids'));
        $manualInvoiceIds = array_map('intval', $request->validated('manual_invoice_ids', []));

        return view('finance.seller-commission-documents.preview', compact('summary', 'invoiceRows', 'sellerId', 'dateFrom', 'dateTo', 'invoiceIds', 'manualInvoiceIds'));
    }

    private function invoicePayload($invoice): array
    {
        $paid = (int) ($invoice->paid_amount ?? 0);
        $total = (int) $invoice->total;
        return ['id'=>(int)$invoice->id, 'number'=>(string)$invoice->uuid, 'date_iso'=>$invoice->display_document_date?->format('Y-m-d'), 'date'=>JalaliDate::date($invoice->display_document_date), 'customer'=>$invoice->customer_name ?: $invoice->customer?->display_name ?: '—', 'seller'=>$invoice->seller?->name ?: $invoice->preinvoiceOrder?->seller?->name ?: $invoice->preinvoiceOrder?->creator?->name ?: '—', 'total'=>$total, 'payment_status'=>$paid >= $total ? 'پرداخت‌شده' : ($paid > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده')];
    }

    public function availableInvoices(AvailableSellerCommissionInvoicesRequest $request): JsonResponse
    {
        $paginator = $this->service->paginateAvailable(
            (int) $request->validated('user_id'),
            (string) $request->validated('date_from'),
            (string) $request->validated('date_to'),
            $request->integer('document_id') ?: null,
            $request->validated('search'),
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($invoice) => [
                'id' => (int) $invoice->id,
                'number' => (string) $invoice->uuid,
                'date' => $this->service->resolveInvoiceInitialDate($invoice)?->format('Y-m-d'),
                'date_display' => JalaliDate::date($this->service->resolveInvoiceInitialDate($invoice)),
                'customer' => $invoice->customer_name ?: $invoice->customer?->display_name ?: '—',
                'total' => $this->service->resolveInvoiceFinalAmount($invoice),
            ])->values(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function store(StoreSellerCommissionDocumentRequest $request): RedirectResponse
    {
        $document = $this->service->createDocument($request->validated(), $request->user());

        return redirect()->route('finance.seller-sales.show', $document)->with('success', 'سند با موفقیت ثبت شد.');
    }

    public function show(SellerSalesDocument $document): View
    {
        $document->load(['seller:id,name', 'creator:id,name', 'updater:id,name', 'items.reassignedToSeller:id,name']);

        return view('finance.seller-commission-documents.show', compact('document'));
    }

    public function edit(SellerSalesDocument $document): View
    {
        $document->load(['items.reassignedToSeller:id,name']);

        return view('finance.seller-commission-documents.form', [
            'document' => $document,
            'users' => $this->users(),
        ]);
    }

    public function update(UpdateSellerCommissionDocumentRequest $request, SellerSalesDocument $document): RedirectResponse
    {
        $document = $this->service->updateDocument($document, $request->validated(), $request->user());

        return redirect()->route('finance.seller-sales.show', $document)->with('success', 'سند با موفقیت به‌روزرسانی شد.');
    }

    public function destroy(SellerSalesDocument $document): RedirectResponse
    {
        $this->service->deleteDraft($document);

        return redirect()->route('finance.seller-sales.index')->with('success', 'گزارش پیش‌نویس حذف شد و فاکتورها آزاد شدند.');
    }

    public function print(SellerSalesDocument $document): View
    {
        $document->load(['seller:id,name', 'creator:id,name', 'items.reassignedToSeller:id,name']);

        return view('finance.seller-commission-documents.print', compact('document'));
    }

    private function users()
    {
        return User::query()->activeErpUsers()->orderBy('name')->get(['id', 'name']);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $value
            : JalaliDate::toGregorianDate($value);
    }
}
