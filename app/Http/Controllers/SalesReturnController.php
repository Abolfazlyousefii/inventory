<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateSalesReturnRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ModelList;
use App\Models\SalesReturnDocument;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesReturnController extends Controller
{
    public function __construct(private SalesReturnService $service) {}

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->query('status', SalesReturnDocument::STATUS_DRAFT),
            'source_type' => $request->query('source_type'),
            'customer_id' => $request->integer('customer_id') ?: null,
            'document_number' => trim((string) $request->query('document_number', '')),
            'external_invoice_number' => trim((string) $request->query('external_invoice_number', '')),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $documents = SalesReturnDocument::query()
            ->with(['customer:id,first_name,last_name,mobile', 'invoice:id,uuid,total,status'])
            ->when($filters['status'], fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['source_type'], fn ($q) => $q->where('source_type', $filters['source_type']))
            ->when($filters['customer_id'], fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when($filters['document_number'] !== '', fn ($q) => $q->where('document_number', 'like', '%' . $filters['document_number'] . '%'))
            ->when($filters['external_invoice_number'] !== '', fn ($q) => $q->where('external_invoice_number', 'like', '%' . $filters['external_invoice_number'] . '%'))
            ->when($filters['date_from'], fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'], fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('sales-returns.index', compact('documents', 'filters'));
    }

    public function create()
    {
        return view('sales-returns.create', $this->formData());
    }

    public function store(StoreSalesReturnRequest $request)
    {
        $document = $this->service->createDraft($request->validated(), $request->user());

        return redirect()->route('sales-returns.edit', $document)->with('success', 'پیش‌نویس برگشت از فروش ذخیره شد.');
    }

    public function show(SalesReturnDocument $document)
    {
        $document->load(['items.product', 'items.variant', 'items.destinationWarehouse', 'customer', 'invoice']);

        return view('sales-returns.show', compact('document'));
    }

    public function edit(SalesReturnDocument $document)
    {
        if (!$document->isDraft()) {
            throw ValidationException::withMessages(['status' => 'فقط سند پیش‌نویس قابل ویرایش است.']);
        }
        $document->load(['items', 'customer', 'invoice']);

        return view('sales-returns.edit', $this->formData() + compact('document'));
    }

    public function update(UpdateSalesReturnRequest $request, SalesReturnDocument $document)
    {
        $updated = $this->service->updateDraft($document, $request->validated(), $request->user());

        return redirect()->route('sales-returns.edit', $updated)->with('success', 'پیش‌نویس برگشت از فروش بروزرسانی شد.');
    }

    public function cancel(Request $request, SalesReturnDocument $document)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.cancel_draft'), 403);
        $data = $request->validate(['cancel_reason' => ['nullable', 'string', 'max:1000']]);
        $this->service->cancelDraft($document, $request->user(), $data['cancel_reason'] ?? null);

        return redirect()->route('sales-returns.index')->with('success', 'پیش‌نویس برگشت از فروش لغو شد.');
    }

    private function formData(): array
    {
        $centralWarehouse = Warehouse::query()->where('type', 'central')->where('is_active', true)->orderBy('id')->first();
        $returnWarehouse = Warehouse::query()->where('type', 'return')->where('is_active', true)->orderBy('id')->first();
        $warehouses = collect([$centralWarehouse, $returnWarehouse])->filter()->values();
        $categories = class_exists(Category::class) ? Category::query()->orderBy('name')->limit(300)->get(['id', 'name', 'parent_id']) : collect();
        $modelLists = class_exists(ModelList::class) ? ModelList::query()->orderBy('model_name')->limit(300)->get(['id', 'model_name', 'brand']) : collect();

        return compact('centralWarehouse', 'returnWarehouse', 'warehouses', 'categories', 'modelLists') + [
            'sourceTypeLabels' => SalesReturnDocument::sourceTypeLabels(),
            'statusLabels' => SalesReturnDocument::statusLabels(),
        ];
    }
}
