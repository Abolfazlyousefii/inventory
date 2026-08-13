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

class SellerCommissionDocumentController extends Controller
{
    public function __construct(private readonly SellerCommissionDocumentService $service) {}

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
        return view('finance.seller-commission-documents.form', [
            'document' => null,
            'users' => $this->users(),
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
