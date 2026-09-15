<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailableSellerCommissionInvoicesRequest;
use App\Http\Requests\StoreSellerCommissionDocumentRequest;
use App\Http\Requests\UpdateSellerCommissionDocumentRequest;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentAdjustment;
use App\Models\User;
use App\Services\Finance\SellerCommissionDocumentService;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\SellerCommissionSalesPreviewRequest;
use App\Services\Finance\SellerCommissionSalesPreviewService;

class SellerCommissionDocumentController extends Controller
{
    public function __construct(private readonly SellerCommissionDocumentService $service, private readonly SellerCommissionSalesPreviewService $salesPreviewService) {}

    public function index(SellerCommissionSalesPreviewRequest $request): View
    {
        $filters = [
            'seller_id' => $request->integer('seller_id') ?: null,
            'date_from' => $this->normalizeDate($request->query('date_from')),
            'date_to' => $this->normalizeDate($request->query('date_to')),
            'invoice_number' => $request->string('invoice_number')->toString(), 'customer' => $request->string('customer')->toString(),
        ];
        $complete = $filters['seller_id'] && $filters['date_from'] && $filters['date_to'];
        $rangeInvalid = $filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to'];

        $documentFilters = [
            'user_id' => $request->integer('user_id') ?: null,
            'document_number' => $request->string('document_number')->toString(),
            'date_from' => $rangeInvalid ? null : $filters['date_from'],
            'date_to' => $rangeInvalid ? null : $filters['date_to'],
        ];

        return view('finance.seller-commission-documents.index', [
            'users' => $this->users(),
            'filters' => $filters,
            'documentFilters' => $documentFilters,
            'documents' => $this->service->paginateDocuments($documentFilters, 'documents_page'),
            'rangeError' => $rangeInvalid ? 'بازه تاریخ نامعتبر است؛ «از تاریخ» نمی‌تواند بعد از «تا تاریخ» باشد.' : null,
            'canIssueDocument' => $complete && ! $rangeInvalid,
            'activeTab' => $request->string('tab')->toString() === 'documents' ? 'documents' : 'report',
            'report' => ($complete && ! $rangeInvalid) ? $this->salesPreviewService->report($filters) : null,
            'selectedSeller' => $filters['seller_id'] ? User::find($filters['seller_id']) : null,
        ]);
    }

    public function create(Request $request): View
    {
        return view('finance.seller-commission-documents.form', [
            'document' => null,
            'users' => $this->users(),
            'prefill' => [
                'seller_id' => $request->integer('seller_id') ?: null,
                'date_from' => $this->normalizeDate($request->query('date_from')) ?: '',
                'date_to' => $this->normalizeDate($request->query('date_to')) ?: '',
            ],
        ]);
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
        $document->load([
            'seller:id,name',
            'creator:id,name',
            'updater:id,name',
            'confirmer:id,name',
            'finalizer:id,name',
            'items' => fn ($q) => $q->where('status', \App\Models\SellerSalesDocumentItem::STATUS_ACTIVE)->orderBy('id'),
            'items.reassignedToSeller:id,name',
            'adjustments.invoice:id,uuid',
            'adjustments.creator:id,name',
        ]);

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
        $document->load([
            'seller:id,name',
            'creator:id,name',
            'confirmer:id,name',
            'finalizer:id,name',
            'items' => fn ($q) => $q->where('status', \App\Models\SellerSalesDocumentItem::STATUS_ACTIVE)->orderBy('id'),
            'adjustments.invoice:id,uuid',
            'adjustments.creator:id,name',
        ]);

        return view('finance.seller-commission-documents.print', compact('document'));
    }

    public function storeAdjustment(Request $request, SellerSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->service->addAdjustment($document, $data['invoice_id'], $data['amount'], $data['reason'], $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تنظیم دستی ثبت شد.');
    }

    public function destroyAdjustment(SellerSalesDocument $document, SellerSalesDocumentAdjustment $adjustment): RedirectResponse
    {
        try {
            $this->service->removeAdjustment($document, $adjustment, request()->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'تنظیم دستی حذف شد.');
    }

    public function setBonus(Request $request, SellerSalesDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'bonus_amount' => ['nullable', 'integer'],
            'bonus_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->service->setBonus($document, $data['bonus_amount'] ?? 0, $data['bonus_reason'] ?? null, $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'بونوس ثبت شد.');
    }

    public function confirm(Request $request, SellerSalesDocument $document): RedirectResponse
    {
        try {
            $this->service->confirmDocument($document, $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'سند تأیید شد.');
    }

    public function finalize(Request $request, SellerSalesDocument $document): RedirectResponse
    {
        try {
            $this->service->finalizeDocument($document, $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'سند نهایی شد.');
    }

    public function recalculate(Request $request, SellerSalesDocument $document): RedirectResponse
    {
        try {
            $this->service->recalculateItems($document, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'نرخ‌های سند با موفقیت بازمحاسبه شد.');
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
