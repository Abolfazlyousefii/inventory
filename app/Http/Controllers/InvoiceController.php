<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SalesHavalehStatusService;
use App\Services\SalesHavalehService;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesPrintDocumentService;
use App\Services\WarehousePendingRefreshService;
use App\Services\WarehouseCollectionService;
use App\Services\CustomerLedgerService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    ) {}

    public function index(Request $request)
    {
        $allowedPaymentStatuses = ['paid', 'partial', 'unpaid'];
        $allowedStatuses = $this->statusService->all();

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

        $baseQuery = $this->invoiceReportQuery($filters, $dateFrom, $dateTo);

        if ($request->input('export') === 'csv' || $request->input('export') === 'excel' || $request->input('export') === 'daily_csv') {
            abort_unless($this->canHandleFinanceActions(), 403);
            return $this->exportInvoiceAccountingCsv((clone $baseQuery)->with(['customer:id,crm_customer_id,first_name,last_name,mobile', 'preinvoiceOrder.creator:id,name'])->orderByDesc('id')->get(), $filters, $request->input('export') === 'excel');
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
            ->orderBy('status_changed_at')
            ->orderBy('id')
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
                'created_at' => $invoice->created_at ? Jalalian::fromDateTime($invoice->created_at)->format('Y/m/d H:i') : null,
                'updated_at' => $invoice->updated_at ? Jalalian::fromDateTime($invoice->updated_at)->format('Y/m/d H:i') : null,
                'warehouse_received_at' => $invoice->warehouse_received_at ? Jalalian::fromDateTime($invoice->warehouse_received_at)->format('Y/m/d H:i') : null,
                'collection_started_at' => $invoice->collection_started_at ? Jalalian::fromDateTime($invoice->collection_started_at)->format('Y/m/d H:i') : null,
                'collected_at' => $invoice->collected_at ? Jalalian::fromDateTime($invoice->collected_at)->format('Y/m/d H:i') : null,
                'seller' => $invoice->preinvoiceOrder?->creator?->name,
                'show_url' => route('vouchers.sales.show', $invoice->uuid),
                'print_url' => route('vouchers.sales.print', $invoice->uuid),
                'edit_items_url' => $invoice->status === Invoice::STATUS_COLLECTING ? route('vouchers.sales.edit', $invoice->uuid) : null,
                'history_url' => route('vouchers.sales.history', $invoice->uuid),
                'receive_url' => $invoice->status === Invoice::STATUS_PENDING_COLLECTION ? route('vouchers.sales.queue.receive', $invoice->uuid) : null,
                'start_collection_url' => $invoice->status === Invoice::STATUS_WAREHOUSE_RECEIVED ? route('vouchers.sales.queue.start-collection', $invoice->uuid) : null,
                'complete_collection_url' => in_array((string) $invoice->status, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING], true) ? route('vouchers.sales.queue.complete-collection', $invoice->uuid) : null,
            ])->values(),
        ]);
    }

    private function salesQueueQuery(bool $shipped)
    {
        return Invoice::query()
            ->with(['items.product', 'items.variant', 'preinvoiceOrder.creator:id,name'])
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
        $canEditItems = in_array((string) $invoice->status, [Invoice::STATUS_WAREHOUSE_RECEIVED, Invoice::STATUS_COLLECTING], true);

        return view('vouchers.sales.edit', compact('invoice', 'statusLabels', 'canEditItems'));
    }

    public function salesVoucherUpdate(string $uuid, Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|exists:invoice_items,id',
            'items.*.invoice_item_id' => 'nullable|exists:invoice_items,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:0',
            'change_note' => 'nullable|string|max:2000',
            'collection_note' => 'nullable|string|max:2000',
        ]);

        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();
        $this->warehouseCollectionService->updateCollectedItems($invoice, $data['items'], auth()->user(), $data['collection_note'] ?? $data['change_note'] ?? null);

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
        $this->warehouseCollectionService->updateCollectedItems($invoice, $data['items'], auth()->user(), $data['collection_note'] ?? null);

        $this->notifyFinanceReapproval($invoice);

        return redirect()->route('vouchers.sales.queue')->with('success', 'تغییرات اقلام ثبت شد و فاکتور برای تایید مجدد به مالی ارجاع شد.');
    }

    public function financeReapproveInvoice(string $uuid)
    {
        $invoice = DB::transaction(function () use ($uuid) {
            $invoice = Invoice::query()->with('items')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 422, 'وضعیت فاکتور برای تایید مجدد مالی مجاز نیست.');
            abort_if($invoice->items->sum('quantity') <= 0, 422, 'فاکتور باید حداقل یک قلم کالا داشته باشد.');
            $this->customerLedgerService->syncInvoiceDebit($invoice);
            $invoice->update(['status' => Invoice::STATUS_READY_TO_SHIP, 'status_changed_at' => now(), 'status_changed_by' => auth()->id()]);
            $this->warehouseCollectionServiceHistory($invoice, 'finance_reapproved', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_READY_TO_SHIP, 'فاکتور تایید مجدد شد و به صف ارسال بار منتقل شد.');
            return $invoice;
        });

        $this->notificationService->notifyRole('warehouse', 'invoice_ready_to_ship', 'فاکتور آماده ارسال بار است', 'فاکتور شماره ' . $invoice->uuid . ' تایید مجدد مالی شد و آماده ارسال بار است.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'success', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_ready_to_ship:' . $invoice->id]);
        return redirect()->route('preinvoice.draft.index')->with('success', 'فاکتور تایید مجدد شد و به صف ارسال بار منتقل شد.');
    }

    public function financeReturnInvoiceToSales(string $uuid, Request $request)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $invoice = DB::transaction(function () use ($uuid, $data) {
            $invoice = Invoice::query()->with('preinvoiceOrder')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, 422, 'وضعیت فاکتور برای ارجاع مجاز نیست.');
            $invoice->update(['status' => Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, 'status_changed_at' => now(), 'status_changed_by' => auth()->id(), 'collection_note' => $data['reason']]);
            $this->warehouseCollectionServiceHistory($invoice, 'finance_returned_to_sales', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL, Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION, $data['reason']);
            return $invoice;
        });
        if ($invoice->preinvoiceOrder?->created_by) {
            $this->notificationService->notifyUser((int) $invoice->preinvoiceOrder->created_by, 'invoice_returned_to_sales_after_collection', 'فاکتور برای بررسی به شما ارجاع شد', 'فاکتور پس از حذف و اضافه انبار توسط مالی برای بررسی به شما ارجاع شد.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'warning', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_returned_to_sales:' . $invoice->id]);
        }
        return redirect()->route('preinvoice.draft.index')->with('success', 'فاکتور برای بررسی به اپراتور ارجاع شد.');
    }

    private function notifyFinanceReapproval(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();
        $this->notificationService->notifyRole('finance', 'invoice_pending_finance_reapproval', 'فاکتور نیازمند تایید مجدد مالی است', 'اقلام فاکتور شماره ' . $invoice->uuid . ' توسط انبار تغییر کرد و نیازمند تایید مالی است.', route('vouchers.sales.show', $invoice->uuid), ['level' => 'warning', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_pending_finance_reapproval:' . $invoice->id]);
    }

    private function warehouseCollectionServiceHistory(Invoice $invoice, string $action, string $old, string $new, string $description): void
    {
        app(\App\Services\SalesHavalehHistoryService::class)->log($invoice, $action, 'status', $old, $new, $description, auth()->id());
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
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (! $this->accessService->canSellerEditInvoiceItems($invoice, auth()->user())) {
            return redirect()->back()->with('error', 'این فاکتور قابل ویرایش توسط اپراتور نیست و ویرایش آن باید توسط واحد مالی انجام شود.');
        }

        return view('invoices.edit', compact('invoice'));
    }

    public function update(string $uuid, Request $request)
    {
        $invoice = Invoice::query()->with(['items', 'preinvoiceOrder:id,created_by'])->where('uuid', $uuid)->firstOrFail();
        if (! $this->accessService->canSellerEditInvoiceItems($invoice, auth()->user())) {
            return redirect()->back()->with('error', 'این فاکتور قابل ویرایش توسط اپراتور نیست و ویرایش آن باید توسط واحد مالی انجام شود.');
        }

        $data = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_mobile' => 'required|string|max:50',
            'customer_address' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:invoice_items,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|integer|min:0',
            'edit_reason' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::query()->with(['items', 'preinvoiceOrder'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $this->accessService->canSellerEditInvoiceItems($invoice, auth()->user())) {
                abort(403, 'این فاکتور قابل ویرایش توسط اپراتور نیست و ویرایش آن باید توسط واحد مالی انجام شود.');
            }
            $beforeAudit = [
                'invoice' => $invoice->only(['customer_name', 'customer_mobile', 'customer_address', 'subtotal', 'discount_amount', 'total', 'status']),
                'items' => $invoice->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total'])->values()->all(),
            ];

            $invoice->update([
                'customer_name' => $data['customer_name'],
                'customer_mobile' => $data['customer_mobile'],
                'customer_address' => $data['customer_address'] ?? '',
            ]);

            $this->salesHavalehService->updateItems($invoice, $data['items'], (int) auth()->id(), $data['edit_reason'], $data['edit_reason']);
            $fresh = $invoice->fresh(['items.product', 'items.variant', 'preinvoiceOrder.items']);
            DB::table('invoice_edit_audits')->insert([
                'invoice_id' => $invoice->id,
                'user_id' => auth()->id(),
                'reason' => $data['edit_reason'],
                'changes_before' => json_encode($beforeAudit, JSON_UNESCAPED_UNICODE),
                'changes_after' => json_encode([
                    'invoice' => $fresh->only(['customer_name', 'customer_mobile', 'customer_address', 'subtotal', 'discount_amount', 'total', 'status']),
                    'items' => $fresh->items->map->only(['id', 'product_id', 'variant_id', 'quantity', 'price', 'line_total'])->values()->all(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->warehousePendingRefreshService->refreshActiveWarehousePendingForDocument($fresh, 'invoice', auth()->id());
        });

        return redirect()->route('invoices.show', $invoice->uuid)->with('success', '✅ اقلام سند تغییر کرد و برای بررسی مجدد به انبار و مالی ارسال شد.');
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
            'status' => ['required', 'string', Rule::in($this->statusService->all())],
            'note' => [
                Rule::requiredIf($request->input('status') === Invoice::STATUS_SHIPPED),
                'nullable',
                'string',
                'max:1000',
            ],
        ], [
            'note.required' => 'برای ثبت وضعیت ارسال‌شده، وارد کردن یادداشت نهایی الزامی است.',
        ]);

        $updatedInvoice = $this->salesHavalehService->changeStatus($invoice, $data['status'], $data['note'] ?? null, auth()->id());

        if ((string) $updatedInvoice->status === Invoice::STATUS_SHIPPED) {
            return redirect()->route('vouchers.sales.queue')
                ->with('success', '✅ حواله ارسال شد و از صف جمع‌آوری خارج شد.');
        }

        return redirect()->route('vouchers.sales.edit', $updatedInvoice->uuid)
            ->with('success', '✅ وضعیت حواله بروزرسانی شد.');
    }

    public function cancel(string $uuid, Request $request)
    {
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);
        $this->salesHavalehService->cancelAndRestore($invoice, $data['note'] ?? null, auth()->id());

        return back()->with('success', '✅ فاکتور کنسل شد و موجودی به انبار برگشت.');
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

    private function exportInvoiceAccountingCsv($invoices, array $filters, bool $excelAlias = false): StreamedResponse
    {
        $filename = 'invoice-accounting-report-' . now()->format('Ymd-His') . ($excelAlias ? '.xls' : '.csv');

        return response()->streamDownload(function () use ($invoices) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'invoice_number', 'invoice_date', 'customer_name', 'customer_code', 'customer_mobile',
                'invoice_total', 'paid_amount', 'remaining_amount', 'payment_status', 'invoice_status', 'seller',
            ]);

            foreach ($invoices as $invoice) {
                $paid = (int) ($invoice->paid_total ?? 0);
                $remaining = max((int) $invoice->total - $paid, 0);
                fputcsv($handle, [
                    $invoice->uuid,
                    optional($invoice->created_at)->format('Y-m-d'),
                    $invoice->customer_name ?: $invoice->customer?->display_name,
                    $invoice->customer?->crm_customer_id ?: $invoice->customer_id,
                    $invoice->customer_mobile ?: $invoice->customer?->mobile,
                    (int) $invoice->total,
                    $paid,
                    $remaining,
                    $this->paymentStatusLabel($paid, (int) $invoice->total),
                    $this->statusService->labels()[$invoice->status] ?? ($invoice->status ?: ''),
                    $invoice->preinvoiceOrder?->creator?->name ?? '',
                ]);
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