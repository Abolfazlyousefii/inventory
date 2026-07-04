<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\WarehouseStock;
use App\Services\SalesHavalehStatusService;
use App\Services\SalesHavalehService;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesPrintDocumentService;
use App\Services\WarehousePendingRefreshService;
use App\Services\WarehouseStockService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly SalesHavalehStatusService $statusService,
        private readonly SalesHavalehService $salesHavalehService,
        private readonly SalesDocumentAccessService $accessService,
        private readonly WarehousePendingRefreshService $warehousePendingRefreshService,
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request)
    {
        [
            'filters' => $filters,
            'filterErrors' => $filterErrors,
            'baseQuery' => $baseQuery,
            'allowedStatuses' => $allowedStatuses,
        ] = $this->buildInvoicesReportContext($request);

        if (in_array($request->input('export'), ['csv', 'excel', 'daily_csv', 'pdf'], true)) {
            abort_unless($this->canHandleFinanceActions(), 403);

            $exportInvoices = (clone $baseQuery)
                ->with(['customer:id,crm_customer_id,first_name,last_name,mobile', 'preinvoiceOrder.creator:id,name'])
                ->orderByDesc('id')
                ->get();

            if ($request->input('export') === 'pdf') {
                return $this->exportInvoiceReportPdf($exportInvoices, $filters);
            }

            return $this->exportInvoiceReport($exportInvoices, $request->input('export'));
        }

        $summary = $this->invoiceReportSummary(clone $baseQuery);

        $invoices = (clone $baseQuery)
            ->with(['payments.cheque', 'customer:id,crm_customer_id,first_name,last_name,mobile', 'preinvoiceOrder.creator:id,name'])
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

        return view('invoices.index', compact('invoices', 'q', 'statusLabels', 'dateInput', 'filters', 'reportDateInput', 'canRegisterPayments', 'summary', 'pageTotals', 'filterErrors', 'allowedStatuses'));
    }

    private function buildInvoicesReportContext(Request $request): array
    {
        $allowedPaymentStatuses = ['paid', 'partial', 'unpaid'];
        $allowedStatuses = $this->statusService->manualStatuses();

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

        return [
            'filters' => $filters,
            'filterErrors' => $filterErrors,
            'baseQuery' => $this->invoiceReportQuery($filters, $dateFrom, $dateTo),
            'allowedStatuses' => $allowedStatuses,
        ];
    }

    public function salesVouchers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $allowedStatuses = $this->statusService->manualStatuses();

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
            ->orderBy('status_changed_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('vouchers.sales.queue', [
            'invoices' => $invoices,
            'statusLabels' => $this->statusService->labels(),
            'queueStatuses' => $this->queueStatuses(),
            'title' => 'حواله‌های آماده جمع‌آوری',
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
            'isShippedPage' => true,
        ]);
    }

    public function salesQueueData(Request $request)
    {
        $invoices = $this->salesQueueQuery(false)->orderBy('status_changed_at')->orderBy('id')->limit(100)->get();

        return response()->json([
            'rows' => $invoices->map(fn (Invoice $invoice) => [
                'uuid' => $invoice->uuid,
                'customer_name' => $invoice->customer_name,
                'customer_mobile' => $invoice->customer_mobile,
                'items_count' => (int) $invoice->items->sum('quantity'),
                'total' => (int) $invoice->total,
                'status' => $invoice->status,
                'status_label' => $this->statusService->labels()[$invoice->status] ?? $invoice->status,
                'created_at' => \App\Support\JalaliDate::dateTime($invoice->display_document_date),
                'updated_at' => optional($invoice->updated_at)->format('Y-m-d H:i'),
                'seller' => $invoice->preinvoiceOrder?->creator?->name,
                'show_url' => route('vouchers.sales.show', $invoice->uuid),
                'edit_url' => route('vouchers.sales.edit', $invoice->uuid),
                'print_url' => route('vouchers.sales.print', $invoice->uuid),
                'history_url' => route('vouchers.sales.history', $invoice->uuid),
                'transfer_url' => route('vouchers.sales.transfer-to-warehouse', $invoice->uuid),
                'csrf' => csrf_token(),
            ])->values(),
        ]);
    }

    private function salesQueueQuery(bool $shipped)
    {
        return Invoice::query()
            ->with(['items.product', 'items.variant', 'preinvoiceOrder.creator:id,name'])
            ->when(
                $shipped,
                fn ($query) => $query->where('status', SalesHavalehStatusService::SHIPPED),
                fn ($query) => $query
                    ->whereIn('status', $this->queueStatuses())
                    ->where(function ($query) {
                        $query->whereNull('collection_status')
                            ->orWhere('collection_status', '!=', Invoice::COLLECTION_STATUS_COMPLETED);
                    })
            );
    }

    private function queueStatuses(): array
    {
        return [
            SalesHavalehStatusService::COLLECTING,
            SalesHavalehStatusService::CHECKING_DISCREPANCY,
            SalesHavalehStatusService::FINAL_CHECK,
            SalesHavalehStatusService::PACKING,
        ];
    }


    public function transferCollectionToWarehouse(string $uuid, Request $request)
    {
        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();

        if ((string) $invoice->collection_status === Invoice::COLLECTION_STATUS_COMPLETED) {
            return redirect()->route('vouchers.sales.queue')
                ->with('warning', 'این فاکتور قبلاً از صف جمع‌آوری خارج شده است.');
        }

        $updatedInvoice = $this->salesHavalehService->completeCollectionAndTransferToWarehouse($invoice, auth()->id());

        $this->notificationService->notifyRoles(
            ['warehouse', 'Warehouse', 'StorageManager'],
            'invoice_collection_completed',
            'فاکتور آماده مرحله بعد است',
            "فاکتور شماره {$updatedInvoice->uuid} جمع‌آوری و چک شد و آماده بررسی مرحله بعد است.",
            route('vouchers.sales.edit', $updatedInvoice->uuid),
            [
                'level' => 'success',
                'notifiable_type' => Invoice::class,
                'notifiable_id' => $updatedInvoice->id,
                'unique_key' => "invoice_collection_completed:{$updatedInvoice->id}",
            ]
        );

        return redirect()->route('vouchers.sales.queue')
            ->with('success', '✅ فاکتور جمع‌آوری و چک شد و به انبار منتقل شد.');
    }

    public function salesVoucherShow(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant', 'histories.actor', 'notes'])
            ->where('uuid', $uuid)
            ->firstOrFail();

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
        $canEditItems = $this->statusService->isEditable($invoice, auth()->user());
        $stockLimits = $this->salesVoucherStockLimits($invoice);

        return view('vouchers.sales.edit', compact('invoice', 'statusLabels', 'canEditItems', 'stockLimits'));
    }

    public function salesVoucherUpdate(string $uuid, Request $request)
    {
        $invoice = Invoice::query()->with('items')->where('uuid', $uuid)->firstOrFail();

        $normalizedItems = collect($request->input('items', []))->map(function (array $row) {
            $row['quantity'] = $this->normalizeIntegerInput($row['quantity'] ?? 0);
            $row['price'] = $this->normalizeIntegerInput($row['price'] ?? 0);
            return $row;
        })->values()->all();
        $request->merge(['items' => $normalizedItems]);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.sort_order' => 'nullable|integer|min:0',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|integer|min:0',
            'change_reason' => ['nullable', 'string', 'max:100', Rule::in(['price_correction', 'customer_quantity_change', 'item_removed', 'item_added', 'warehouse_correction', 'other', 'physical_shortage', 'customer_cancelled', 'wrong_item', 'finance_correction', 'replacement'])],
            'change_note' => 'nullable|string|max:2000',
        ]);

        try {
            $this->salesHavalehService->updateItemsForInvoice($invoice, $data['items'], (int) auth()->id(), $data['change_reason'] ?? '', $data['change_note'] ?? null);
        } catch (ValidationException $e) {
            throw $e;
        }

        return redirect()->route('vouchers.sales.edit', $invoice->uuid)
            ->with('success', '✅ آیتم‌های حواله فروش با موفقیت بروزرسانی شد.');
    }

    public function salesVoucherAjaxCategories()
    {
        return response()->json(Category::query()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']));
    }

    public function salesVoucherAjaxSubcategories(Request $request)
    {
        $parentId = (int) $request->query('parent_id');
        return response()->json(Category::query()->where('parent_id', $parentId)->orderBy('name')->get(['id', 'name']));
    }

    public function salesVoucherAjaxProducts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $categoryId = (int) $request->query('category_id');

        $products = Product::query()
            ->when($categoryId > 0, fn ($query) => $query->whereIn('category_id', Category::selfAndDescendantIds($categoryId)))
            ->when($q !== '', fn ($query) => $query->search($q))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'sku', 'code', 'barcode']);

        return response()->json($products);
    }

    public function salesVoucherAjaxProductVariants(Product $product)
    {
        $centralId = WarehouseStockService::centralWarehouseId();
        $variants = $product->variants()->active()->orderBy('variant_name')->get();
        $stocks = WarehouseStock::query()
            ->where('warehouse_id', $centralId)
            ->where('product_id', $product->id)
            ->whereIn('product_variant_id', $variants->pluck('id'))
            ->pluck('quantity', 'product_variant_id');

        return response()->json($variants->map(fn ($variant) => [
            'id' => (int) $variant->id,
            'name' => (string) ($variant->variant_name ?: $product->name),
            'variant_code' => (string) ($variant->variant_code ?? ''),
            'sell_price' => (int) ($variant->sell_price ?? 0),
            'available_stock' => max(0, (int) ($stocks[$variant->id] ?? 0)),
            'max_allowed' => max(0, (int) ($stocks[$variant->id] ?? 0)),
        ])->values());
    }


    private function salesVoucherStockLimits(Invoice $invoice): array
    {
        $variantIds = $invoice->items
            ->pluck('variant_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            return [];
        }

        $centralId = WarehouseStockService::centralWarehouseId();
        $stocks = WarehouseStock::query()
            ->where('warehouse_id', $centralId)
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('quantity', 'product_variant_id');

        $oldTotals = $invoice->items
            ->groupBy(fn ($item) => (int) $item->variant_id)
            ->map(fn ($items) => (int) $items->sum('quantity'));

        return $variantIds->mapWithKeys(function (int $variantId) use ($stocks, $oldTotals) {
            $freeStock = max(0, (int) ($stocks[$variantId] ?? 0));
            $currentTotal = (int) ($oldTotals[$variantId] ?? 0);

            return [$variantId => [
                'free_stock' => $freeStock,
                'current_total' => $currentTotal,
                'max_allowed' => $currentTotal + $freeStock,
            ]];
        })->all();
    }

    private function normalizeIntegerInput($value): int
    {
        $normalized = strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ',' => '', '٬' => '', ' ' => '', "\u{00A0}" => '',
        ]);

        return (int) preg_replace('/[^0-9]/', '', $normalized);
    }

    public function salesVoucherHistory(string $uuid)
    {
        $invoice = Invoice::query()->with('histories.actor')->where('uuid', $uuid)->firstOrFail();

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

    public function edit(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant', 'customer'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($this->canHandleFinanceActions(), 403);

        $statusLabels = $this->statusService->labels();

        return view('invoices.edit', compact('invoice', 'statusLabels'));
    }

    public function update(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $invoice = Invoice::query()->with(['items', 'preinvoiceOrder:id,created_by'])->where('uuid', $uuid)->firstOrFail();

        $normalizedItems = collect($request->input('items', []))->map(function (array $row) {
            $row['quantity'] = $this->normalizeIntegerInput($row['quantity'] ?? 0);
            $row['price'] = $this->normalizeIntegerInput($row['price'] ?? 0);
            $row['line_discount_amount'] = $this->normalizeIntegerInput($row['line_discount_amount'] ?? 0);
            return $row;
        })->values()->all();
        $request->merge([
            'items' => $normalizedItems,
            'discount_amount' => $this->normalizeIntegerInput($request->input('discount_amount', 0)),
            'shipping_price' => $this->normalizeIntegerInput($request->input('shipping_price', 0)),
        ]);

        $data = $request->validate([
            'discount_amount' => 'nullable|integer|min:0',
            'shipping_price' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'required_without:items.*.id|nullable|exists:products,id',
            'items.*.variant_id' => 'required_without:items.*.id|nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|integer|min:0',
            'items.*.line_discount_amount' => 'nullable|integer|min:0',
            'edit_reason' => 'required|string|max:2000',
        ], [
            'edit_reason.required' => 'ثبت یادداشت ویرایش فاکتور برای مالی الزامی است.',
        ]);

        $beforeAudit = [
            'invoice' => $invoice->only(['customer_name', 'customer_mobile', 'customer_address', 'subtotal', 'discount_amount', 'shipping_price', 'total', 'status']),
            'items' => $invoice->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_discount_amount', 'line_total'])->values()->all(),
        ];

        $fresh = $this->salesHavalehService->updateInvoiceByFinance(
            $invoice,
            $data,
            $data['items'],
            (int) auth()->id(),
            $data['edit_reason']
        );

        DB::table('invoice_edit_audits')->insert([
            'invoice_id' => $invoice->id,
            'user_id' => auth()->id(),
            'reason' => $data['edit_reason'],
            'changes_before' => json_encode($beforeAudit, JSON_UNESCAPED_UNICODE),
            'changes_after' => json_encode([
                'invoice' => $fresh->only(['customer_name', 'customer_mobile', 'customer_address', 'subtotal', 'discount_amount', 'shipping_price', 'total', 'status']),
                'items' => $fresh->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_discount_amount', 'line_total'])->values()->all(),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!empty($fresh->preinvoiceOrder?->created_by)) {
            $this->notificationService->notifyUser(
                (int) $fresh->preinvoiceOrder->created_by,
                'invoice_finance_edited',
                'فاکتور شما توسط مالی ویرایش شد',
                "فاکتور شماره {$fresh->uuid} برای مشتری {$fresh->customer_name} توسط مالی ویرایش شد.",
                route('invoices.show', $fresh->uuid),
                ['level' => 'warning', 'notifiable_type' => Invoice::class, 'notifiable_id' => $fresh->id, 'unique_key' => "seller_invoice_finance_edited:{$fresh->id}:" . md5($data['edit_reason'] . '|' . $fresh->updated_at)]
            );
        }

        return redirect()->route('invoices.show', $invoice->uuid)->with('success', '✅ فاکتور با یادداشت مالی ذخیره شد؛ موجودی و گردش حساب مشتری بروزرسانی شد.');
    }

    public function print(string $uuid, Request $request, SalesPrintDocumentService $printService)
    {
        $invoice = Invoice::query()
            ->with([
                'items.product',
                'items.variant',
                'preinvoiceOrder.creator',
                'shippingMethod:id,name,price',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

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
                'preinvoiceOrder.creator:id,name',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $canFinanceApprove = $this->canHandleFinanceActions();
        $statusLabels = $this->statusService->labels();

        return view('invoices.show', compact('invoice', 'canFinanceApprove', 'statusLabels'));
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole(['admin', 'Admin', 'Manager', 'manager', 'finance', 'Accountant']) || $user->can('finance.approve'));
    }

    public function updateStatus(string $uuid, Request $request)
    {
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in($this->statusService->manualStatuses())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updatedInvoice = $this->salesHavalehService->changeStatus($invoice, $data['status'], $data['note'] ?? null, auth()->id());
        $this->notifySellerForInvoiceStatus($updatedInvoice, $data['status'], $data['note'] ?? null);
        $this->notifyDepartmentsForInvoiceStatus($updatedInvoice, $data['status'], $data['note'] ?? null);

        if ((string) $updatedInvoice->status === Invoice::STATUS_SHIPPED) {
            return redirect()->route('vouchers.sales.queue')
                ->with('success', '✅ حواله ارسال شد و از صف جمع‌آوری خارج شد.');
        }

        return redirect()->route('vouchers.sales.edit', $updatedInvoice->uuid)
            ->with('success', '✅ وضعیت حواله بروزرسانی شد.');
    }

    private function notifySellerForInvoiceStatus(Invoice $invoice, string $status, ?string $note = null): void
    {
        $invoice->loadMissing('preinvoiceOrder:id,created_by,uuid,customer_name');
        $sellerId = (int) ($invoice->preinvoiceOrder?->created_by ?? 0);
        if ($sellerId <= 0) {
            return;
        }

        $labels = $this->statusService->labels();
        $statusLabel = $labels[$status] ?? $status;
        $this->notificationService->notifyUser(
            $sellerId,
            'invoice_status_changed',
            'وضعیت فاکتور شما تغییر کرد',
            "فاکتور شماره {$invoice->uuid} برای مشتری {$invoice->customer_name} به وضعیت «{$statusLabel}» تغییر کرد." . ($note ? " توضیح: {$note}" : ''),
            route('invoices.show', $invoice->uuid),
            ['level' => in_array($status, [Invoice::STATUS_NOT_SHIPPED, SalesHavalehStatusService::NOT_SHIPPED], true) ? 'danger' : 'info', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "seller_invoice_status:{$invoice->id}:{$status}:{$sellerId}"]
        );
    }

    private function notifyDepartmentsForInvoiceStatus(Invoice $invoice, string $status, ?string $note = null): void
    {
        $labels = $this->statusService->labels();
        $statusLabel = $labels[$status] ?? $status;
        $message = "فاکتور شماره {$invoice->uuid} برای مشتری {$invoice->customer_name} به وضعیت «{$statusLabel}» تغییر کرد." . ($note ? " توضیح: {$note}" : '');

        if ($status === Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL) {
            $this->notificationService->notifyRoles(['warehouse', 'Warehouse', 'StorageManager'], 'invoice_pending_warehouse_approval', 'فاکتور نیازمند تایید انبار است', $message, route('vouchers.sales.edit', $invoice->uuid), ['level' => 'warning', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "warehouse_invoice_status:{$invoice->id}:{$status}"]);
        }

        if ($status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL) {
            $this->notificationService->notifyRoles(['finance', 'Accountant'], 'invoice_pending_finance_approval', 'فاکتور نیازمند تایید مالی است', $message, route('invoices.show', $invoice->uuid), ['level' => 'warning', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "finance_invoice_status:{$invoice->id}:{$status}"]);
        }

        if ($status === Invoice::STATUS_SHIPPED) {
            $this->notificationService->notifyRoles(['finance', 'Accountant', 'manager', 'Manager'], 'invoice_shipped', 'فاکتور ارسال شد', $message, route('invoices.show', $invoice->uuid), ['level' => 'success', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "departments_invoice_status:{$invoice->id}:{$status}"]);
        }
    }

    public function cancel(string $uuid, Request $request)
    {
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);
        $updatedInvoice = $this->salesHavalehService->cancelAndRestore($invoice, $data['note'] ?? null, auth()->id());
        $this->notifySellerForInvoiceStatus($updatedInvoice, SalesHavalehStatusService::NOT_SHIPPED, $data['note'] ?? null);
        $this->notificationService->notifyRoles(['finance', 'Accountant', 'warehouse', 'Warehouse', 'StorageManager', 'manager', 'Manager'], 'invoice_cancelled', 'فاکتور کنسل شد', "فاکتور شماره {$updatedInvoice->uuid} کنسل شد و اثر مالی/موجودی اصلاح شد." . (!empty($data['note']) ? ' توضیح: ' . $data['note'] : ''), route('invoices.show', $updatedInvoice->uuid), ['level' => 'danger', 'notifiable_type' => Invoice::class, 'notifiable_id' => $updatedInvoice->id, 'unique_key' => "departments_invoice_cancelled:{$updatedInvoice->id}"]);

        if ((string) $invoice->status === SalesHavalehStatusService::NOT_SHIPPED) {
            return back()->with('success', 'این فاکتور قبلاً کنسل شده و عملیات برگشت موجودی/مالی دوباره انجام نشد.');
        }

        return back()->with('success', '✅ فاکتور کنسل شد، موجودی به انبار برگشت و اثر مالی اصلاح شد.');
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

    private function invoiceReportQuery(array $filters, ?Carbon $dateFrom, ?Carbon $dateTo)
    {
        $query = Invoice::query()
            ->select('invoices.*')
            ->where('invoices.status', '!=', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
            ->selectSub('select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id', 'paid_total')
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
            ->when($filters['min_amount'] !== '', fn ($q) => $q->where('total', '>=', (int) $filters['min_amount']))
            ->when($filters['max_amount'] !== '', fn ($q) => $q->where('total', '<=', (int) $filters['max_amount']));

        if ($dateFrom) {
            $query->where('document_date', '>=', $dateFrom->copy()->startOfDay());
        }
        if ($dateTo) {
            $query->where('document_date', '<=', $dateTo->copy()->endOfDay());
        }

        $paidExpr = '(select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id)';
        if ($filters['only_remaining'] === '1') {
            $query->whereRaw("(invoices.total - {$paidExpr}) > 0");
        }
        if ($filters['only_paid'] === '1') {
            $query->whereRaw("(invoices.total - {$paidExpr}) <= 0");
        }
        match ($filters['payment_status']) {
            'paid' => $query->whereRaw("(invoices.total - {$paidExpr}) <= 0"),
            'partial' => $query->whereRaw("{$paidExpr} > 0 and (invoices.total - {$paidExpr}) > 0"),
            'unpaid' => $query->whereRaw("{$paidExpr} = 0 and invoices.total > 0"),
            default => null,
        };

        return $query;
    }

    private function invoiceReportSummary($query): array
    {
        $rows = DB::query()->fromSub($query->toBase(), 'invoice_report')->selectRaw('
            count(*) as invoice_count,
            coalesce(sum(total), 0) as total_sales,
            coalesce(sum(paid_total), 0) as paid_amount,
            coalesce(sum(greatest(total - paid_total, 0)), 0) as remaining_amount,
            coalesce(sum(case when (total - paid_total) <= 0 then 1 else 0 end), 0) as paid_count,
            coalesce(sum(case when paid_total > 0 and (total - paid_total) > 0 then 1 else 0 end), 0) as partial_count,
            coalesce(sum(case when paid_total = 0 and total > 0 then 1 else 0 end), 0) as unpaid_count
        ')->first();

        return [
            'invoice_count' => (int) ($rows->invoice_count ?? 0),
            'total_sales' => (int) ($rows->total_sales ?? 0),
            'paid_amount' => (int) ($rows->paid_amount ?? 0),
            'remaining_amount' => (int) ($rows->remaining_amount ?? 0),
            'paid_count' => (int) ($rows->paid_count ?? 0),
            'partial_count' => (int) ($rows->partial_count ?? 0),
            'unpaid_count' => (int) ($rows->unpaid_count ?? 0),
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

    private function exportInvoiceReportPdf(Collection $invoices, array $filters)
    {
        $rows = $this->invoiceReportPdfRows($invoices);
        $totals = [
            'count' => $rows->count(),
            'total' => (int) $rows->sum('total'),
            'paid' => (int) $rows->sum('paid'),
            'remaining' => (int) $rows->sum('remaining'),
        ];
        $html = View::make('invoices.report-pdf', [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $this->activeInvoiceReportFilterLabels($filters),
            'generatedAt' => \App\Support\JalaliDate::dateTime(now()),
        ])->render();

        $filename = 'invoices-report-' . now()->format('Y-m-d') . '.pdf';

        if (class_exists(\Mpdf\Mpdf::class)) {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4-L',
                'directionality' => 'rtl',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'default_font' => 'dejavusans',
                'margin_top' => 10,
                'margin_right' => 8,
                'margin_bottom' => 10,
                'margin_left' => 8,
            ]);
            $mpdf->SetTitle('گزارش فاکتورهای فروش');
            $mpdf->WriteHTML($html);

            return response($mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function invoiceReportPdfRows(Collection $invoices): Collection
    {
        $statusLabels = $this->statusService->labels();

        return $invoices->map(function (Invoice $invoice) use ($statusLabels) {
            $paid = (int) ($invoice->paid_total ?? 0);
            $total = (int) $invoice->total;
            $remaining = max($total - $paid, 0);

            return [
                'number' => $invoice->uuid,
                'date' => \App\Support\JalaliDate::date($invoice->display_document_date),
                'customer' => $invoice->customer_name ?: $invoice->customer?->display_name ?: '—',
                'code' => $invoice->customer?->crm_customer_id ?: $invoice->customer_id ?: '—',
                'mobile' => $invoice->customer_mobile ?: $invoice->customer?->mobile ?: '—',
                'total' => $total,
                'paid' => $paid,
                'remaining' => $remaining,
                'payment_status' => $this->paymentStatusLabel($paid, $total),
                'invoice_status' => $statusLabels[$invoice->status] ?? ($invoice->status ?: '—'),
                'creator' => $invoice->preinvoiceOrder?->creator?->name ?: '—',
            ];
        });
    }

    private function activeInvoiceReportFilterLabels(array $filters): array
    {
        $labels = [];
        $paymentLabels = [
            'paid' => 'تسویه‌شده',
            'partial' => 'پرداخت ناقص',
            'unpaid' => 'پرداخت‌نشده',
        ];

        if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
            $labels['بازه تاریخ'] = trim(($filters['date_from'] ?: 'ابتدا') . ' تا ' . ($filters['date_to'] ?: 'امروز'));
        }
        if (($filters['payment_status'] ?? '') !== '') {
            $labels['وضعیت پرداخت'] = $paymentLabels[$filters['payment_status']] ?? $filters['payment_status'];
        }
        if (($filters['invoice_number'] ?? '') !== '') {
            $labels['شماره فاکتور'] = $filters['invoice_number'];
        }
        if (($filters['customer_name'] ?? '') !== '') {
            $labels['مشتری'] = $filters['customer_name'];
        }
        if (($filters['customer_mobile'] ?? '') !== '') {
            $labels['موبایل'] = $filters['customer_mobile'];
        }
        if (($filters['seller'] ?? '') !== '') {
            $labels['ثبت‌کننده/فروشنده'] = $filters['seller'];
        }

        return $labels;
    }

    private function exportInvoiceReport(Collection $invoices, string $exportType)
    {
        $filename = 'invoices-report-' . now()->format('Y-m-d');

        if ($exportType === 'excel' && class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
            $export = new class($this->invoiceReportExportRows($invoices)) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
                public function __construct(private readonly Collection $rows) {}

                public function collection(): Collection
                {
                    return $this->rows;
                }

                public function headings(): array
                {
                    return [
                        'شماره فاکتور',
                        'ثبت‌کننده فاکتور',
                        'مشتری',
                        'مبلغ فاکتور',
                        'وضعیت فاکتور',
                    ];
                }
            };

            return \Maatwebsite\Excel\Facades\Excel::download($export, $filename . '.xlsx');
        }

        return $this->exportInvoiceReportCsv($invoices, $filename . '.csv');
    }

    private function invoiceReportExportRows(Collection $invoices): Collection
    {
        $statusLabels = $this->statusService->labels();

        return $invoices->map(fn (Invoice $invoice) => [
            $invoice->uuid,
            $invoice->preinvoiceOrder?->creator?->name ?: '—',
            $invoice->customer_name ?: $invoice->customer?->display_name ?: '—',
            (int) $invoice->total,
            $statusLabels[$invoice->status] ?? ($invoice->status ?: '—'),
        ]);
    }

    private function exportInvoiceReportCsv(Collection $invoices, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($invoices) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'شماره فاکتور',
                'ثبت‌کننده فاکتور',
                'مشتری',
                'مبلغ فاکتور',
                'وضعیت فاکتور',
            ]);

            foreach ($this->invoiceReportExportRows($invoices) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function paymentStatusLabel(int $paid, int $total): string
    {
        $remaining = max($total - $paid, 0);
        if ($remaining <= 0) {
            return 'تسویه‌شده';
        }

        return $paid > 0 ? 'پرداخت ناقص' : 'پرداخت‌نشده';
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
                    \App\Support\JalaliDate::date($invoice->display_document_date),
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
                        \App\Support\JalaliDate::date($invoice->display_document_date),
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