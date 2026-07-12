<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateSalesReturnRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesReturnDocument;
use App\Models\SalesReturnDocumentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReturnQueryService;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReturnController extends Controller
{
    public function __construct(
        private SalesReturnService $service,
        private SalesReturnQueryService $queryService,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->queryService->filtersFromRequest($request);
        $filters['status'] = $filters['status'] ?: $this->queryService->defaultStatus();
        $documents = $this->queryService->buildQuery($filters)->paginate(30)->withQueryString();
        $statusCounts = $this->queryService->statusCounts($filters);

        return view('sales-returns.index', $this->indexData($filters) + compact('documents', 'filters', 'statusCounts'));
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
        $document->load(['items.product', 'items.variant', 'items.destinationWarehouse', 'customer', 'invoice', 'creator', 'applier']);

        return view('sales-returns.show', compact('document'));
    }

    public function edit(SalesReturnDocument $document)
    {
        if (!$document->isDraft()) {
            throw ValidationException::withMessages(['status' => 'فقط سند پیش‌نویس قابل ویرایش است.']);
        }
        $document->load(['items.destinationWarehouse', 'customer', 'invoice']);

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

    public function print(SalesReturnDocument $document)
    {
        $document->load(['items.product', 'items.variant', 'items.destinationWarehouse', 'customer', 'invoice', 'creator', 'applier']);

        return view('sales-returns.print', compact('document'));
    }

    public function pdf(SalesReturnDocument $document)
    {
        if (!$this->hasPdfRenderer()) {
            return redirect()->route('sales-returns.print', $document)
                ->with('warning', 'Package تولید PDF در پروژه نصب نیست؛ از صفحه چاپ برای ذخیره PDF استفاده کنید.');
        }

        return redirect()->route('sales-returns.print', $document);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->hasPermission('sales_returns.export'), 403);
        $filters = $this->queryService->filtersFromRequest($request);
        $filters['status'] = $filters['status'] ?: $this->queryService->defaultStatus();
        $documents = $this->queryService->buildQuery($filters)->with(['items.destinationWarehouse', 'items.product', 'items.variant'])->get();
        $filename = 'sales-returns-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($documents) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['شماره سند', 'تاریخ', 'نوع برگشت', 'مشتری', 'فاکتور مرجع', 'کالا', 'تنوع', 'تعداد', 'وضعیت کالا', 'انبار مقصد', 'مبلغ واحد', 'مبلغ کل', 'ثبت‌کننده', 'وضعیت سند']);
            foreach ($documents as $document) {
                foreach ($document->items as $item) {
                    fputcsv($out, [
                        $document->document_number,
                        optional($document->created_at)->format('Y-m-d H:i'),
                        $document->sourceTypeLabel(),
                        $this->customerName($document->customer),
                        $this->referenceNumber($document),
                        $item->product_name_snapshot ?: $item->product?->name,
                        $item->variant_name_snapshot ?: $item->variant?->variant_name ?: $item->sku_snapshot,
                        (int) $item->return_quantity,
                        $item->isDamaged() ? 'معیوب' : 'سالم',
                        $item->destinationWarehouse?->name,
                        (int) $item->refund_unit_price,
                        (int) $item->refund_amount,
                        $document->creator?->name,
                        $document->statusLabel(),
                    ]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        abort_unless($request->user()?->hasPermission('sales_returns.export'), 403);
        $filters = $this->queryService->filtersFromRequest($request);
        $filters['status'] = $filters['status'] ?: $this->queryService->defaultStatus();
        $documents = $this->queryService->buildQuery($filters)->with(['items.destinationWarehouse'])->limit(500)->get();

        return view('sales-returns.report-print', compact('documents', 'filters'));
    }

    private function formData(): array
    {
        $centralWarehouse = Warehouse::query()->where('type', 'central')->where('is_active', true)->orderBy('id')->first();
        $returnWarehouse = Warehouse::query()->where('type', 'return')->where('is_active', true)->orderBy('id')->first();
        $warehouses = collect([$centralWarehouse, $returnWarehouse])->filter()->values();
        $categories = class_exists(Category::class) ? Category::query()->orderBy('name')->limit(300)->get(['id', 'name', 'parent_id']) : collect();
        $modelLists = class_exists(ModelList::class) ? ModelList::query()->orderBy('model_name')->limit(300)->get(['id', 'model_name', 'brand']) : collect();
        $returnReasons = $this->returnReasons();

        return compact('centralWarehouse', 'returnWarehouse', 'warehouses', 'categories', 'modelLists', 'returnReasons') + [
            'sourceTypeLabels' => SalesReturnDocument::sourceTypeLabels(),
            'statusLabels' => SalesReturnDocument::statusLabels(),
        ];
    }

    private function indexData(array $filters): array
    {
        return [
            'sourceTypeLabels' => SalesReturnDocument::sourceTypeLabels(),
            'statusLabels' => SalesReturnDocument::statusLabels(),
            'returnReasons' => $this->returnReasons(),
            'warehouses' => Warehouse::query()->whereIn('type', ['central', 'return'])->orderBy('type')->orderBy('name')->get(['id', 'name', 'type']),
            'customers' => $this->selectedCustomers($filters),
            'products' => $this->selectedProducts($filters),
            'variants' => $this->selectedVariants($filters),
            'creators' => User::query()->whereIn('id', array_filter([(int) ($filters['created_by'] ?? 0)]))->get(['id', 'name']),
            'hasPdfRenderer' => $this->hasPdfRenderer(),
        ];
    }

    private function selectedCustomers(array $filters)
    {
        return Customer::query()->whereIn('id', array_filter([(int) ($filters['customer_id'] ?? 0)]))->get(['id', 'first_name', 'last_name', 'mobile']);
    }

    private function selectedProducts(array $filters)
    {
        return Product::query()->whereIn('id', array_filter([(int) ($filters['product_id'] ?? 0)]))->get(['id', 'name', 'sku', 'barcode']);
    }

    private function selectedVariants(array $filters)
    {
        return ProductVariant::query()->whereIn('id', array_filter([(int) ($filters['product_variant_id'] ?? 0)]))->get(['id', 'variant_name', 'variant_code']);
    }

    private function returnReasons(): array
    {
        return [
            'damaged' => 'خرابی کالا',
            'mismatch' => 'مغایرت کالا',
            'wrong_shipping' => 'اشتباه در ارسال',
            'customer_cancelled' => 'انصراف مشتری',
            'appearance_issue' => 'ایراد ظاهری',
            'technical_issue' => 'ایراد فنی',
            'wrong_registration' => 'ثبت اشتباه',
            'other' => 'سایر',
        ];
    }

    private function referenceNumber(SalesReturnDocument $document): string
    {
        return $document->isInternal() ? (string) ($document->invoice?->uuid ?: '—') : (string) ($document->external_invoice_number ?: '—');
    }

    private function customerName(?Customer $customer): string
    {
        if (!$customer) {
            return '—';
        }

        return trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: ($customer->mobile ?: ('#' . $customer->id));
    }

    private function hasPdfRenderer(): bool
    {
        return class_exists(\Barryvdh\DomPDF\Facade\Pdf::class) || class_exists(\Barryvdh\DomPDF\PDF::class);
    }
}
