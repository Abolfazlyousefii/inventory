<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceLiveFilterRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryWebhookService;
use App\Support\PermissionCatalog;
use App\Support\PageAccessCatalog;
use App\Support\Currency;
use App\Support\SalesDocumentTotals;
use App\Services\SalesHavalehStatusService;
use App\Services\SalesHavalehService;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesPrintDocumentService;
use App\Services\WarehousePendingRefreshService;
use App\Services\WarehouseCollectionService;
use App\Services\WarehouseStockService;
use App\Services\CustomerLedgerService;
use App\Services\NotificationService;
use App\Services\SalesDocumentSellerReassignmentService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly SalesHavalehStatusService $statusService,
        private readonly SalesHavalehService $salesHavalehService,
        private readonly SalesDocumentAccessService $accessService,
        private readonly WarehousePendingRefreshService $warehousePendingRefreshService,
        private readonly WarehouseCollectionService $warehouseCollectionService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly NotificationService $notificationService,
        private readonly SalesDocumentSellerReassignmentService $sellerReassignmentService,
    ) {}

    public function index(Request $request)
    {
        $customer = Customer::query()
            ->select(['id', 'crm_customer_id', 'first_name', 'last_name', 'mobile'])
            ->find($request->integer('customer_id'));

        return view('invoices.index', [
            'initialCustomer' => $customer ? $this->customerSearchPayload($customer) : null,
            'initialFilters' => [
                'order_code' => InvoiceLiveFilterRequest::normalizeDigits(trim((string) $request->query('order_code', ''))),
                'customer_id' => $customer?->id,
                'date_from' => trim((string) $request->query('date_from', '')),
                'date_to' => trim((string) $request->query('date_to', '')),
                'quick_range' => trim((string) $request->query('quick_range', '')),
            ],
            'canViewCancelled' => PermissionCatalog::userHasPermission($request->user(), 'invoices.cancel'),
            'canReassignSeller' => $this->canReassignSeller($request->user()),
            'sellers' => $this->canReassignSeller($request->user()) ? User::activeSellers()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);

        /* Legacy report implementation retained below temporarily for reference. */
        $allowedPaymentStatuses = ['paid', 'partial', 'unpaid', 'overpaid'];
        $newWorkflowStatuses = $this->invoiceNewWorkflowStatuses();
        $legacyStatuses = $this->invoiceLegacyStatuses();
        $allowedStatuses = array_values(array_unique(array_merge($newWorkflowStatuses, $legacyStatuses, $this->statusService->all())));

        $filters = [
            'date_from' => trim((string) $request->query('date_from', $request->query('date', ''))),
            'date_to' => trim((string) $request->query('date_to', '')),
            'quick_range' => trim((string) $request->query('quick_range', '')),
            'invoice_number' => trim((string) $request->query('invoice_number', $request->query('q', ''))),
            'customer_code' => trim((string) $request->query('customer_code', '')),
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'customer_mobile' => trim((string) $request->query('customer_mobile', '')),
            'payment_status' => trim((string) $request->query('payment_status', '')),
            'status' => trim((string) $request->query('status', '')),
            'seller' => trim((string) $request->query('seller', '')),
            'only_remaining' => $request->boolean('only_remaining') ? '1' : '',
            'only_paid' => $request->boolean('only_paid') ? '1' : '',
            'has_cheque' => $request->boolean('has_cheque') ? '1' : '',
            'has_warnings' => $request->boolean('has_warnings') ? '1' : '',
            'overpaid_only' => $request->boolean('overpaid_only') ? '1' : '',
            'legacy_only' => $request->boolean('legacy_only') ? '1' : '',
            'shipping_method' => trim((string) $request->query('shipping_method', '')),
            'min_amount' => $this->normalizeDigits(trim((string) $request->query('min_amount', ''))),
            'max_amount' => $this->normalizeDigits(trim((string) $request->query('max_amount', ''))),
        ];

        if ($filters['quick_range'] !== '') {
            [$quickFrom, $quickTo] = $this->quickJalaliRange($filters['quick_range']);
            if ($quickFrom && $quickTo) {
                $filters['date_from'] = Jalalian::fromCarbon($quickFrom)->format('Y/m/d');
                $filters['date_to'] = Jalalian::fromCarbon($quickTo)->format('Y/m/d');
            }
        }

        $filterErrors = [];
        $dateFrom = $this->parseInvoiceFilterDate($filters['date_from']);
        $dateTo = $this->parseInvoiceFilterDate($filters['date_to']);
        if ($filters['date_from'] !== '' && !$dateFrom) {
            $filterErrors[] = 'تاریخ شروع معتبر نیست.';
        }
        if ($filters['date_to'] !== '' && !$dateTo) {
            $filterErrors[] = 'تاریخ پایان معتبر نیست.';
        }
        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            $filterErrors[] = 'تاریخ شروع نباید بعد از تاریخ پایان باشد.';
        }
        if ($filters['payment_status'] !== '' && !in_array($filters['payment_status'], $allowedPaymentStatuses, true)) {
            $filterErrors[] = 'وضعیت پرداخت انتخاب‌شده معتبر نیست.';
            $filters['payment_status'] = '';
        }
        if ($filters['status'] !== '' && !in_array($filters['status'], $allowedStatuses, true)) {
            $filterErrors[] = 'وضعیت عملیاتی انتخاب‌شده معتبر نیست.';
            $filters['status'] = '';
        }
        foreach (['min_amount' => 'حداقل مبلغ', 'max_amount' => 'حداکثر مبلغ'] as $key => $label) {
            if ($filters[$key] !== '' && !ctype_digit($filters[$key])) {
                $filterErrors[] = $label . ' باید عددی باشد.';
                $filters[$key] = '';
            }
        }

        $baseQuery = $this->invoiceReportQuery($filters, $dateFrom, $dateTo)->active();

        if ($request->input('export') === 'csv' || $request->input('export') === 'excel' || $request->input('export') === 'daily_csv') {
            abort_unless($this->canHandleFinanceActions(), 403);
            return $this->exportInvoiceAccountingCsv((clone $baseQuery)->with(['customer:id,crm_customer_id,first_name,last_name,mobile', 'preinvoiceOrder:id,uuid,created_by', 'preinvoiceOrder.creator:id,name'])->orderByDesc('id')->get(), $filters, $request->input('export') === 'excel');
        }

        $summary = $this->invoiceReportSummary(clone $baseQuery);

        $invoices = (clone $baseQuery)
            ->with(['payments.cheque', 'customer:id,crm_customer_id,first_name,last_name,mobile', 'preinvoiceOrder:id,uuid,created_by', 'preinvoiceOrder.creator:id,name'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $pageRows = $invoices->getCollection();
        $pageTotals = [
            'count' => $pageRows->count(),
            'total' => (int) $pageRows->sum('total'),
            'paid' => (int) $pageRows->sum(fn ($invoice) => (int) ($invoice->paid_total ?? 0)),
            'remaining' => (int) $pageRows->sum(fn ($invoice) => max((int) $invoice->total - (int) ($invoice->paid_total ?? 0), 0)),
        ];

        $statusLabels = $this->statusService->labels();
        $q = $filters['invoice_number'];
        $dateInput = $filters['date_from'];
        $reportDateInput = $filters['date_from'];
        $canRegisterPayments = $this->canHandleFinanceActions();

        $canCancelInvoices = $this->canCancelInvoices();

        return view('invoices.index', compact('invoices', 'q', 'statusLabels', 'dateInput', 'filters', 'reportDateInput', 'canRegisterPayments', 'canCancelInvoices', 'summary', 'pageTotals', 'filterErrors', 'allowedStatuses', 'newWorkflowStatuses', 'legacyStatuses'));
    }

    public function data(InvoiceLiveFilterRequest $request)
    {
        $filters = $request->validated();
        [$dateFrom, $dateTo] = $this->liveInvoiceDateRange($request);
        $query = $this->liveInvoiceQuery($filters, $dateFrom, $dateTo);
        $summary = $request->boolean('include_summary') ? $this->liveInvoiceSummary(clone $query) : null;

        $query->with([
            'customer:id,crm_customer_id,first_name,last_name,mobile',
            'preinvoiceOrder:id,uuid,created_by,seller_id',
            'preinvoiceOrder.seller:id,name',
            'preinvoiceOrder.creator:id,name',
            'seller:id,name',
        ])->withSum('payments as paid_total', 'amount');

        $orderCode = (string) ($filters['order_code'] ?? '');
        if ($orderCode !== '') {
            $query->select('invoices.*')->selectRaw(
                'case when invoices.uuid = ? then 0 when exists (select 1 from preinvoice_orders where preinvoice_orders.id = invoices.preinvoice_order_id and preinvoice_orders.uuid = ?) then 1 when invoices.uuid like ? then 2 when exists (select 1 from preinvoice_orders where preinvoice_orders.id = invoices.preinvoice_order_id and preinvoice_orders.uuid like ?) then 3 else 4 end as code_match_priority',
                [$orderCode, $orderCode, $orderCode.'%', $orderCode.'%']
            )->orderBy('code_match_priority');
        }

        $paginator = $query->orderByDesc('invoices.created_at')->orderByDesc('invoices.id')
            ->cursorPaginate((int) ($filters['limit'] ?? 40));
        $permissions = $this->invoiceListPermissions($request);
        foreach ($paginator->items() as $invoice) {
            $invoice->setAttribute('live_meta', $this->invoiceLiveMeta($invoice, $permissions));
        }
        $viewData = ['invoices' => collect($paginator->items()), 'canReassignSeller' => $this->canReassignSeller($request->user())];

        $effectiveDateFrom = $dateFrom ? Jalalian::fromCarbon($dateFrom)->format('Y/m/d') : ($filters['date_from'] ?? null);
        $effectiveDateTo = $dateTo ? Jalalian::fromCarbon($dateTo)->format('Y/m/d') : ($filters['date_to'] ?? null);

        return response()->json([
            'desktop_html' => view('invoices.partials.table-rows', $viewData)->render(),
            'mobile_html' => view('invoices.partials.mobile-cards', $viewData)->render(),
            'summary_html' => $summary === null ? null : view('invoices.partials.summary', compact('summary'))->render(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
            'filters' => array_filter([
                'order_code' => $orderCode,
                'customer_id' => $filters['customer_id'] ?? null,
                'date_from' => $effectiveDateFrom,
                'date_to' => $effectiveDateTo,
                'quick_range' => $filters['quick_range'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public function customersSearch(Request $request)
    {
        $data = Validator::make(['q' => trim((string) $request->query('q', ''))], [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])->validate();
        $term = $data['q'];
        $digits = InvoiceLiveFilterRequest::normalizeDigits($term);
        $nameParts = array_values(array_filter(preg_split('/\s+/u', $term) ?: []));
        $customers = Customer::query()->select(['id', 'crm_customer_id', 'first_name', 'last_name', 'mobile'])
            ->where(function ($query) use ($term, $digits, $nameParts) {
                $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('crm_customer_id', 'like', "%{$digits}%")
                    ->orWhere('mobile', 'like', "%{$digits}%");
                if (count($nameParts) > 1) {
                    $query->orWhere(function ($fullNameQuery) use ($nameParts) {
                        foreach ($nameParts as $part) {
                            $fullNameQuery->where(fn ($partQuery) => $partQuery
                                ->where('first_name', 'like', "%{$part}%")
                                ->orWhere('last_name', 'like', "%{$part}%"));
                        }
                    });
                }
                if (ctype_digit($digits)) {
                    $query->orWhere('id', (int) $digits);
                }
            })->orderBy('last_name')->orderBy('first_name')->orderBy('id')->limit(20)->get()
            ->map(fn (Customer $customer) => $this->customerSearchPayload($customer));

        return response()->json(['items' => $customers]);
    }

    public function salesVouchers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $allowedStatuses = $this->statusService->all();

        $invoices = Invoice::query()
            ->with(['items.product', 'items.variant', 'preinvoiceOrder:id,created_by'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('uuid', 'like', "%{$q}%")
                        ->orWhere('customer_name', 'like', "%{$q}%")
                        ->orWhere('customer_mobile', 'like', "%{$q}%");
                });
            })
            ->when(in_array($status, $allowedStatuses, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->whereIn('status', $allowedStatuses)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $statusLabels = $this->statusService->labels();

        return view('vouchers.sales.index', compact('invoices', 'q', 'status', 'statusLabels', 'allowedStatuses'));
    }


    public function salesQueue(Request $request)
    {
        $invoices = $this->salesQueueQuery(false)
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
            ->paginate(20)
            ->withQueryString();

        return view('vouchers.sales.queue', [
            'invoices' => $invoices,
            'statusLabels' => $this->statusService->labels(),
            'queueStatuses' => $this->queueStatuses(),
            'title' => 'صف جمع‌آوری فاکتورها',
            'subtitle' => 'فاکتورهای تاییدشده مالی که باید توسط انبار جمع‌آوری شوند.',
            'isShippedPage' => false,
        ]);
    }

    public function salesShipped(Request $request)
    {
        $invoices = $this->salesQueueQuery(true)
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('vouchers.sales.queue', [
            'invoices' => $invoices,
            'statusLabels' => $this->statusService->labels(),
            'queueStatuses' => [SalesHavalehStatusService::SHIPPED],
            'title' => 'حواله‌های ارسال‌شده',
            'subtitle' => 'فقط حواله‌های ارسال‌شده نمایش داده می‌شود.',
            'isShippedPage' => true,
        ]);
    }

    public function salesQueueData(Request $request)
    {
        $perPage = max(1, min($request->integer('per_page', 20), 50));
        $page = max(1, $request->integer('page', 1));
        $invoices = $this->salesQueueQuery(false)
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'rows' => $invoices->getCollection()->map(fn (Invoice $invoice) => [
                'uuid' => $invoice->uuid,
                'customer_name' => $invoice->customer_name,
                'customer_mobile' => $invoice->customer_mobile,
                'items_count' => (int) $invoice->items->sum('quantity'),
                'total' => (int) $invoice->total,
                'status' => $invoice->status,
                'status_label' => $this->statusService->labels()[$invoice->status] ?? $invoice->status,
                'created_at' => $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : null,
                'updated_at' => $invoice->updated_at ? Jalalian::fromDateTime($invoice->updated_at)->format('Y/m/d H:i') : null,
                'warehouse_received_at' => $invoice->warehouse_received_at ? Jalalian::fromDateTime($invoice->warehouse_received_at)->format('Y/m/d H:i') : null,
                'collection_started_at' => $invoice->collection_started_at ? Jalalian::fromDateTime($invoice->collection_started_at)->format('Y/m/d H:i') : null,
                'collected_at' => $invoice->collected_at ? Jalalian::fromDateTime($invoice->collected_at)->format('Y/m/d H:i') : null,
                'seller' => $invoice->effectiveSeller()?->name,
                'show_url' => route('vouchers.sales.show', $invoice->uuid),
                'print_url' => route('vouchers.sales.print', $invoice->uuid),
                'edit_items_url' => $invoice->status === Invoice::STATUS_COLLECTING ? route('vouchers.sales.collection.edit', $invoice->uuid) : null,
                'history_url' => route('vouchers.sales.history', $invoice->uuid),
                'receive_url' => $invoice->status === Invoice::STATUS_PENDING_COLLECTION ? route('vouchers.sales.queue.receive', $invoice->uuid) : null,
                'start_collection_url' => $invoice->status === Invoice::STATUS_WAREHOUSE_RECEIVED ? route('vouchers.sales.queue.start-collection', $invoice->uuid) : null,
                'complete_collection_url' => in_array((string) $invoice->status, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING], true) ? route('vouchers.sales.queue.complete-collection', $invoice->uuid) : null,
            ])->values(),
            'total' => $invoices->total(),
            'current_page' => $invoices->currentPage(),
            'per_page' => $invoices->perPage(),
            'last_page' => $invoices->lastPage(),
        ]);
    }

    private function salesQueueQuery(bool $shipped)
    {
        return Invoice::query()
            ->with(['items.product', 'items.variant', 'seller:id,name', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name'])
            ->when($shipped, fn ($query) => $query->where('status', SalesHavalehStatusService::SHIPPED), fn ($query) => $query->whereIn('status', $this->queueStatuses()));
    }

    private function queueStatuses(): array
    {
        return [
            Invoice::STATUS_PENDING_COLLECTION,
            Invoice::STATUS_WAREHOUSE_RECEIVED,
            Invoice::STATUS_COLLECTING,
        ];
    }

    public function salesVoucherShow(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant', 'notes', 'seller:id,name', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name', 'shippingMethod:id,name,price', 'dispatchShippingMethod:id,name,price', 'shippedBy:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->accessService->canViewInvoiceReadonly($invoice, auth()->user()), 403);

        $statusLabels = $this->statusService->labels();

        return view('vouchers.sales.show', compact('invoice', 'statusLabels'));
    }

    public function salesVoucherEdit(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $statusLabels = $this->statusService->labels();
        $canEditItems = in_array((string) $invoice->status, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING], true);

        $canAdjustPrice = auth()->user()?->hasPermission('warehouse.collection.adjust_price')
                          || auth()->user()?->hasAnyRole(['admin', 'Admin', 'manager', 'Manager', 'finance', 'Finance', 'Accountant']);
        $openedAt = optional($invoice->items_updated_at ?: $invoice->updated_at)->toJSON();

        return view('vouchers.sales.edit', compact('invoice', 'statusLabels', 'canEditItems', 'canAdjustPrice', 'openedAt'));
    }


    public function salesProductCategories()
    {
        $categories = Category::query()
            ->with(['children:id,name,code,parent_id'])
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'parent_id'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'code' => $category->code,
                'children' => $category->children->map(fn (Category $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'code' => $child->code,
                ])->values(),
            ]);

        return response()->json(['categories' => $categories]);
    }

    public function salesProductsByCategory(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $categoryIds = Category::selfAndDescendantIds((int) $data['category_id']);
        $query = Product::query()
            ->withCount(['variants as active_variants_count' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('category_id', $categoryIds)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->limit(50);

        if (! empty($data['q'])) {
            $term = trim((string) $data['q']);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }

        return response()->json([
            'products' => $query->get(['id', 'name', 'sku', 'code'])->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku ?: $product->code,
                'active_variants_count' => (int) $product->active_variants_count,
            ]),
        ]);
    }

    public function salesProductVariants(Product $product)
    {
        $variants = $product->variants()
            ->where('is_active', true)
            ->orderBy('variant_name')
            ->get()
            ->map(function (ProductVariant $variant) use ($product) {
                $available = WarehouseStockService::available(WarehouseStockService::centralWarehouseId(), (int) $product->id, (int) $variant->id);

                return [
                    'id' => $variant->id,
                    'product_id' => $product->id,
                    'title' => $variant->variant_name ?: $variant->variety_name ?: ('#' . $variant->id),
                    'sku' => $variant->variant_code ?: $variant->variety_code,
                    'sell_price' => (int) $variant->sell_price,
                    'available_stock' => $available,
                    'is_active' => (bool) $variant->is_active,
                ];
            })
            ->filter(fn (array $variant) => $variant['available_stock'] > 0)
            ->values();

        return response()->json(['variants' => $variants]);
    }

    public function salesVoucherUpdate(string $uuid, Request $request)
    {
        $data = $request->validate([
            'items_payload' => 'nullable|string',
            'items_payload_count' => 'nullable|integer|min:1|max:2000',
            'opened_at' => 'required|string',
            'change_reason' => ['required', 'string', Rule::in(['physical_shortage', 'customer_cancelled', 'wrong_item', 'warehouse_correction', 'replacement', 'other'])],
            'change_note' => 'required_if:change_reason,other|nullable|string|max:2000',
            'collection_note' => 'nullable|string|max:2000',
        ]);

        if ($request->filled('items_payload')) {
            try {
                $items = json_decode((string) $data['items_payload'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages(['items_payload' => 'اطلاعات اقلام فاکتور ناقص یا نامعتبر است. صفحه را تازه‌سازی کرده و دوباره تلاش کنید.']);
            }

            if (! is_array($items)) {
                throw ValidationException::withMessages(['items_payload' => 'اطلاعات اقلام فاکتور ناقص یا نامعتبر است. صفحه را تازه‌سازی کرده و دوباره تلاش کنید.']);
            }

            $expectedCount = (int) ($data['items_payload_count'] ?? 0);
            if ($expectedCount < 1 || count($items) < 1 || count($items) > 2000 || count($items) !== $expectedCount) {
                throw ValidationException::withMessages(['items_payload' => 'اطلاعات فرم به‌صورت ناقص به سرور رسیده است. هیچ تغییری ثبت نشد.']);
            }

            $items = collect($items)->map(function ($item) {
                $item = is_array($item) ? $item : [];
                if (filter_var($item['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $item['quantity'] = 0;
                    $item['_delete'] = true;
                }

                return $item;
            })->all();

            Validator::make(['items' => $items], [
                'items' => 'required|array|min:1|max:2000',
                'items.*.id' => 'nullable|integer|exists:invoice_items,id',
                'items.*.invoice_item_id' => 'nullable|integer|exists:invoice_items,id',
                'items.*.product_id' => 'required|integer|exists:products,id',
                'items.*.variant_id' => 'required|integer|exists:product_variants,id',
                'items.*.quantity' => 'required|integer|min:0',
                'items.*._delete' => 'nullable|boolean',
                'items.*.price' => 'nullable|numeric|min:1',
                'items.*.line_discount_amount' => 'nullable|numeric|min:0',
            ])->validate();
        } else {
            $legacy = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'nullable|exists:invoice_items,id',
                'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
                'items.*.product_id' => 'nullable|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.quantity' => 'required|integer|min:0',
                'items.*._delete' => 'nullable|boolean',
                'items.*.price' => 'nullable|numeric|min:1',
                'items.*.line_discount_amount' => 'nullable|numeric|min:0',
            ]);
            $items = $legacy['items'];

            if (isset($data['items_payload_count']) && count($items) < (int) $data['items_payload_count']) {
                throw ValidationException::withMessages(['items' => 'اطلاعات فرم به‌صورت ناقص به سرور رسیده است. هیچ تغییری ثبت نشد.']);
            }
        }

        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $canAdjustPrice = auth()->user()?->hasPermission('warehouse.collection.adjust_price')
                          || auth()->user()?->hasAnyRole(['admin', 'Admin', 'manager', 'Manager', 'finance', 'Finance', 'Accountant']);
        $this->warehouseCollectionService->updateCollectedItems($invoice, $items, auth()->user(), $data['collection_note'] ?? $data['change_note'] ?? null, $canAdjustPrice, $data['change_reason'], $data['opened_at']);

        $this->notifyFinanceReapproval($invoice);

        return redirect()->route('vouchers.sales.queue')
            ->with('success', 'تغییرات اقلام ثبت شد و فاکتور برای تایید مجدد به مالی ارجاع شد.');
    }

    public function receiveSalesQueueInvoice(string $uuid)
    {
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $this->warehouseCollectionService->receiveInvoice($invoice, auth()->user());

        return redirect()->route('vouchers.sales.queue')->with('success', 'فاکتور توسط انبار دریافت شد.');
    }

    public function startSalesQueueCollection(string $uuid)
    {
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $this->warehouseCollectionService->startCollection($invoice, auth()->user());

        return redirect()->route('vouchers.sales.queue')->with('success', 'جمع‌آوری فاکتور شروع شد.');
    }

    public function completeSalesQueueCollection(string $uuid, Request $request)
    {
        $data = $request->validate(['collection_note' => 'nullable|string|max:2000']);
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $this->warehouseCollectionService->completeWithoutChanges($invoice, auth()->user(), $data['collection_note'] ?? null);

        return redirect()->route('vouchers.sales.queue')->with('success', 'جمع‌آوری فاکتور نهایی شد و به صف ارسال بار منتقل شد.');
    }

    public function updateSalesQueueItems(string $uuid, Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:0',
            'collection_note' => 'nullable|string|max:2000',
        ]);

        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $this->warehouseCollectionService->updateCollectedItems($invoice, $data['items'], auth()->user(), $data['collection_note'] ?? null, false, 'warehouse_queue_items', $request->input('opened_at', optional($invoice->items_updated_at ?: $invoice->updated_at)->toJSON()));

        $this->notifyFinanceReapproval($invoice);

        return redirect()->route('vouchers.sales.queue')->with('success', 'تغییرات اقلام ثبت شد و فاکتور برای تایید مجدد به مالی ارجاع شد.');
    }

    public function financeReapproveInvoice(string $uuid)
    {
        $invoice = DB::transaction(function () use ($uuid) {
            $invoice = Invoice::query()->with('items')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 422, 'وضعیت فاکتور برای تایید مجدد مالی مجاز نیست.');
            abort_if($invoice->items->sum('quantity') <= 0, 422, 'فاکتور باید حداقل یک قلم کالا داشته باشد.');
            abort_if($invoice->items->contains(fn ($item) => (int) $item->quantity <= 0), 422, 'تعداد همه ردیف‌ها باید بیشتر از صفر باشد.');
            abort_if($invoice->items->contains(fn ($item) => (int) $item->price <= 0), 422, 'قیمت snapshot همه ردیف‌ها باید بیشتر از صفر باشد.');
            $integrityIssues = SalesDocumentTotals::integrityIssues($invoice);
            if ($integrityIssues !== []) {
                Log::warning('Invoice finance reapproval blocked by pricing integrity check.', [
                    'document_type' => 'invoice',
                    'document_id' => (int) $invoice->id,
                    'document_number' => (string) $invoice->uuid,
                    'user_id' => auth()->id(),
                    'issues' => $integrityIssues,
                    'calculation_version' => SalesDocumentTotals::CALCULATION_VERSION,
                ]);
                throw ValidationException::withMessages([
                    'invoice' => 'این فاکتور به دلیل مغایرت مبلغ با snapshot اقلام قابل تایید نیست. ابتدا بررسی صحت فاکتور را انجام دهید.
                    این فاکتور دارای مغایرت مالی است و نیاز به بررسی دارد، قیمت نهایی حاصل از تعداد کالاها با محاسبه تخفیف همخوانی ندارد.',
                ]);
            }
            $canonicalTotals = SalesDocumentTotals::fromDocument($invoice);
            $subtotal = (int) $canonicalTotals['subtotal_before_discount'] + (int) $canonicalTotals['shipping'];
            $discount = (int) $canonicalTotals['total_discount'];
            abort_if((int) $invoice->total !== max($subtotal - $discount, 0), 422, 'جمع فاکتور با اقلام snapshot همخوانی ندارد.');
            $this->customerLedgerService->syncInvoiceDebit($invoice);
            $invoice->update(['status' => Invoice::STATUS_READY_TO_SHIP, 'status_changed_at' => now(), 'status_changed_by' => auth()->id()]);
            $this->warehouseCollectionServiceHistory($invoice, 'finance_reapproved', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_READY_TO_SHIP, 'فاکتور تایید مجدد شد و به صف ارسال بار منتقل شد.');
            return $invoice;
        });

        // The outbound webhook must run only after the reapproval transaction has
        // committed, otherwise a rollback would leave the CRM told about a completion
        // that never happened, with its own log row rolled back as well.
        $invoice->loadMissing('customer', 'payments');
        InventoryWebhookService::send(
            'invoice.collection.completed',
            [
                'invoice_id' => $invoice->id,
                'external_order_id' => $invoice->external_order_id,
                'crm_customer_id' => $invoice->customer?->crm_customer_id,
                'total' => (int) $invoice->total,
                'paid_amount' => (int) $invoice->paid_amount,
                'credit_amount' => max((int) ($invoice->payments?->sum('amount') ?? 0) - (int) $invoice->total, 0),
                'collection_adjustment_id' => 'invoice-' . $invoice->id,
            ]
        );

        $invoice->loadMissing('preinvoiceOrder');
        if ($invoice->preinvoiceOrder?->created_by) {
            $this->notificationService->notifyUserAfterCommit((int) $invoice->preinvoiceOrder->created_by, 'invoice_finance_reapproved', 'فاکتور پس از اصلاح انبار تایید شد', 'فاکتور شماره «' . $invoice->uuid . '» برای مشتری «' . ($invoice->customer_name ?: '---') . '» پس از حذف و اضافه انبار توسط مالی تایید شد و آماده ارسال بار است.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'success', 'priority' => 'important', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_finance_reapproved:' . $invoice->id]);
        }
        $this->notificationService->notifyRoleAfterCommit('warehouse', 'invoice_ready_to_ship', 'فاکتور آماده ارسال بار است', 'فاکتور شماره «' . $invoice->uuid . '» برای مشتری «' . ($invoice->customer_name ?: '---') . '» آماده ارسال بار است.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'success', 'priority' => 'important', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_ready_to_ship:' . $invoice->id]);
        return redirect()->route('preinvoice.draft.index')->with('success', 'فاکتور تایید مجدد شد و به صف ارسال بار منتقل شد.');
    }

    public function financeReturnInvoiceToSales(string $uuid, Request $request)
    {
        $data = $request->validate(['reason' => 'required|string|max:255', 'note' => 'nullable|string|max:2000']);
        $invoice = DB::transaction(function () use ($uuid, $data) {
            $invoice = Invoice::query()->with('preinvoiceOrder')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 422, 'وضعیت فاکتور برای ارجاع مجاز نیست.');
            $invoice->update(['status' => Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, 'status_changed_at' => now(), 'status_changed_by' => auth()->id(), 'collection_note' => trim($data['reason'] . (!empty($data['note']) ? ' - ' . $data['note'] : ''))]);
            $this->warehouseCollectionServiceHistory($invoice, 'finance_returned_to_sales', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, trim($data['reason'] . (!empty($data['note']) ? ' - ' . $data['note'] : '')));
            return $invoice;
        });
        if ($invoice->preinvoiceOrder?->created_by) {
            $this->notificationService->notifyUserAfterCommit((int) $invoice->preinvoiceOrder->created_by, 'invoice_returned_to_sales_after_collection', 'فاکتور برای بررسی به شما ارجاع شد', 'فاکتور پس از حذف و اضافه انبار توسط مالی برای بررسی به شما ارجاع شد.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'warning', 'priority' => 'urgent', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_returned_to_sales:' . $invoice->id]);
        }
        return redirect()->route('preinvoice.draft.index')->with('success', 'فاکتور برای بررسی به اپراتور ارجاع شد.');
    }

    private function notifyFinanceReapproval(Invoice $invoice): void
    {
        try {
            $invoice = $invoice->fresh();
            $this->notificationService->notifyRoleAfterCommit('finance', 'invoice_pending_finance_reapproval', 'فاکتور نیازمند تایید مجدد مالی است', 'فاکتور شماره «' . $invoice->uuid . '» پس از تغییر اقلام جمع‌آوری، برای تأیید مجدد مالی ارسال شد.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'warning', 'priority' => 'urgent', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_pending_finance_reapproval:' . $invoice->id]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function warehouseCollectionServiceHistory(Invoice $invoice, string $action, string $old, string $new, string $description): void
    {
        app(\App\Services\SalesHavalehHistoryService::class)->log($invoice, $action, 'status', $old, $new, $description, auth()->id());
    }

    public function salesVoucherHistory(string $uuid)
    {
        $invoice = Invoice::query()->with('histories.actor')->where('uuid', $uuid)->firstOrFail();

        if (request()->expectsJson()) {
            return response()->json([
                'invoice_uuid' => $invoice->uuid,
                'history' => $invoice->histories->map(fn ($h) => [
                    'action_type' => $h->action_type,
                    'field_name' => $h->field_name,
                    'old_value' => $h->old_value,
                    'new_value' => $h->new_value,
                    'description' => $h->description,
                    'done_by' => $h->actor?->name,
                    'done_at' => optional($h->done_at)->toDateTimeString(),
                ])->values(),
            ]);
        }

        $statusLabels = $this->statusService->labels();
        return view('invoices.history', compact('invoice', 'statusLabels'));
    }

    public function history(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['histories.actor', 'payments.creator', 'notes.user', 'seller:id,name', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->accessService->canViewInvoiceReadonly($invoice, auth()->user()), 403);

        $statusLabels = $this->statusService->labels();
        return view('invoices.history', compact('invoice', 'statusLabels'));
    }

    public function edit(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant', 'payments.cheque', 'notes.user', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name', 'seller:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->canManageInvoice($invoice), 403);

        $paidTotal = (int) $invoice->payments->sum('amount');
        $remainingAmount = max((int) $invoice->total - $paidTotal, 0);
        $canRegisterPayments = $this->canHandleFinanceActions();
        $canEditPrices = $this->canHandleFinanceActions();
        $canEditItemsWithCollectionFlow = (string) $invoice->status !== Invoice::STATUS_SHIPPED;
        $statusLabels = $this->statusService->labels();

        $canReassignSeller = $this->canReassignSeller(auth()->user());
        $sellers = $canReassignSeller ? User::activeSellers()->orderBy('name')->get(['id', 'name']) : collect();
        return view('invoices.edit', compact('invoice', 'paidTotal', 'remainingAmount', 'canRegisterPayments', 'canEditPrices', 'canEditItemsWithCollectionFlow', 'statusLabels', 'canReassignSeller', 'sellers'));
    }

    public function update(string $uuid, Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'nullable|integer|min:1',
            'items.*.line_discount_amount' => 'nullable|integer|min:0',
            'change_reason' => 'nullable|string|max:255',
            'change_note' => 'nullable|string|max:2000',
        ]);

        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->canManageInvoice($invoice), 403);

        $this->warehouseCollectionService->updateInvoiceItemsInPlace(
            $invoice,
            $data['items'],
            auth()->user(),
            $this->canHandleFinanceActions(),
            $data['change_reason'] ?? null,
            $data['change_note'] ?? null
        );

        if ($invoice->preinvoiceOrder?->created_by) {
            $this->notificationService->notifyUserAfterCommit((int) $invoice->preinvoiceOrder->created_by, 'invoice_items_updated_in_edit', 'فاکتور مشتری شما به‌روزرسانی شد', 'اقلام یا مبلغ فاکتور شماره «' . $invoice->uuid . '» به‌روزرسانی شد.', route('preinvoice.my.index'), ['level' => 'info', 'priority' => 'normal', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_items_updated_in_edit:' . $invoice->id . ':' . now()->timestamp]);
        }

        return redirect()->route('invoices.edit', $invoice->uuid)
            ->with('success', 'تغییرات اقلام فاکتور ذخیره شد.');
    }

    public function reassignSeller(string $uuid, Request $request)
    {
        abort_unless($this->canReassignSeller($request->user()), 403);
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'sync_preinvoice' => ['nullable', 'boolean'],
        ]);
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $seller = User::query()->findOrFail($data['seller_id']);
        $result = $this->sellerReassignmentService->reassignInvoiceSeller($invoice, $seller, $request->user(), $data['reason'], $request->boolean('sync_preinvoice', true));

        $message = match (true) {
            $result->changed && $result->commissionClaimRepaired => 'فروشنده فاکتور تغییر کرد و فاکتور از سند پورسانت فروشنده قبلی آزاد شد.',
            $result->changed => 'فروشنده فاکتور تغییر کرد.',
            $result->commissionClaimRepaired => 'فروشنده از قبل همین کاربر بود؛ ناسازگاری سند پورسانت اصلاح و فاکتور آزاد شد.',
            default => 'فروشنده و وضعیت پورسانت از قبل صحیح بودند.',
        };

        return back()->with('success', $message);
    }

    public function bulkReassignSeller(Request $request)
    {
        abort_unless($this->canReassignSeller($request->user()), 403);
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'distinct'],
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'sync_preinvoice' => ['nullable', 'boolean'],
            'operation_key' => ['nullable', 'string', 'max:100'],
        ]);
        $seller = User::query()->findOrFail($data['seller_id']);
        $results = $this->sellerReassignmentService->reassignMany($data['invoice_ids'], $seller, $request->user(), $data['reason'], $request->boolean('sync_preinvoice', true), 'bulk', $data['operation_key'] ?? null);
        $results = collect($results);
        $changed = $results->where('changed', true)->count();
        $repaired = $results->where('commissionClaimRepaired', true)->count();

        return back()->with(
            'success',
            "فروشنده {$changed} فاکتور تغییر کرد؛ وضعیت پورسانت {$repaired} فاکتور آزاد/اصلاح شد."
        );
    }

    private function canReassignSeller(?User $user): bool
    {
        return $user !== null && $user->hasAnyRole(array_merge(PermissionCatalog::administratorRoles(), ['Manager', 'manager', 'sales_manager']));
    }

    private function canManageInvoice(Invoice $invoice): bool
    {
        $user = auth()->user();

        return $user && $this->accessService->canSellerEditInvoiceItems($invoice, $user);
    }

    public function print(string $uuid, Request $request, SalesPrintDocumentService $printService)
    {
        $invoice = Invoice::query()
            ->with([
                'items.product',
                'items.variant',
                'seller:id,name',
                'preinvoiceOrder.seller:id,name',
                'preinvoiceOrder.creator',
                'shippingMethod:id,name,price',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->accessService->canViewInvoiceReadonly($invoice, auth()->user()), 403);

        $printData = $printService->invoiceData($invoice, (string) $request->query('mode', $request->query('print', 'warehouse')));

        return view('prints.invoice', compact('printData'));
    }

    public function show(string $uuid)
    {
        $invoice = Invoice::query()
            ->with([
                'items.product',
                'items.variant',
                'payments.cheque',
                'payments.creator',
                'notes',
                'seller:id,name',
                'preinvoiceOrder.seller:id,name',
                'preinvoiceOrder.creator:id,name',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->accessService->canViewInvoiceReadonly($invoice, auth()->user()), 403);

        $paidTotal = (int) $invoice->payments->sum('amount');
        $remainingAmount = max((int) $invoice->total - $paidTotal, 0);
        $user = auth()->user();
        $canHandleFinanceActions = $this->canHandleFinanceActions();
        $canEditInvoice = $this->canManageInvoice($invoice)
                          && $user
                          && PageAccessCatalog::userCanRoute($user, 'invoices.edit');
        $canRegisterPayments = $canHandleFinanceActions && $remainingAmount > 0;
        $canCancelInvoice = $this->canCancelInvoices();
        $canPrintInvoice = $user && PageAccessCatalog::userCanRoute($user, 'invoices.print');
        $backUrl = $this->invoiceShowBackUrl($user);
        $statusLabels = $this->statusService->labels();

        return view('invoices.show', compact(
            'invoice',
            'paidTotal',
            'remainingAmount',
            'canEditInvoice',
            'canRegisterPayments',
            'canHandleFinanceActions',
            'canCancelInvoice',
            'canPrintInvoice',
            'backUrl',
            'statusLabels'
        ));
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && PageAccessCatalog::userCan($user, 'page.finance.payments');
    }

    private function canCancelInvoices(): bool
    {
        $user = auth()->user();

        return $user && PageAccessCatalog::userCan($user, 'page.sales.invoices');
    }


    private function invoiceShowBackUrl(?User $user): string
    {
        if (! $user) {
            return route('login');
        }

        if (PageAccessCatalog::userCan($user, 'page.sales.invoices')) {
            return route('invoices.index');
        }

        if (PageAccessCatalog::userCan($user, 'page.sales.preinvoice_finance_review')) {
            return route('preinvoice.draft.index');
        }

        if (PageAccessCatalog::userCan($user, 'page.warehouse.issues')) {
            return route('vouchers.index');
        }

        if (PageAccessCatalog::userCan($user, 'page.sales.preinvoices')) {
            return route('preinvoice.my.index');
        }

        return route('access.unassigned');
    }

    public function updateStatus(string $uuid, Request $request)
    {
        Invoice::where('uuid', $uuid)->firstOrFail();

        return back()->with('error', 'تغییر وضعیت دستی در نسخه جدید غیرفعال است. وضعیت‌ها فقط از مسیرهای رسمی تغییر می‌کنند.');
    }

    public function cancel(string $uuid, Request $request)
    {
        abort_unless($this->canCancelInvoices(), 403);

        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $rules = [
            'cancellation_reason' => 'required|string|max:1000',
            'cancellation_note' => 'nullable|string|max:2000',
            'confirm_invoice_uuid' => ['required', 'string', Rule::in([(string) $invoice->uuid])],
        ];
        if ((string) $invoice->status === Invoice::STATUS_SHIPPED) {
            $rules['physical_return_confirmed'] = 'accepted';
        }
        $data = $request->validate($rules, [
            'confirm_invoice_uuid.in' => 'شماره فاکتور واردشده با فاکتور انتخاب‌شده مطابقت ندارد.',
            'physical_return_confirmed.accepted' => 'برای لغو فاکتور ارسال‌شده، تأیید بازگشت/ارسال فیزیکی کالا جهت تحویل به انبار الزامی است.',
        ]);

        $this->salesHavalehService->cancelAndRestore($invoice, $data['cancellation_reason'], auth()->id(), $data['cancellation_note'] ?? null);

        return redirect()->route('invoices.index')->with('success', 'فاکتور با موفقیت لغو شد و اقلام آن وارد صف دریافت انبار شدند. موجودی فقط پس از تأیید مدیر انبار افزایش می‌یابد؛ سند فاکتور از گردش حساب مشتری حذف شد و پرداخت‌ها بدون تغییر ماندند.');
    }

    public function cancelled(Request $request)
    {
        abort_unless($this->canCancelInvoices(), 403);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];
        $dateFrom = $this->parseInvoiceFilterDate($filters['date_from']);
        $dateTo = $this->parseInvoiceFilterDate($filters['date_to']);

        $invoices = Invoice::query()->cancelled()
            ->select('invoices.*')
            ->selectSub('select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id', 'paid_total')
            ->with(['payments.cheque', 'customer:id,crm_customer_id,first_name,last_name,mobile', 'seller:id,name', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name', 'canceller:id,name'])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $q = $filters['q'];
                $query->where(function ($qq) use ($q) {
                    $qq->where('uuid', 'like', "%{$q}%")
                        ->orWhere('customer_name', 'like', "%{$q}%")
                        ->orWhere('customer_mobile', 'like', "%{$q}%");
                });
            })
            ->when($dateFrom, fn ($q) => $q->where('cancelled_at', '>=', $dateFrom->copy()->startOfDay()))
            ->when($dateTo, fn ($q) => $q->where('cancelled_at', '<=', $dateTo->copy()->endOfDay()))
            ->orderByDesc('cancelled_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('invoices.cancelled', ['invoices' => $invoices, 'filters' => $filters]);
    }

    public function undoCancel(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);
        $this->salesHavalehService->undoCancelAndReserve($invoice, $data['note'] ?? null, auth()->id());

        return back()->with('success', '✅ کنسلی فاکتور لغو شد و سند دوباره به صف تایید انبار برگشت.');
    }

    private function liveInvoiceQuery(array $filters, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Invoice::query()->active()
            ->select('invoices.*')
            ->selectSub('select count(*) from invoice_items where invoice_items.invoice_id = invoices.id and invoice_items.quantity > 0 and invoice_items.price <= 0', 'zero_price_items_count')
            ->selectSub('select coalesce(sum(case when (quantity * price) - coalesce(line_discount_amount, 0) > 0 then (quantity * price) - coalesce(line_discount_amount, 0) else 0 end), 0) from invoice_items where invoice_items.invoice_id = invoices.id', 'snapshot_items_total')
            ->selectSub("select count(*) from customer_ledgers where customer_ledgers.reference_type = 'App\\Models\\Invoice' and customer_ledgers.reference_id = invoices.id and customer_ledgers.type = 'debit'", 'ledger_debit_count');

        if (($filters['customer_id'] ?? null) !== null) {
            $query->where('invoices.customer_id', (int) $filters['customer_id']);
        }
        if ($dateFrom) {
            $query->where('invoices.created_at', '>=', $dateFrom->copy()->startOfDay());
        }
        if ($dateTo) {
            $query->where('invoices.created_at', '<=', $dateTo->copy()->endOfDay());
        }

        $orderCode = (string) ($filters['order_code'] ?? '');
        if ($orderCode !== '') {
            $query->where(function ($codeQuery) use ($orderCode) {
                $codeQuery->where('invoices.uuid', 'like', "%{$orderCode}%")
                    ->orWhereHas('preinvoiceOrder', fn ($preinvoiceQuery) => $preinvoiceQuery->where('uuid', 'like', "%{$orderCode}%"));
            });
        }

        return $query;
    }

    private function liveInvoiceDateRange(InvoiceLiveFilterRequest $request): array
    {
        $quickRange = (string) $request->input('quick_range', '');
        if ($quickRange === 'today') {
            return [now()->startOfDay(), now()->endOfDay()];
        }
        if ($quickRange === 'week') {
            return [now()->startOfWeek(Carbon::SATURDAY)->startOfDay(), now()->endOfDay()];
        }
        if ($quickRange === 'month') {
            $today = Jalalian::now();
            $start = new Jalalian($today->getYear(), $today->getMonth(), 1);
            $nextYear = $today->getMonth() === 12 ? $today->getYear() + 1 : $today->getYear();
            $nextMonth = $today->getMonth() === 12 ? 1 : $today->getMonth() + 1;

            return [$start->toCarbon()->startOfDay(), (new Jalalian($nextYear, $nextMonth, 1))->toCarbon()->subSecond()];
        }

        return [$request->jalaliDate('date_from'), $request->jalaliDate('date_to')];
    }

    private function liveInvoiceSummary($query): array
    {
        $paidExpression = '(select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id)';
        $summaryQuery = clone $query;
        $summaryQuery->getQuery()->columns = null;
        $row = $summaryQuery->reorder()->selectRaw("count(*) as invoice_count, coalesce(sum(invoices.total), 0) as total_sales, coalesce(sum({$paidExpression}), 0) as paid_amount, coalesce(sum(case when invoices.total - {$paidExpression} > 0 then invoices.total - {$paidExpression} else 0 end), 0) as remaining_amount")->first();

        return [
            'invoice_count' => (int) ($row->invoice_count ?? 0),
            'total_sales' => (int) ($row->total_sales ?? 0),
            'paid_amount' => (int) ($row->paid_amount ?? 0),
            'remaining_amount' => (int) ($row->remaining_amount ?? 0),
        ];
    }

    private function invoiceListPermissions(Request $request): array
    {
        $user = $request->user();

        $hasPage = $user && PageAccessCatalog::userCan($user, 'page.sales.invoices');

        return ['show' => $hasPage, 'print' => $hasPage, 'edit' => $hasPage, 'cancel' => $hasPage];
    }

    private function invoiceLiveMeta(Invoice $invoice, array $permissions): array
    {
        $paid = (int) ($invoice->paid_total ?? 0);
        $total = (int) $invoice->total;
        $remaining = max($total - $paid, 0);
        $customerName = $invoice->customer_name ?: $invoice->customer?->display_name ?: '—';
        $payment = $paid <= 0
            ? ['پرداخت‌نشده', 'danger']
            : ($paid < $total ? ['پرداخت ناقص', 'warning'] : ($paid > $total ? ['پرداخت اضافه', 'danger'] : ['تسویه‌شده', 'success']));
        $status = (string) $invoice->status;
        $statusTone = match ($status) {
            Invoice::STATUS_SHIPPED => 'success',
            Invoice::STATUS_READY_TO_SHIP => 'info',
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'warning',
            default => 'secondary',
        };
        $warnings = collect($this->invoiceWarningLabels($invoice))->map(fn ($label) => [
            'label' => $label,
            'tone' => str_contains($label, 'اضافه') || str_contains($label, 'صفر') || str_contains($label, 'نامعتبر') ? 'danger' : 'warning',
        ])->all();

        return [
            'id' => $invoice->id,
            'number' => $invoice->uuid ?: '—',
            'date' => $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '—',
            'preinvoice' => $invoice->preinvoiceOrder?->uuid,
            'customer_name' => $customerName,
            'customer_mobile' => $invoice->customer_mobile ?: $invoice->customer?->mobile ?: '—',
            'customer_code' => $invoice->customer?->crm_customer_id ?: $invoice->customer_id ?: '—',
            'seller' => $invoice->effectiveSeller()?->name ?? '—',
            'status_label' => $this->statusService->labels()[$status] ?? ($status ?: '—'),
            'status_tone' => $statusTone,
            'legacy' => in_array($status, $this->invoiceLegacyStatuses(), true),
            'payment_label' => $payment[0],
            'payment_tone' => $payment[1],
            'total' => Currency::formatRial($total),
            'paid' => Currency::formatRial($paid),
            'remaining' => Currency::formatRial($remaining),
            'remaining_value' => $remaining,
            'warnings' => $warnings,
            'actions' => [
                'show' => $permissions['show'] ? route('invoices.show', $invoice->uuid) : null,
                'print' => $permissions['print'] ? route('invoices.print', $invoice->uuid) : null,
                'edit' => $permissions['edit'] ? route('invoices.edit', $invoice->uuid) : null,
                'cancel' => $permissions['cancel'] && ! $invoice->isCancelled() ? route('invoices.cancel', $invoice->uuid) : null,
            ],
            'is_shipped' => $status === Invoice::STATUS_SHIPPED,
        ];
    }

    private function customerSearchPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->display_name ?: 'بدون نام',
            'mobile' => $customer->mobile,
            'code' => $customer->crm_customer_id,
        ];
    }

    private function invoiceReportQuery(array $filters, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Invoice::query()
            ->select('invoices.*')
            ->selectSub('select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id', 'paid_total')
            ->selectSub('select count(*) from invoice_items where invoice_items.invoice_id = invoices.id and invoice_items.quantity > 0 and invoice_items.price <= 0', 'zero_price_items_count')
            ->selectSub('select coalesce(sum(greatest((quantity * price) - coalesce(line_discount_amount, 0), 0)), 0) from invoice_items where invoice_items.invoice_id = invoices.id', 'snapshot_items_total')
            ->selectSub("select count(*) from customer_ledgers where customer_ledgers.reference_type = 'App\\Models\\Invoice' and customer_ledgers.reference_id = invoices.id and customer_ledgers.type = 'debit'", 'ledger_debit_count')
            ->when($filters['invoice_number'] !== '', fn ($q) => $q->where('uuid', 'like', '%' . $filters['invoice_number'] . '%'))
            ->when($filters['customer_name'] !== '', function ($query) use ($filters) {
                $name = $filters['customer_name'];
                $query->where(function ($qq) use ($name) {
                    $qq->where('customer_name', 'like', "%{$name}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($name) {
                            $customerQuery->where('first_name', 'like', "%{$name}%")
                                ->orWhere('last_name', 'like', "%{$name}%")
                                ->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?", ["%{$name}%"]);
                        });
                });
            })
            ->when($filters['customer_code'] !== '', function ($query) use ($filters) {
                $code = $this->normalizeDigits($filters['customer_code']);
                $query->where(function ($qq) use ($code) {
                    if (ctype_digit($code)) {
                        $qq->where('customer_id', (int) $code);
                    }
                    $qq->orWhereHas('customer', fn ($customerQuery) => $customerQuery
                        ->where('id', 'like', "%{$code}%")
                        ->orWhere('crm_customer_id', 'like', "%{$code}%"));
                });
            })
            ->when($filters['customer_mobile'] !== '', function ($query) use ($filters) {
                $mobile = $this->normalizeDigits($filters['customer_mobile']);
                $query->where(function ($qq) use ($mobile) {
                    $qq->where('customer_mobile', 'like', "%{$mobile}%")
                        ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('mobile', 'like', "%{$mobile}%"));
                });
            })
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['seller'] !== '', fn ($q) => $q->whereHas('preinvoiceOrder.creator', fn ($userQ) => $userQ->where('name', 'like', '%' . $filters['seller'] . '%')))
            ->when($filters['has_cheque'] === '1', fn ($q) => $q->whereHas('payments.cheque'))
            ->when($filters['shipping_method'] !== '', fn ($q) => $q->where(function ($qq) use ($filters) {
                $qq->where('shipping_id', $filters['shipping_method'])->orWhere('shipping_method_id', $filters['shipping_method']);
            }))
            ->when($filters['legacy_only'] === '1', fn ($q) => $q->whereIn('status', $this->invoiceLegacyStatuses()))
            ->when($filters['min_amount'] !== '', fn ($q) => $q->where('total', '>=', (int) $filters['min_amount']))
            ->when($filters['max_amount'] !== '', fn ($q) => $q->where('total', '<=', (int) $filters['max_amount']));

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom->copy()->startOfDay());
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo->copy()->endOfDay());
        }

        $paidExpr = '(select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id)';
        if ($filters['only_remaining'] === '1') {
            $query->whereRaw("(invoices.total - {$paidExpr}) > 0");
        }
        if ($filters['only_paid'] === '1') {
            $query->whereRaw("(invoices.total - {$paidExpr}) <= 0");
        }
        if ($filters['overpaid_only'] === '1') {
            $query->whereRaw("{$paidExpr} > invoices.total");
        }
        if ($filters['has_warnings'] === '1') {
            $legacyList = implode(',', array_fill(0, count($this->invoiceLegacyStatuses()), '?'));
            $query->where(function ($warningQuery) use ($paidExpr, $legacyList) {
                $warningQuery->whereExists(fn ($itemQ) => $itemQ->selectRaw('1')->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->where('quantity', '>', 0)->where('price', '<=', 0))
                    ->orWhereRaw("abs(invoices.total - (select coalesce(sum(greatest((quantity * price) - coalesce(line_discount_amount, 0), 0)), 0) from invoice_items where invoice_items.invoice_id = invoices.id)) > 1")
                    ->orWhereRaw("{$paidExpr} > invoices.total")
                    ->orWhereNull('uuid')
                    ->orWhere('uuid', '')
                    ->orWhereIn('status', $this->invoiceLegacyStatuses())
                    ->orWhereRaw("(select count(*) from customer_ledgers where customer_ledgers.reference_type = 'App\\Models\\Invoice' and customer_ledgers.reference_id = invoices.id and customer_ledgers.type = 'debit') > 1");
            });
        }
        match ($filters['payment_status']) {
            'paid' => $query->whereRaw("{$paidExpr} = invoices.total"),
            'overpaid' => $query->whereRaw("{$paidExpr} > invoices.total"),
            'partial' => $query->whereRaw("{$paidExpr} > 0 and (invoices.total - {$paidExpr}) > 0"),
            'unpaid' => $query->whereRaw("{$paidExpr} = 0 and invoices.total > 0"),
            default => null,
        };

        return $query;
    }

    private function invoiceReportSummary($query): array
    {
        $rows = DB::query()->fromSub($query->toBase(), 'invoice_report')->selectRaw(<<<'SQL'
            count(*) as invoice_count,
            coalesce(sum(total), 0) as total_sales,
            coalesce(sum(paid_total), 0) as paid_amount,
            coalesce(sum(greatest(total - paid_total, 0)), 0) as remaining_amount,
            coalesce(sum(case when paid_total = total then 1 else 0 end), 0) as paid_count,
            coalesce(sum(case when paid_total > 0 and paid_total < total then 1 else 0 end), 0) as partial_count,
            coalesce(sum(case when paid_total <= 0 then 1 else 0 end), 0) as unpaid_count,
            coalesce(sum(case when paid_total > total then 1 else 0 end), 0) as overpaid_count,
            coalesce(sum(case when status = 'ready_to_ship' then 1 else 0 end), 0) as ready_to_ship_count,
            coalesce(sum(case when status = 'shipped' then 1 else 0 end), 0) as shipped_count,
            coalesce(sum(case when status = 'pending_finance_reapproval' then 1 else 0 end), 0) as pending_finance_reapproval_count
SQL)->first();

        return [
            'invoice_count' => (int) ($rows->invoice_count ?? 0),
            'total_sales' => (int) ($rows->total_sales ?? 0),
            'paid_amount' => (int) ($rows->paid_amount ?? 0),
            'remaining_amount' => (int) ($rows->remaining_amount ?? 0),
            'paid_count' => (int) ($rows->paid_count ?? 0),
            'partial_count' => (int) ($rows->partial_count ?? 0),
            'unpaid_count' => (int) ($rows->unpaid_count ?? 0),
            'overpaid_count' => (int) ($rows->overpaid_count ?? 0),
            'ready_to_ship_count' => (int) ($rows->ready_to_ship_count ?? 0),
            'shipped_count' => (int) ($rows->shipped_count ?? 0),
            'pending_finance_reapproval_count' => (int) ($rows->pending_finance_reapproval_count ?? 0),
        ];
    }

    private function quickJalaliRange(string $range): array
    {
        $today = Jalalian::now();
        $currentYear = $today->getYear();
        $currentMonth = $today->getMonth();
        $lastMonthYear = $currentMonth === 1 ? $currentYear - 1 : $currentYear;
        $lastMonth = $currentMonth === 1 ? 12 : $currentMonth - 1;
        $nextMonthYear = $currentMonth === 12 ? $currentYear + 1 : $currentYear;
        $nextMonth = $currentMonth === 12 ? 1 : $currentMonth + 1;

        return match ($range) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'this_week' => [now()->startOfWeek(Carbon::SATURDAY)->startOfDay(), now()->endOfDay()],
            'this_month' => [
                (new Jalalian($currentYear, $currentMonth, 1))->toCarbon()->startOfDay(),
                (new Jalalian($nextMonthYear, $nextMonth, 1))->toCarbon()->subSecond(),
            ],
            'last_month' => [
                (new Jalalian($lastMonthYear, $lastMonth, 1))->toCarbon()->startOfDay(),
                (new Jalalian($currentYear, $currentMonth, 1))->toCarbon()->subSecond(),
            ],
            default => [null, null],
        };
    }

    private function parseInvoiceFilterDate(string $dateInput): ?Carbon
    {
        $dateInput = $this->normalizeDigits($dateInput);

        if ($dateInput === '') {
            return null;
        }

        $dateInput = str_replace(['-', '.', ' '], '/', $dateInput);

        try {
            if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $dateInput) === 1) {
                [$year, $month, $day] = array_map('intval', explode('/', $dateInput));

                if ($year >= 1300 && $year <= 1600) {
                    return (new Jalalian($year, $month, $day))->toCarbon()->startOfDay();
                }

                return Carbon::create($year, $month, $day)->startOfDay();
            }

            return Carbon::parse($dateInput)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDigits(string $value): string
    {
        return trim(strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
    }

    private function exportInvoiceAccountingCsv($invoices, array $filters, bool $excelAlias = false): StreamedResponse
    {
        $filename = 'invoice-accounting-report-' . now()->format('Ymd-His') . ($excelAlias ? '.xls' : '.csv');

        return response()->streamDownload(function () use ($invoices) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'invoice_number', 'invoice_date', 'customer_name', 'customer_code', 'customer_mobile',
                'invoice_total', 'paid_amount', 'remaining_amount', 'payment_status', 'operational_status', 'seller', 'warnings', 'created_at_jalali',
            ]);

            foreach ($invoices as $invoice) {
                $paid = (int) ($invoice->paid_total ?? 0);
                $remaining = max((int) $invoice->total - $paid, 0);
                fputcsv($handle, [
                    $invoice->uuid,
                    optional($invoice->display_document_date)->format('Y-m-d'),
                    $invoice->customer_name ?: $invoice->customer?->display_name,
                    $invoice->customer?->crm_customer_id ?: $invoice->customer_id,
                    $invoice->customer_mobile ?: $invoice->customer?->mobile,
                    (int) $invoice->total,
                    $paid,
                    $remaining,
                    $this->paymentStatusLabel($paid, (int) $invoice->total),
                    $this->statusService->labels()[$invoice->status] ?? ($invoice->status ?: ''),
                    $invoice->effectiveSeller()?->name ?? '',
                    implode(' | ', $this->invoiceWarningLabels($invoice)),
                    $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function paymentStatusLabel(int $paid, int $total): string
    {
        if ($paid <= 0) {
            return 'پرداخت‌نشده';
        }
        if ($paid < $total) {
            return 'پرداخت ناقص';
        }
        if ($paid > $total) {
            return 'تسویه‌شده با هشدار پرداخت اضافه';
        }

        return 'تسویه‌شده';
    }

    private function invoiceNewWorkflowStatuses(): array
    {
        return [Invoice::STATUS_PENDING_COLLECTION, Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING, Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_READY_TO_SHIP, Invoice::STATUS_SHIPPED, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION];
    }

    private function invoiceLegacyStatuses(): array
    {
        return [Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL, 'finance_approved', Invoice::STATUS_CHECKING_DISCREPANCY, Invoice::STATUS_FINAL_CHECK, Invoice::STATUS_PACKING, Invoice::STATUS_NOT_SHIPPED];
    }

    private function invoiceWarningLabels(Invoice $invoice): array
    {
        $paid = (int) ($invoice->paid_total ?? 0);
        $total = (int) $invoice->total;
        $snapshotTotal = (int) ($invoice->snapshot_items_total ?? $total);
        $warnings = [];
        if ((int) ($invoice->zero_price_items_count ?? 0) > 0) { $warnings[] = 'قیمت صفر'; }
        if (abs($total - $snapshotTotal) > 1) { $warnings[] = 'مغایرت مبلغ'; }
        if ($paid > $total) { $warnings[] = 'پرداخت اضافه'; }
        if (blank($invoice->uuid)) { $warnings[] = 'شماره نامعتبر'; }
        if (in_array((string) $invoice->status, $this->invoiceLegacyStatuses(), true)) { $warnings[] = 'وضعیت قدیمی'; }
        if ((int) ($invoice->ledger_debit_count ?? 0) > 1) { $warnings[] = 'ledger مشکوک'; }
        return $warnings;
    }

    private function exportDailyCustomerFinanceCsv($invoices, Carbon $reportDate): StreamedResponse
    {
        $filename = 'daily-customer-finance-' . $reportDate->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($invoices, $reportDate) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'report_date',
                'customer_name',
                'customer_mobile',
                'invoice_number',
                'invoice_date',
                'row_type',
                'amount',
                'payment_method',
                'payment_date',
                'payment_bank_name',
                'payment_identifier',
                'cheque_number',
                'cheque_due_date',
                'cheque_received_at',
                'cheque_bank_name',
                'cheque_branch_name',
                'cheque_account_number',
                'cheque_account_holder',
                'cheque_customer_name',
                'cheque_customer_code',
                'cheque_status',
                'note',
            ]);

            foreach ($invoices as $invoice) {
                fputcsv($handle, [
                    $reportDate->toDateString(),
                    $invoice->customer_name ?? '',
                    $invoice->customer_mobile ?? '',
                    $invoice->uuid,
                    optional($invoice->created_at)->format('Y-m-d'),
                    'invoice',
                    (int) $invoice->total,
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                ]);

                foreach ($invoice->payments as $payment) {
                    $cheque = $payment->cheque;
                    fputcsv($handle, [
                        $reportDate->toDateString(),
                        $invoice->customer_name ?? '',
                        $invoice->customer_mobile ?? '',
                        $invoice->uuid,
                        optional($invoice->created_at)->format('Y-m-d'),
                        'payment',
                        (int) $payment->amount,
                        $payment->method,
                        $payment->paid_at,
                        $payment->bank_name ?? '',
                        $payment->payment_identifier ?? '',
                        $cheque?->cheque_number ?? '',
                        $cheque?->due_date ?? '',
                        $cheque?->received_at ?? '',
                        $cheque?->bank_name ?? '',
                        $cheque?->branch_name ?? '',
                        $cheque?->account_number ?? '',
                        $cheque?->account_holder ?? '',
                        $cheque?->customer_name ?? '',
                        $cheque?->customer_code ?? '',
                        $cheque?->status ?? '',
                        $payment->note ?? '',
                    ]);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
