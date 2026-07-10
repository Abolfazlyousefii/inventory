<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\WarehouseStock;
use App\Support\Currency;
use App\Support\IranLocations;
use App\Support\DocumentCodeGenerator;
use App\Support\ActivityLogger;
use App\Services\WarehouseReviewAuditService;
use App\Services\WarehousePendingRefreshService;
use App\Services\WarehouseStockService;
use App\Services\CentralInventoryService;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesPrintDocumentService;
use App\Services\PaymentRegistrationService;
use App\Services\NotificationService;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PreinvoiceController extends Controller
{
    public function __construct(
        private readonly PaymentRegistrationService $paymentService,
        private readonly NotificationService $notificationService,
        private readonly CentralInventoryService $centralInventoryService,
        private readonly SalesDocumentAccessService $accessService,
        private readonly WarehouseReviewAuditService $warehouseReviewAuditService,
        private readonly WarehousePendingRefreshService $warehousePendingRefreshService,
        private readonly PreinvoiceDraftReservationService $draftReservationService,
        private readonly PreinvoiceReservationService $reservationService,
    ) {}

    public function create()
    {
        return view('preinvoice.create');
    }

    public function warehouseQueue()
    {
        return $this->redirectLegacyWarehouseFlow();
    }

    public function warehouseReview(string $uuid)
    {
        return $this->redirectLegacyWarehouseFlow();

        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()
            ->with([
                'items.product:id,name',
                'items.variant:id,product_id,variant_name,stock,reserved,is_active',
                'creator:id,name',
                'warehouseReviewer:id,name',
                'reviews.user:id,name',
                'invoice:id,uuid,preinvoice_order_id,status,created_at',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        DB::transaction(function () use ($order) {
            $lockedOrder = PreinvoiceOrder::query()
                ->with('items')
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedOrder->status === PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE && $this->hasActiveFreeze($lockedOrder)) {
                $this->syncPreinvoiceReservations($lockedOrder);
            }
        });

        $order->refresh()->load([
            'items.product:id,name',
            'items.variant:id,product_id,variant_name,stock,reserved,is_active',
            'creator:id,name',
            'warehouseReviewer:id,name',
            'reviews.user:id,name',
            'invoice:id,uuid,preinvoice_order_id,status,created_at',
        ]);

        $products = Product::query()
            ->where('is_sellable', true)
            ->whereHas('variants', fn($q) => $q->where('is_active', true))
            ->with(['variants' => fn($q) => $q->where('is_active', true)->orderBy('variant_name')])
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('preinvoice.warehouse-review', compact('order', 'products'));
    }

    public function warehouseSave(string $uuid, Request $request)
    {
        return $this->redirectLegacyWarehouseFlow();

        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()->with('items')->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        $data = $this->validateWarehouseReviewPayload($request);

        DB::transaction(function () use ($order, $data) {
            $order = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);
            $this->assertWarehouseCanOnlyReduceOrDelete($order, $data['items']);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id());
            $this->validateWarehouseChangeReasons($order, $data);

            $before = $this->snapshotItems($order);
            $stockLocked = $this->hasActiveFreeze($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $this->replaceOrderItems($order, $data['items']);
            if ($stockLocked) {
                $this->syncPreinvoiceReservations($order->fresh('items'));
            }

            $order->update([
                'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                'warehouse_review_note' => $data['warehouse_review_note'] ?? null,
                'warehouse_reject_reason' => null,
                'warehouse_reviewed_by' => auth()->id(),
                'warehouse_reviewed_at' => now(),
                'total_price' => $this->calculateOrderTotal($order),
            ]);

            $after = $this->snapshotItems($order->fresh('items.product', 'items.variant'));
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'warehouse_saved',
                'reason' => $data['warehouse_review_note'] ?? null,
                'before_items' => $before,
                'after_items' => $after,
            ]);
            $this->warehouseReviewAuditService->recordItemChanges($order->fresh(), $before, $after, $this->warehouseChangeReasons($data), auth()->id());
            if (!empty($data['warehouse_review_note'])) {
                $this->warehouseReviewAuditService->log($order->fresh(), \App\Models\WarehouseReviewLog::ACTION_NOTE_ADDED, auth()->id(), $order->status, $order->status, $data['warehouse_review_note']);
            }
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit(
                    (int)$order->created_by,
                    'preinvoice_warehouse_changed',
                    'پیش‌فاکتور شما توسط انبار اصلاح شد',
                    "آیتم‌های پیش‌فاکتور مشتری {$order->customer_name} توسط انبار اصلاح شد.",
                    route('preinvoice.my.show', $order->uuid),
                    ['level' => 'warning', 'priority' => 'urgent', 'data' => ['document_type' => 'پیش‌فاکتور', 'reason' => $data['warehouse_review_note'] ?? null], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_warehouse_changed:{$order->id}:{$order->created_by}"]
                );
            }
        });

        return back()->with('success', '✅ تغییرات انبار ذخیره شد.');
    }

    public function warehouseApprove(string $uuid, Request $request)
    {
        return $this->redirectLegacyWarehouseFlow();

        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()->with('items')->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        $data = $this->validateWarehouseReviewPayload($request, true);

        DB::transaction(function () use ($order, $data) {
            $order = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);
            $this->assertWarehouseCanOnlyReduceOrDelete($order, $data['items']);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id());
            $this->validateWarehouseChangeReasons($order, $data);

            $before = $this->snapshotItems($order);
            $stockLocked = $this->hasActiveFreeze($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $this->replaceOrderItems($order, $data['items']);
            if ($stockLocked) {
                $this->syncPreinvoiceReservations($order->fresh('items'));
            }

            $order->refresh()->load('items');
            $this->assertOrderHasStock($order);

            $order->update([
                'status' => PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
                'warehouse_review_note' => $data['warehouse_review_note'] ?? null,
                'warehouse_reject_reason' => null,
                'warehouse_reviewed_by' => auth()->id(),
                'warehouse_reviewed_at' => now(),
                'total_price' => $this->calculateOrderTotal($order),
            ]);

            $after = $this->snapshotItems($order->fresh('items.product', 'items.variant'));
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'warehouse_approved',
                'reason' => $data['warehouse_review_note'] ?? null,
                'before_items' => $before,
                'after_items' => $after,
            ]);
            $this->warehouseReviewAuditService->recordItemChanges($order->fresh(), $before, $after, $this->warehouseChangeReasons($data), auth()->id());
            $this->warehouseReviewAuditService->createAfterSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id(), $data['warehouse_review_note'] ?? null);
            $this->warehouseReviewAuditService->log($order->fresh(), \App\Models\WarehouseReviewLog::ACTION_APPROVED_TO_FINANCE, auth()->id(), PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE, $data['warehouse_review_note'] ?? null);
            $this->notificationService->notifyRoleAfterCommit(
                'finance',
                'preinvoice_submitted',
                'پیش‌فاکتور جدید در انتظار تایید مالی',
                "پیش‌فاکتور مشتری {$order->customer_name} توسط انبار تایید شد و آماده بررسی مالی است.",
                route('preinvoice.draft.finance', $order->uuid),
                ['level' => 'info', 'priority' => 'important', 'data' => ['document_type' => 'پیش‌فاکتور'], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "finance_preinvoice_ready:{$order->id}"]
            );
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit(
                    (int)$order->created_by,
                    'preinvoice_warehouse_approved',
                    'پیش‌فاکتور شما توسط انبار تایید شد',
                    "پیش‌فاکتور مشتری {$order->customer_name} تایید انبار شد و وارد صف مالی شد.",
                    route('preinvoice.my.show', $order->uuid),
                    ['level' => 'success', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_warehouse_approved:{$order->id}:{$order->created_by}"]
                );
            }
        });

        return redirect()->route('preinvoice.warehouse.index')
            ->with('success', '✅ تایید انبار انجام شد و پیش‌فاکتور به صف مالی ارسال شد.');
    }

    public function warehouseReject(string $uuid, Request $request)
    {
        return $this->redirectLegacyWarehouseFlow();

        abort_unless($this->canHandleWarehouseActions(), 403);
        $order = PreinvoiceOrder::query()->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        $data = $request->validate([
            'warehouse_reject_reason' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($order, $data) {
            $order = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id());

            $order->update([
                'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                'warehouse_reject_reason' => $data['warehouse_reject_reason'],
                'warehouse_reviewed_by' => auth()->id(),
                'warehouse_reviewed_at' => now(),
            ]);
            if ($this->hasActiveFreeze($order)) {
                $this->releaseReservedStock($order);
                $order->update(['stock_released_at' => now()]);
            }
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'warehouse_rejected',
                'reason' => $data['warehouse_reject_reason'],
                'before_items' => $this->snapshotItems($order),
                'after_items' => $this->snapshotItems($order),
            ]);
            $this->warehouseReviewAuditService->createAfterSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id(), $data['warehouse_reject_reason']);
            $this->warehouseReviewAuditService->log($order->fresh(), \App\Models\WarehouseReviewLog::ACTION_REJECTED_TO_CREATOR, auth()->id(), PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE, $data['warehouse_reject_reason']);
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit((int)$order->created_by, 'preinvoice_warehouse_rejected', 'پیش‌فاکتور شما توسط انبار برگشت خورد', 'علت: ' . $data['warehouse_reject_reason'], route('preinvoice.my.show', $order->uuid), ['level' => 'danger', 'priority' => 'urgent', 'data' => ['document_type' => 'پیش‌فاکتور', 'reason' => $data['warehouse_reject_reason']], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_warehouse_rejected:{$order->id}:{$order->created_by}"]);
            }
        });

        return redirect()->route('preinvoice.warehouse.index')->with('success', '✅ پیش‌فاکتور رد شد.');
    }

    public function draftIndex(Request $request)
    {
        $orders = PreinvoiceOrder::query()
            ->where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE)
            ->with(['creator:id,name', 'customer:id,reservation_tier'])
            ->orderByDesc('id')
            ->paginate(20);

        $financeReapprovalInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
            ->with(['preinvoiceOrder.creator:id,name'])
            ->orderByDesc('items_updated_at')
            ->orderByDesc('id')
            ->get();

        $canFinanceApprove = $this->canHandleFinanceActions();

        return view('preinvoice.drafts-index', compact('orders', 'canFinanceApprove', 'financeReapprovalInvoices'));
    }

    public function allIndex(Request $request)
    {
        $status = (string) $request->query('status', '');
        $query = PreinvoiceOrder::query()->with(['creator:id,name'])->withCount('items');
        if ($status !== '') {
            $query->where('status', $status);
        }
        $orders = $query->orderByDesc('id')->paginate(30)->withQueryString();
        $statusLabels = PreinvoiceOrder::statusLabels();

        return view('preinvoice.all-index', compact('orders', 'status', 'statusLabels'));
    }


    public function myIndex(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $status = (string) $request->query('status', '');
        $query = PreinvoiceOrder::query()
            ->where('created_by', auth()->id())
            ->with([
                'customer:id,first_name,last_name,mobile',
                'items:id,preinvoice_order_id,quantity,total_price,sort_order',
                'invoice:id,uuid,preinvoice_order_id,status,total,items_updated_at,status_changed_at,status_changed_by,created_at,updated_at,customer_name,customer_mobile',
                'invoice.items:id,invoice_id,quantity,total_price,sort_order',
                'invoice.shippingMethod:id,name',
                'invoice.statusChangedByUser:id,name',
            ]);

        if ($status !== '') {
            $query->where(function ($query) use ($status) {
                $query->where('status', $status)
                    ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('status', $status));
            });
        }

        $orders = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $orders->getCollection()->transform(function (PreinvoiceOrder $order) {
            $order->setAttribute('current_document', $this->buildMyPreinvoiceSummary($order));

            return $order;
        });
        $statusLabels = array_merge($this->myPreinvoiceStatusLabels(), $this->myInvoiceStatusLabels());

        return view('preinvoice.my-index', compact('orders', 'status', 'statusLabels'));
    }

    private function buildMyPreinvoiceSummary(PreinvoiceOrder $order): array
    {
        $invoice = $order->invoice;
        $hasInvoice = $invoice !== null;
        $statusKey = $hasInvoice ? (string) $invoice->status : (string) $order->status;
        $totalAmount = $hasInvoice ? (int) ($invoice->total ?? 0) : (int) ($order->total_price ?? 0);
        $originalTotalAmount = (int) ($order->total_price ?? 0);
        $itemsCount = $hasInvoice
            ? (int) $invoice->items->sum(fn ($item) => (int) ($item->quantity ?? 0))
            : (int) $order->items->sum(fn ($item) => (int) ($item->quantity ?? 0));
        $hasItemsChanged = $hasInvoice && (
            !empty($invoice->items_updated_at)
            || in_array($invoice->status, [
                Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                Invoice::STATUS_READY_TO_SHIP,
                Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION,
            ], true)
        );

        return [
            'source' => $hasInvoice ? 'invoice' : 'preinvoice',
            'preinvoice_uuid' => $order->uuid,
            'invoice_uuid' => $invoice?->uuid,
            'invoice_number' => $invoice?->uuid,
            'status_key' => $statusKey,
            'status_label' => $hasInvoice
                ? ($this->myInvoiceStatusLabels()[$statusKey] ?? $invoice->statusLabels()[$statusKey] ?? $statusKey)
                : ($this->myPreinvoiceStatusLabels()[$statusKey] ?? $order->status_label),
            'status_group' => $hasInvoice ? 'invoice' : 'preinvoice',
            'customer_name' => $invoice?->customer_name ?: $order->customer?->display_name ?: $order->customer_name,
            'customer_mobile' => $invoice?->customer_mobile ?: $order->customer?->mobile ?: $order->customer_mobile,
            'items_count' => $itemsCount,
            'total_amount' => $totalAmount,
            'original_total_amount' => $originalTotalAmount,
            'has_invoice' => $hasInvoice,
            'has_items_changed' => $hasItemsChanged,
            'has_total_changed' => $hasInvoice && $totalAmount !== $originalTotalAmount,
            'last_changed_at' => $invoice?->items_updated_at ?: $invoice?->status_changed_at ?: $invoice?->updated_at ?: $order->updated_at,
            'next_action_label' => $this->myPreinvoiceNextActionLabel($hasInvoice, $statusKey),
            'view_url' => $hasInvoice ? route('vouchers.sales.show', $invoice->uuid) : route('preinvoice.my.show', $order->uuid),
            'edit_url' => (!$hasInvoice && in_array($order->status, [PreinvoiceOrder::STATUS_DRAFT, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED], true))
                ? route('preinvoice.draft.edit', $order->uuid)
                : null,
            'print_url' => $hasInvoice ? route('vouchers.sales.print', $invoice->uuid) : route('preinvoice.my.show', $order->uuid) . '?print=1',
        ];
    }

    private function myPreinvoiceStatusLabels(): array
    {
        return [
            PreinvoiceOrder::STATUS_DRAFT => 'پیش‌نویس',
            PreinvoiceOrder::STATUS_PENDING_FINANCE => 'در انتظار تایید مالی',
            PreinvoiceOrder::STATUS_RETURNED_TO_SALES => 'برگشت‌خورده از مالی',
            PreinvoiceOrder::STATUS_RESERVATION_EXPIRED => 'رزرو منقضی‌شده',
            PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE => 'کنسل‌شده توسط مالی',
        ];
    }

    private function myInvoiceStatusLabels(): array
    {
        return [
            Invoice::STATUS_PENDING_COLLECTION => 'در صف جمع‌آوری انبار',
            Invoice::STATUS_WAREHOUSE_RECEIVED => 'دریافت‌شده توسط انبار',
            Invoice::STATUS_COLLECTING => 'در حال جمع‌آوری',
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL => 'در انتظار تایید مجدد مالی',
            Invoice::STATUS_READY_TO_SHIP => 'آماده ارسال بار',
            Invoice::STATUS_SHIPPED => 'ارسال‌شده',
            Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION => 'برگشت‌خورده پس از جمع‌آوری',
        ];
    }

    private function myPreinvoiceNextActionLabel(bool $hasInvoice, string $status): string
    {
        if ($hasInvoice) {
            return $status === Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION ? 'مشاهده و پیگیری ارجاع' : 'مشاهده فاکتور فقط‌خواندنی';
        }

        return match ($status) {
            PreinvoiceOrder::STATUS_DRAFT => 'ویرایش / ثبت نهایی',
            PreinvoiceOrder::STATUS_RETURNED_TO_SALES => 'ویرایش و ارسال مجدد',
            PreinvoiceOrder::STATUS_RESERVATION_EXPIRED => 'ویرایش و ثبت مجدد',
            default => 'مشاهده',
        };
    }

    public function myShow(string $uuid, Request $request, SalesPrintDocumentService $printService)
    {
        abort_unless(auth()->check(), 403);

        $order = PreinvoiceOrder::query()
            ->with([
                'items.product',
                'items.variant.modelList',
                'items.variant.color',
                'creator:id,name',
                'warehouseReviewer:id,name',
                'reviews.user:id,name',
                'invoice:id,uuid,preinvoice_order_id,status,created_at',
            ])
            ->where('uuid', $uuid)
            ->where('created_by', auth()->id())
            ->firstOrFail();

        if ($request->has('print') || $request->has('mode')) {
            $printData = $printService->preinvoiceData($order, (string) $request->query('mode', $request->query('print', 'warehouse')));

            return view('prints.invoice', compact('printData'));
        }

        return view('archive.preinvoice-show', compact('order'));
    }
    public function saveDraft(Request $request)
    {
        abort_unless(auth()->check(), 403);

        $intent = (string) $request->input('intent', 'submit');
        if ($intent === 'draft') {
            return $this->saveTrueDraftFromRequest($request);
        }

        return $this->submitPreinvoiceFromRequest($request);
    }

    public function autosave(Request $request)
    {
        abort_unless(auth()->check(), 403);
        $validated = $this->validateAutosavePayload($request);
        $order = DB::transaction(function () use ($validated) {
            $order = null;
            if (! empty($validated['draft_uuid'])) {
                $order = PreinvoiceOrder::query()
                    ->where('uuid', $validated['draft_uuid'])
                    ->where('created_by', auth()->id())
                    ->where('status', PreinvoiceOrder::STATUS_DRAFT)
                    ->lockForUpdate()
                    ->first();
            }

            $order ??= PreinvoiceOrder::query()
                ->where('created_by', auth()->id())
                ->where('status', PreinvoiceOrder::STATUS_DRAFT)
                ->where('is_auto_draft', true)
                ->latest('auto_saved_at')
                ->lockForUpdate()
                ->first();

            $customer = $this->resolveCustomer($validated);
            $shippingId = $this->validatedShippingId($validated);
            $attrs = [
                'created_by' => auth()->id(),
                'status' => PreinvoiceOrder::STATUS_DRAFT,
                'customer_id' => $customer?->id,
                'is_in_person' => (bool) ($validated['is_in_person'] ?? false),
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'payment_terms_note' => $this->orderPaymentTermsNote($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),
                'shipping_id' => $shippingId,
                'shipping_price' => $shippingId ? (int) $this->resolveShippingPrice($shippingId) : 0,
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'stock_frozen_until' => null,
                'stock_released_at' => null,
                'is_auto_draft' => true,
                'auto_saved_at' => now(),
                'draft_token' => $validated['reservation_token'] ?? null,
            ];

            if (! $order) {
                $order = PreinvoiceOrder::create($attrs + [
                    'uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class),
                    'total_price' => 0,
                ]);
            } else {
                $order->update($attrs + ['total_price' => 0]);
            }

            $order->items()->delete();
            $this->syncItems($order, $validated['products'] ?? []);
            $order->update(['total_price' => $this->calculateOrderTotal($order)]);

            return $order->fresh('items.product', 'items.variant');
        });

        return response()->json(['ok' => true, 'uuid' => $order->uuid, 'saved_at' => optional($order->auto_saved_at)->toIso8601String()]);
    }

    public function latestAutosave()
    {
        abort_unless(auth()->check(), 403);
        $order = PreinvoiceOrder::query()
            ->with(['items.product:id,name,short_barcode,code,sku,price', 'items.variant:id,product_id,variant_name,stock,reserved,sell_price'])
            ->where('created_by', auth()->id())
            ->where('status', PreinvoiceOrder::STATUS_DRAFT)
            ->where('is_auto_draft', true)
            ->latest('auto_saved_at')
            ->first();

        if (! $order) {
            return response()->json(['ok' => true, 'draft' => null]);
        }

        return response()->json(['ok' => true, 'draft' => [
            'uuid' => $order->uuid,
            'saved_at' => optional($order->auto_saved_at ?? $order->updated_at)->toIso8601String(),
            'is_in_person' => (bool) $order->is_in_person,
            'customer' => ['id' => $order->customer_id, 'name' => $order->customer_name, 'mobile' => $order->customer_mobile],
            'payment_terms_note' => $order->payment_terms_note,
            'discount' => ['type' => 'amount', 'value' => (int) $order->discount_amount],
            'items' => $order->items->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'available' => (int) ($item->variant?->stock ?? 0),
                'stock_warning' => (int) ($item->variant?->stock ?? 0) < (int) $item->quantity,
                'product' => ['id' => $item->product_id, 'title' => $item->product?->name, 'sku' => $item->product?->short_barcode],
            ])->values(),
        ]]);
    }

    public function discardAutosave(string $uuid)
    {
        abort_unless(auth()->check(), 403);
        $order = PreinvoiceOrder::query()
            ->where('uuid', $uuid)
            ->where('created_by', auth()->id())
            ->where('status', PreinvoiceOrder::STATUS_DRAFT)
            ->where('is_auto_draft', true)
            ->firstOrFail();
        $order->delete();

        return response()->json(['ok' => true]);
    }

    public function heartbeatReservations(Request $request)
    {
        abort_unless(auth()->check(), 403);
        $data = $request->validate(['token' => 'required|uuid', 'browser_session_id' => 'nullable|string|max:100']);
        $count = $this->draftReservationService->heartbeat($data['token'], (int) auth()->id(), $data['browser_session_id'] ?? null);

        return response()->json(['ok' => true, 'updated' => $count]);
    }

    public function releaseReservationToken(Request $request)
    {
        abort_unless(auth()->check(), 403);
        $data = $request->validate(['token' => 'nullable|uuid', 'reservation_token' => 'nullable|uuid']);
        $token = $data['token'] ?? $data['reservation_token'] ?? null;
        if (! $token) {
            return response()->json(['ok' => true, 'released' => []]);
        }

        return response()->json(['ok' => true] + $this->draftReservationService->releaseTokenReservations($token, (int) auth()->id(), 'temporary_session_lost', 'صفحه پیش‌فاکتور بسته یا رفرش شد؛ رزرو موقت آزاد شد.'));
    }

    private function submitPreinvoiceFromRequest(Request $request)
    {
        $validated = $this->validateDraftPayload($request);

        $reservationMeta = DB::transaction(function () use ($validated) {
            $customer = $this->resolveCustomer($validated);
            $shippingId = $this->validatedShippingId($validated);
            $reservationMeta = $this->reservationExpirationForCustomer($customer);

            $order = $this->editableAutosaveOrder($validated['autosave_uuid'] ?? null);
            $orderAttrs = [
                'created_by' => auth()->id(),
                'status' => PreinvoiceOrder::STATUS_PENDING_FINANCE,

                'customer_id' => $customer?->id,
                'is_in_person' => (bool) ($validated['is_in_person'] ?? false),
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'payment_terms_note' => $this->orderPaymentTermsNote($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),

                'shipping_id' => $shippingId,
                'shipping_price' => $shippingId ? (int) $this->resolveShippingPrice($shippingId) : 0,
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'total_price' => 0,
                'stock_frozen_until' => null,
                'stock_released_at' => null,
                'is_auto_draft' => false,
            ];
            if ($order) {
                $order->items()->delete();
                $order->update($orderAttrs);
            } else {
                $order = PreinvoiceOrder::create($orderAttrs + ['uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class)]);
            }

            $this->syncItems($order, $validated['products']);
            $this->finalizeDraftReservations($order, $validated['reservation_token'] ?? null, $validated['products'], $reservationMeta);
            $this->syncPreinvoiceReservations($order, true, $reservationMeta);
            $order->update([
                'total_price' => $this->calculateOrderTotal($order),
                'stock_frozen_until' => $reservationMeta['expires_at'],
                'stock_released_at' => null,
            ]);

            $this->notificationService->notifyRoleAfterCommit(
                'finance',
                'preinvoice_submitted',
                'پیش‌فاکتور جدید در انتظار تایید مالی',
                "پیش‌فاکتور مشتری {$order->customer_name} با مبلغ " . Currency::formatRialNumber($order->total_price) . " ریال ثبت نهایی شد و آماده بررسی مالی است.",
                route('preinvoice.draft.finance', $order->uuid),
                ['level' => 'info', 'priority' => 'important', 'data' => ['document_type' => 'پیش‌فاکتور'], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "finance_preinvoice_ready:{$order->id}"]
            );

            return $reservationMeta;
        });

        return redirect()->route('preinvoice.create')
            ->with('success', $this->finalSubmitSuccessMessage($reservationMeta));
    }

    private function saveTrueDraftFromRequest(Request $request)
    {
        $validated = $this->validateDraftPayload($request, false);

        $order = DB::transaction(function () use ($validated) {
            $customer = $this->resolveCustomer($validated);
            $shippingId = $this->validatedShippingId($validated);

            $order = $this->editableAutosaveOrder($validated['autosave_uuid'] ?? null);
            $orderAttrs = [
                'created_by' => auth()->id(),
                'status' => PreinvoiceOrder::STATUS_DRAFT,

                'customer_id' => $customer?->id,
                'is_in_person' => (bool) ($validated['is_in_person'] ?? false),
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'payment_terms_note' => $this->orderPaymentTermsNote($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),

                'shipping_id' => $shippingId,
                'shipping_price' => $shippingId ? (int) $this->resolveShippingPrice($shippingId) : 0,
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'total_price' => 0,
                'stock_frozen_until' => null,
                'stock_released_at' => null,
                'is_auto_draft' => false,
                'auto_saved_at' => null,
            ];
            if ($order) {
                $order->items()->delete();
                $order->update($orderAttrs);
            } else {
                $order = PreinvoiceOrder::create($orderAttrs + ['uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class)]);
            }

            $this->syncItems($order, $validated['products']);
            $order->update([
                'total_price' => $this->calculateOrderTotal($order),
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ]);

            if (! empty($validated['reservation_token'])) {
                $this->draftReservationService->releaseTokenReservations(
                    (string) $validated['reservation_token'],
                    (int) auth()->id(),
                    'save_as_draft',
                    'پیش‌فاکتور به صورت پیش‌نویس ذخیره شد؛ رزرو موقت آزاد شد.'
                );
            }

            ActivityLogger::log('preinvoice_draft_saved', $order->fresh(), 'پیش‌فاکتور به صورت پیش‌نویس ذخیره شد.', [
                'user_id' => auth()->id(),
                'preinvoice_id' => $order->id,
                'preinvoice_uuid' => $order->uuid,
                'status' => PreinvoiceOrder::STATUS_DRAFT,
            ]);

            return $order;
        });

        return redirect()->route('preinvoice.draft.edit', $order->uuid)
            ->with('success', '✅ پیش‌فاکتور به صورت پیش‌نویس ذخیره شد و موجودی رزرو نشد.');
    }

    public function editDraft(string $uuid)
    {
        $order = PreinvoiceOrder::with(['items.product:id,name,code,sku', 'items.variant:id,variant_name', 'invoice'])->where('uuid', $uuid)->firstOrFail();
        if (! $this->accessService->canSellerEditPreinvoiceItems($order, auth()->user())) {
            return redirect()->back()->with('error', 'این پیش‌فاکتور به فاکتور تبدیل شده است و فقط واحد مالی مجاز به ویرایش آن است.');
        }

        $shippingMethods = ShippingMethod::query()
            ->select(['id', 'name', 'price'])
            ->orderBy('name')
            ->get();

        $canFinanceApprove = $this->canHandleFinanceActions();
        $canEditItems = $this->accessService->canSellerEditPreinvoiceItems($order, auth()->user());

        return view('preinvoice.edit', compact('order', 'shippingMethods', 'canFinanceApprove', 'canEditItems'));
    }

    public function updateDraft(string $uuid, Request $request)
    {
        abort_unless(auth()->check(), 403);
        $order = PreinvoiceOrder::with(['items', 'invoice.items'])->where('uuid', $uuid)->firstOrFail();
        if (! $this->accessService->canSellerEditPreinvoiceItems($order, auth()->user())) {
            return redirect()->back()->with('error', 'این پیش‌فاکتور به فاکتور تبدیل شده است و فقط واحد مالی مجاز به ویرایش آن است.');
        }

        $intent = (string) $request->input('intent', 'submit');
        $isSubmit = $intent !== 'draft';

        $validated = $this->validateDraftPayload($request, $isSubmit, $order);

        $reservationMeta = DB::transaction(function () use ($order, $validated, $isSubmit) {
            $order = PreinvoiceOrder::query()
                ->with(['items', 'invoice.items'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $this->accessService->canSellerEditPreinvoiceItems($order, auth()->user())) {
                throw ValidationException::withMessages([
                    'preinvoice' => 'این پیش‌فاکتور در وضعیت قابل ویرایش یا ثبت نهایی مجدد نیست.',
                ]);
            }

            if ($isSubmit && ! in_array($order->status, [
                PreinvoiceOrder::STATUS_DRAFT,
                PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
                PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
            ], true)) {
                throw ValidationException::withMessages([
                    'preinvoice' => 'ثبت نهایی فقط برای پیش‌نویس، ارجاع‌شده به فروشنده یا رزرو منقضی‌شده مجاز است.',
                ]);
            }

            $customer = $this->resolveCustomer($validated);
            $shippingId = array_key_exists('shipping_id', $validated)
                ? $this->validatedShippingId($validated)
                : (! empty($order->shipping_id) ? (int) $order->shipping_id : null);
            $before = $this->snapshotItems($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $newItems = collect($validated['products'])->map(fn($p) => [
                'product_id' => (int) $p['id'],
                'variant_id' => (int) $p['variety_id'],
                'quantity' => (int) $p['quantity'],
            ])->all();

            $stockLocked = $this->hasActiveFreeze($order);
            if ($isSubmit && ($stockLocked || ! $order->invoice)) {
                $this->assertCentralStockForPositiveDeltas($oldItems, $newItems);
            }
            $reservationMeta = $this->reservationExpirationForCustomer($customer);

            $order->update([
                'customer_id' => $customer?->id,
                'is_in_person' => (bool) ($validated['is_in_person'] ?? false),
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'payment_terms_note' => $this->orderPaymentTermsNote($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),

                'shipping_id' => $shippingId,
                'shipping_price' => $shippingId ? (int) $this->resolveShippingPrice($shippingId) : 0,
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'total_price' => 0,
            ]);

            $this->syncItems($order, $validated['products'], true);

            if (! $isSubmit && ! empty($validated['reservation_token'])) {
                $this->draftReservationService->releaseTokenReservations(
                    (string) $validated['reservation_token'],
                    (int) auth()->id(),
                    'save_as_draft',
                    'پیش‌فاکتور به صورت پیش‌نویس ذخیره شد؛ رزرو موقت آزاد شد.'
                );
            }

            if ($isSubmit && ! $stockLocked) {
                $this->finalizeDraftReservations($order->fresh('items'), $validated['reservation_token'] ?? null, $validated['products'], $reservationMeta);
                $this->syncPreinvoiceReservations($order->fresh('items'), true, $reservationMeta);
            } elseif ($stockLocked) {
                $this->syncPreinvoiceReservations($order->fresh('items'));
            } elseif ($order->invoice) {
                $this->moveConsumedInvoiceStockBackToReservation($oldItems, $newItems);
            }

            $order->refresh()->load(['items.product', 'items.variant', 'invoice.items']);
            $oldStatus = (string) $order->status;
            $order->update([
                'status' => $isSubmit ? PreinvoiceOrder::STATUS_PENDING_FINANCE : PreinvoiceOrder::STATUS_DRAFT,
                'warehouse_review_note' => null,
                'warehouse_reject_reason' => null,
                'warehouse_reviewed_by' => null,
                'warehouse_reviewed_at' => null,
                'total_price' => $this->calculateOrderTotal($order),
                'stock_frozen_until' => $isSubmit ? $reservationMeta['expires_at'] : null,
                'stock_released_at' => null,
                'items_updated_at' => now(),
                'items_updated_by' => auth()->id(),
            ]);

            if ($isSubmit) {
                $this->notificationService->notifyRoleAfterCommit(
                    'finance',
                    'preinvoice_submitted',
                    'پیش‌فاکتور جدید در انتظار تایید مالی',
                    "پیش‌فاکتور مشتری {$order->customer_name} ثبت نهایی شد و آماده بررسی مالی است.",
                    route('preinvoice.draft.finance', $order->uuid),
                    ['level' => 'info', 'priority' => 'important', 'data' => ['document_type' => 'پیش‌فاکتور'], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "finance_preinvoice_ready:{$order->id}"]
                );
            }

            $this->syncExistingInvoiceFromOrderForReapproval($order->fresh(['items', 'invoice.items']));

            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => $isSubmit ? 'seller_submitted_to_finance' : 'preinvoice_draft_updated',
                'reason' => $isSubmit ? 'پیش‌فاکتور ثبت نهایی شد و به صف تایید مالی رفت.' : 'پیش‌فاکتور به صورت پیش‌نویس ذخیره شد.',
                'before_items' => $before,
                'after_items' => $this->snapshotItems($order->fresh('items.product', 'items.variant')),
            ]);

            ActivityLogger::log($isSubmit ? 'preinvoice_submitted' : 'preinvoice_draft_updated', $order->fresh(), $isSubmit ? 'پیش‌فاکتور ثبت نهایی شد و به صف تایید مالی ارسال شد.' : 'پیش‌فاکتور به صورت پیش‌نویس بروزرسانی شد.', [
                'old_status' => $oldStatus,
                'new_status' => $isSubmit ? PreinvoiceOrder::STATUS_PENDING_FINANCE : PreinvoiceOrder::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            return $reservationMeta;
        });

        return back()->with('success', $isSubmit ? $this->finalSubmitSuccessMessage($reservationMeta) : '✅ پیش‌فاکتور به صورت پیش‌نویس ذخیره شد و موجودی رزرو نشد.');
    }

    private function validateDraftPayload(Request $request, bool $checkCurrentStock = true, ?PreinvoiceOrder $editingOrder = null): array
    {
        $validated = $request->validate([
            'reservation_token' => 'nullable|uuid',
            'autosave_uuid' => 'nullable|string',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'is_in_person' => 'nullable|boolean',
            'customer_name' => 'required|string|max:255',
            'customer_mobile' => 'required|string|max:20',
            'customer_address' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'payment_terms_note' => 'nullable|string|max:2000',
            'province_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',

            'shipping_id' => 'nullable|integer|exists:shipping_methods,id',
            'shipping_price' => 'nullable|integer|min:0',

            'discount_amount' => 'nullable|integer|min:0',
            'total_price' => 'nullable|integer|min:0',

            'products' => 'required|array|min:1',
            'products.*.id' => 'required|integer|exists:products,id',
            'products.*.variety_id' => ['required', 'integer', 'exists:product_variants,id'],
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.price' => 'nullable|integer|min:0',
            'products.*.item_id' => 'nullable|integer',
            'products.*.line_discount_amount' => 'nullable|integer|min:0',
        ], [
            'customer_name.required' => 'نام مشتری الزامی است.',
            'customer_mobile.required' => 'شماره موبایل مشتری الزامی است.',
            'products.required' => 'حداقل یک محصول باید ثبت شود.',
            'products.min' => 'حداقل یک محصول باید ثبت شود.',
        ]);

        $provinceId = !empty($validated['province_id']) ? (int) $validated['province_id'] : null;
        $cityId = !empty($validated['city_id']) ? (int) $validated['city_id'] : null;

        if ($provinceId && !IranLocations::provinceExists($provinceId)) {
            throw ValidationException::withMessages(['province_id' => 'استان انتخاب‌شده معتبر نیست.']);
        }

        if (!IranLocations::cityBelongsToProvince($provinceId, $cityId)) {
            throw ValidationException::withMessages(['city_id' => 'شهر انتخاب‌شده با استان انتخاب‌شده همخوانی ندارد.']);
        }

        $existingQtyByProductVariant = [];
        if ($editingOrder) {
            $editingOrder->loadMissing('items');
            foreach ($editingOrder->items as $existingItem) {
                $key = ((int) $existingItem->product_id) . ':' . ((int) $existingItem->variant_id);
                $existingQtyByProductVariant[$key] = ($existingQtyByProductVariant[$key] ?? 0) + (int) $existingItem->quantity;
            }
        }

        foreach (($validated['products'] ?? []) as $index => $productRow) {
            $productId = (int) $productRow['id'];
            $variantId = (int) $productRow['variety_id'];
            $requestedQty = (int) $productRow['quantity'];
            $existingQty = (int) ($existingQtyByProductVariant[$productId . ':' . $variantId] ?? 0);

            $product = Product::query()->whereKey($productId)->first(['id', 'is_sellable']);
            $variant = ProductVariant::query()->whereKey($variantId)->first(['id', 'product_id', 'is_active']);
            $isExistingNonIncrease = $existingQty > 0 && $requestedQty <= $existingQty;

            if (! $product || (! (bool) $product->is_sellable && ! $isExistingNonIncrease)) {
                throw ValidationException::withMessages([
                    "products.{$index}.id" => 'کالا قابل فروش نیست؛ فقط کاهش یا حذف آیتم‌های قبلی مجاز است.',
                ]);
            }

            if (! $variant || (int) $variant->product_id !== $productId || (! (bool) $variant->is_active && ! $isExistingNonIncrease)) {
                throw ValidationException::withMessages([
                    "products.{$index}.variety_id" => 'تنوع انتخابی برای این کالا نامعتبر یا غیرفعال است؛ فقط کاهش یا حذف آیتم‌های قبلی مجاز است.',
                ]);
            }
        }

        if ($checkCurrentStock) {
            $this->validateDraftItemsBusinessRules($validated['products'] ?? [], $validated['reservation_token'] ?? null);
        }

        return $validated;
    }

    private function validateAutosavePayload(Request $request): array
    {
        $validated = $request->validate([
            'draft_uuid' => 'nullable|string',
            'reservation_token' => 'nullable|uuid',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'is_in_person' => 'nullable|boolean',
            'customer_name' => 'nullable|string|max:255',
            'customer_mobile' => 'nullable|string|max:20',
            'customer_address' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'payment_terms_note' => 'nullable|string|max:2000',
            'province_id' => 'nullable|integer',
            'city_id' => 'nullable|integer',
            'shipping_id' => 'nullable|integer|exists:shipping_methods,id',
            'shipping_price' => 'nullable|integer|min:0',
            'discount_amount' => 'nullable|integer|min:0',
            'products' => 'nullable|array',
            'products.*.id' => 'required_with:products|integer|exists:products,id',
            'products.*.variety_id' => ['required_with:products', 'integer', 'exists:product_variants,id'],
            'products.*.quantity' => 'required_with:products|integer|min:0',
            'products.*.price' => 'nullable|integer|min:0',
            'products.*.line_discount_amount' => 'nullable|integer|min:0',
        ]);

        $validated['products'] = collect($validated['products'] ?? [])
            ->filter(fn ($row) => (int) ($row['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        foreach ($validated['products'] as $index => $productRow) {
            $productId = (int) $productRow['id'];
            $variantId = (int) $productRow['variety_id'];
            if (! ProductVariant::query()->whereKey($variantId)->where('product_id', $productId)->exists()) {
                throw ValidationException::withMessages(["products.{$index}.variety_id" => 'تنوع انتخابی برای این کالا معتبر نیست.']);
            }
        }

        return $validated;
    }

    private function validateDraftItemsBusinessRules(array $products, ?string $reservationToken = null): void
    {
        $variantIds = collect($products)->pluck('variety_id')->map(fn($id) => (int) $id)->filter()->values();
        if ($variantIds->isEmpty()) {
            return;
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->get(['id', 'product_id', 'sell_price', 'stock', 'reserved', 'is_active'])
            ->keyBy('id');

        $qtyByVariant = [];
        $seenProductVariant = [];

        foreach ($products as $index => $row) {
            $productId = (int) ($row['id'] ?? 0);
            $variantId = (int) ($row['variety_id'] ?? 0);
            $variant = $variants->get($variantId);
            if (!$variant || (int) $variant->product_id !== $productId || !(bool) $variant->is_active) {
                throw ValidationException::withMessages([
                    "products.{$index}.variety_id" => 'تنوع انتخابی معتبر نیست.',
                ]);
            }

            $pairKey = $productId . ':' . $variantId;
            if (isset($seenProductVariant[$pairKey])) {
                throw ValidationException::withMessages([
                    "products.{$index}.variety_id" => 'هر تنوع باید فقط یک‌بار در هر محصول مادر ثبت شود.',
                ]);
            }
            $seenProductVariant[$pairKey] = true;

            $qtyByVariant[$variantId] = ($qtyByVariant[$variantId] ?? 0) + (int) ($row['quantity'] ?? 0);
        }

        $draftReservations = $this->activeDraftReservationQuantities($reservationToken);

        foreach ($qtyByVariant as $variantId => $requiredQty) {
            $variant = $variants->get((int) $variantId);
            $availableQty = max(0, $this->centralInventoryService->availableForVariant((int) $variantId) + (int) ($draftReservations[(int) $variantId] ?? 0));

            if ($requiredQty > $availableQty) {
                throw ValidationException::withMessages([
                    'products' => "موجودی تنوع انتخابی کافی نیست. موجودی قابل فروش: {$availableQty} | درخواست: {$requiredQty}",
                ]);
            }
        }
    }

    private function validateWarehouseReviewPayload(Request $request, bool $forApprove = false): array
    {
        $validated = $request->validate([
            'warehouse_review_note' => $forApprove ? 'required|string|max:2000' : 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id,is_sellable,1',
            'items.*.variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where(fn($q) => $q->where('is_active', true))],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:0',
            'items.*.change_reason' => 'nullable|string|in:' . implode(',', array_keys(WarehouseReviewAuditService::REASONS)),
            'items.*.change_note' => 'nullable|string|max:1000',
            'removed_items' => 'nullable|array',
            'removed_items.*.product_id' => 'required_with:removed_items|integer',
            'removed_items.*.variant_id' => 'required_with:removed_items|integer',
            'removed_items.*.change_reason' => 'required_with:removed_items|string|in:' . implode(',', array_keys(WarehouseReviewAuditService::REASONS)),
            'removed_items.*.change_note' => 'nullable|string|max:1000',
        ], [
            'warehouse_review_note.required' => 'برای تایید و ارسال به مالی، دلیل/توضیح بازبینی انبار الزامی است.',
            'items.required' => 'حداقل یک آیتم در پیش‌فاکتور لازم است.',
            'items.min' => 'حداقل یک آیتم در پیش‌فاکتور لازم است.',
        ]);

        foreach (($validated['items'] ?? []) as $index => $row) {
            $isValidVariant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->where('is_active', true)
                ->exists();

            if (!$isValidVariant) {
                throw ValidationException::withMessages([
                    "items.{$index}.variant_id" => 'تنوع انتخابی برای کالا معتبر نیست.',
                ]);
            }
        }

        return $validated;
    }

    private function validateWarehouseChangeReasons(PreinvoiceOrder $order, array $data): void
    {
        $oldMap = $this->itemQuantityMap($order->items->map(fn($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
        ])->all());
        $newMap = $this->itemQuantityMap($data['items'] ?? []);
        $reasons = $this->warehouseChangeReasons($data);

        foreach ($oldMap as $key => $oldQty) {
            $newQty = (int) ($newMap[$key] ?? 0);
            if ($newQty >= (int) $oldQty) {
                continue;
            }

            $reason = trim((string) ($reasons[$key]['reason'] ?? ''));
            $note = trim((string) ($reasons[$key]['note'] ?? ''));

            if ($reason === '') {
                throw ValidationException::withMessages(['items' => 'برای کاهش تعداد یا حذف کالا، انتخاب دلیل الزامی است.']);
            }

            if ($reason === 'other' && $note === '') {
                throw ValidationException::withMessages(['items' => 'وقتی دلیل «سایر» انتخاب می‌شود، توضیح متنی الزامی است.']);
            }
        }
    }

    private function warehouseChangeReasons(array $data): array
    {
        $reasons = [];

        foreach (($data['items'] ?? []) as $row) {
            $key = ((int) ($row['product_id'] ?? 0)) . ':' . ((int) ($row['variant_id'] ?? 0));
            $reasons[$key] = [
                'reason' => $row['change_reason'] ?? null,
                'note' => $row['change_note'] ?? null,
            ];
        }

        foreach (($data['removed_items'] ?? []) as $row) {
            $key = ((int) ($row['product_id'] ?? 0)) . ':' . ((int) ($row['variant_id'] ?? 0));
            $reasons[$key] = [
                'reason' => $row['change_reason'] ?? null,
                'note' => $row['change_note'] ?? null,
            ];
        }

        return $reasons;
    }

    private function replaceOrderItems(PreinvoiceOrder $order, array $items): void
    {
        $order->items()->delete();

        foreach (array_values($items) as $index => $row) {
            $variant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->firstOrFail(['sell_price']);
            $order->items()->create([
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
                'quantity' => (int) $row['quantity'],
                'price' => (int) ($variant->sell_price ?? 0),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function snapshotItems(PreinvoiceOrder $order): array
    {
        $order->loadMissing(['items.product:id,name,code,sku,barcode', 'items.variant']);

        return $order->items->map(fn($item) => [
            'item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'product_name' => $item->product?->name,
            'variant_id' => (int) $item->variant_id,
            'variant_name' => $item->variant?->variant_name ?: $item->variant?->variety_name,
            'code' => $item->variant?->sku ?: ($item->variant?->variant_code ?: ($item->variant?->barcode ?: ($item->product?->sku ?: $item->product?->code))),
            'quantity' => (int) $item->quantity,
            'price' => (int) $item->price,
            'stock_at_review' => $item->variant ? max(0, (int) $item->variant->stock) : null,
            'available_stock_at_review' => $item->variant ? max(0, (int) $item->variant->stock - (int) $item->variant->reserved) : null,
        ])->values()->all();
    }

    private function calculateOrderTotal(PreinvoiceOrder $order): int
    {
        $subtotal = (int) $order->items()
            ->reorder()
            ->selectRaw('COALESCE(SUM(quantity * price), 0) as total')
            ->value('total');

        return max($subtotal - (int) $order->discount_amount, 0);
    }

    private function assertOrderHasStock(PreinvoiceOrder $order): void
    {
        $order->loadMissing('items.product', 'items.variant');

        $requiredByVariant = $order->items
            ->groupBy('variant_id')
            ->map(fn($rows) => (int) $rows->sum('quantity'));

        $reservedByVariant = PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
            ->whereNull('released_at')
            ->whereIn('variant_id', $requiredByVariant->keys())
            ->lockForUpdate()
            ->select('variant_id', DB::raw('SUM(quantity) as reserved_quantity'))
            ->groupBy('variant_id')
            ->pluck('reserved_quantity', 'variant_id');

        foreach ($requiredByVariant as $variantId => $requiredQty) {
            $reservedQty = (int) ($reservedByVariant[(int) $variantId] ?? 0);

            if ($reservedQty < $requiredQty) {
                $variant = ProductVariant::query()->with('product:id,name')->whereKey((int) $variantId)->first();
                $productName = (string) ($variant?->product?->name ?? 'نامشخص');
                throw ValidationException::withMessages([
                    'items' => "رزرو موجودی این پیش‌فاکتور با اقلام فعلی هماهنگ نیست. محصول «{$productName}» | رزرو شده: {$reservedQty} | درخواست: {$requiredQty}",
                ]);
            }
        }
    }

    private function assertWarehouseCanOnlyReduceOrDelete(PreinvoiceOrder $order, array $newItems): void
    {
        $oldMap = $this->itemQuantityMap($order->items->map(fn($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
        ])->all());
        $newMap = $this->itemQuantityMap($newItems);

        foreach ($newMap as $key => $newQty) {
            if (! array_key_exists($key, $oldMap)) {
                abort(403, 'انبار مجاز به افزودن کالای جدید نیست.');
            }

            if ($newQty > (int) $oldMap[$key]) {
                abort(422, 'انبار فقط مجاز به کاهش تعداد آیتم‌ها است.');
            }
        }
    }

    private function assertCentralStockForPositiveDeltas(array $oldItems, array $newItems): void
    {
        $oldMap = $this->itemQuantityMap($oldItems);
        $newMap = $this->itemQuantityMap($newItems);

        foreach ($newMap as $key => $newQty) {
            $oldQty = (int) ($oldMap[$key] ?? 0);
            $delta = (int) $newQty - $oldQty;
            if ($delta <= 0) {
                continue;
            }

            [, $variantId] = array_map('intval', explode(':', $key));
            $this->centralInventoryService->assertVariantAvailable($variantId, $delta);
        }
    }

    private function itemQuantityMap(array $items): array
    {
        $map = [];
        foreach ($items as $row) {
            $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            $variantId = (int) ($row['variant_id'] ?? $row['variety_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($productId <= 0 || $variantId <= 0 || $qty <= 0) {
                continue;
            }
            $key = $productId . ':' . $variantId;
            $map[$key] = ($map[$key] ?? 0) + $qty;
        }

        return $map;
    }

    private function syncExistingInvoiceFromOrderForReapproval(PreinvoiceOrder $order): void
    {
        $invoice = $order->invoice;
        if (! $invoice) {
            return;
        }

        $subtotal = (int) $order->items->sum(fn($it) => ((int) $it->quantity) * ((int) $it->price));
        $total = max($subtotal + (int) $order->shipping_price - (int) $order->discount_amount, 0);

        $invoice->items()->delete();
        foreach ($order->items as $item) {
            $invoice->items()->create([
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_total' => max(((int) $item->quantity * (int) $item->price) - (int) ($item->line_discount_amount ?? 0), 0),
                'sort_order' => (int) ($item->sort_order ?: 0),
                'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
            ]);
        }

        $oldStatus = (string) $invoice->status;
        $invoice->update([
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name,
            'customer_mobile' => $order->customer_mobile,
            'customer_address' => $order->customer_address,
            'province_id' => $order->province_id,
            'city_id' => $order->city_id,
            'shipping_id' => $order->shipping_id,
            'shipping_price' => (int) $order->shipping_price,
            'discount_amount' => (int) $order->discount_amount,
            'subtotal' => $subtotal,
            'total' => $total,
            'status' => Invoice::STATUS_PENDING_COLLECTION,
            'status_changed_at' => now(),
            'status_changed_by' => auth()->id(),
            'items_updated_at' => now(),
            'items_updated_by' => auth()->id(),
        ]);

        ActivityLogger::log('invoice_items_reapproval', $invoice->fresh(), 'اقلام فاکتور تغییر کرد و فاکتور به وضعیت نیازمند تایید انبار برگشت.', [
            'old_status' => $oldStatus,
            'new_status' => Invoice::STATUS_PENDING_COLLECTION,
            'preinvoice_order_id' => $order->id,
        ]);

        if (!empty($invoice->customer_id)) {
            CustomerLedger::query()->updateOrCreate(
                [
                    'customer_id' => (int) $invoice->customer_id,
                    'reference_type' => Invoice::class,
                    'reference_id' => (int) $invoice->id,
                    'type' => 'debit',
                ],
                [
                    'amount' => (int) $total,
                    'note' => 'بروزرسانی بدهکاری بابت تغییر اقلام فاکتور ' . $invoice->uuid,
                ]
            );
        }
    }

    private function resolveCustomer(array $validated): ?Customer
    {
        $cid = (int) ($validated['customer_id'] ?? 0);
        if ($cid <= 0) return null;

        return Customer::query()->find($cid);
    }

    private function editableAutosaveOrder(?string $uuid): ?PreinvoiceOrder
    {
        $uuid = trim((string) $uuid);
        if ($uuid === '') {
            return null;
        }

        return PreinvoiceOrder::query()
            ->where('uuid', $uuid)
            ->where('created_by', auth()->id())
            ->where('status', PreinvoiceOrder::STATUS_DRAFT)
            ->lockForUpdate()
            ->first();
    }

    private function orderCustomerName(array $validated, ?Customer $customer): string
    {
        $name = trim((string) ($validated['customer_name'] ?? ''));
        if ($name !== '') return $name;

        if ($customer) {
            $full = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            if ($full !== '') return $full;
        }

        return '';
    }

    private function orderCustomerMobile(array $validated, ?Customer $customer): string
    {
        $mobile = trim((string) ($validated['customer_mobile'] ?? ''));
        if ($mobile !== '') return $mobile;

        if ($customer && !empty($customer->mobile)) {
            return (string) $customer->mobile;
        }

        return '';
    }

    private function orderDescription(array $validated): ?string
    {
        $description = trim((string) ($validated['description'] ?? ''));

        return $description !== '' ? $description : null;
    }

    private function orderPaymentTermsNote(array $validated): ?string
    {
        $paymentTermsNote = trim((string) ($validated['payment_terms_note'] ?? ''));

        return $paymentTermsNote !== '' ? $paymentTermsNote : null;
    }

    private function orderCustomerAddress(array $validated, ?Customer $customer, ?int $shippingId): ?string
    {
        if ($shippingId === null || $this->isInPersonShippingId($shippingId)) {
            return null;
        }

        $address = trim((string) ($validated['customer_address'] ?? ''));
        if ($address !== '') return $address;

        if ($customer && !empty($customer->address)) {
            return (string) $customer->address;
        }

        return null;
    }

    private function orderProvinceId(array $validated, ?Customer $customer, ?int $shippingId): ?int
    {
        if ($shippingId === null || $this->isInPersonShippingId($shippingId)) {
            return null;
        }

        if (!empty($validated['province_id'])) {
            return (int) $validated['province_id'];
        }

        if ($customer && !empty($customer->province_id)) {
            return (int) $customer->province_id;
        }

        return null;
    }

    private function orderCityId(array $validated, ?Customer $customer, ?int $shippingId): ?int
    {
        if ($shippingId === null || $this->isInPersonShippingId($shippingId)) {
            return null;
        }

        if (!empty($validated['city_id'])) {
            return (int) $validated['city_id'];
        }

        if ($customer && !empty($customer->city_id)) {
            return (int) $customer->city_id;
        }

        return null;
    }


    private function validatedShippingId(array $validated): ?int
    {
        $shippingId = (int) ($validated['shipping_id'] ?? 0);

        return $shippingId > 0 ? $shippingId : null;
    }

    private function resolveShippingPrice(int $shippingId): int
    {
        return (int) ShippingMethod::query()->whereKey($shippingId)->value('price');
    }

    private function isInPersonShippingId(?int $shippingId): bool
    {
        $shippingId = (int) $shippingId;
        if ($shippingId <= 0) return false;

        $name = (string) ShippingMethod::query()->whereKey($shippingId)->value('name');
        if ($name === '') return false;

        return str_contains($name, 'حضوری') || str_contains($name, 'مراجعه');
    }

    private function syncItems(PreinvoiceOrder $order, array $products, bool $preserveExistingOrder = false): void
    {
        $existingById = $preserveExistingOrder ? $order->items()->get()->keyBy('id') : collect();
        $keepIds = [];
        $nextOrder = (int) $order->items()->max('sort_order');
        foreach (array_values($products) as $index => $p) {
            $variant = ProductVariant::query()
                ->whereKey((int) $p['variety_id'])
                ->where('product_id', (int) $p['id'])
                ->firstOrFail(['sell_price']);
            $attrs = [
                'product_id' => (int) $p['id'],
                'variant_id' => (int) $p['variety_id'],
                'quantity' => (int) $p['quantity'],
                'price' => (int) ($p['price'] ?? $variant->sell_price ?? 0),
                'line_discount_amount' => (int) ($p['line_discount_amount'] ?? 0),
            ];
            $itemId = (int) ($p['item_id'] ?? 0);
            if ($preserveExistingOrder && $itemId > 0 && $existingById->has($itemId)) {
                $item = $existingById->get($itemId);
                $item->update($attrs);
                $keepIds[] = $item->id;
            } else {
                $attrs['sort_order'] = $preserveExistingOrder ? ++$nextOrder : ($index + 1);
                $keepIds[] = $order->items()->create($attrs)->id;
            }
        }
        if ($preserveExistingOrder) {
            $order->items()->whereNotIn('id', $keepIds)->delete();
        }
    }


    private function finalizeDraftReservations(PreinvoiceOrder $order, ?string $reservationToken, array $products, ?array $reservationMeta = null): void
    {
        $reservationMeta ??= $this->reservationExpirationForCustomer($order->customer);
        $required = [];
        foreach ($products as $row) {
            $productId = (int) ($row['id'] ?? 0);
            $variantId = (int) ($row['variety_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            if ($productId <= 0 || $variantId <= 0 || $quantity <= 0) {
                continue;
            }

            $key = $productId . ':' . $variantId;
            if (! isset($required[$key])) {
                $required[$key] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                ];
            }
            $required[$key]['quantity'] += $quantity;
        }

        $reservedRows = collect();
        if ($reservationToken && auth()->check()) {
            $reservedRows = PreinvoiceDraftReservation::query()
                ->where('token', $reservationToken)
                ->where('user_id', auth()->id())
                ->whereNull('converted_at')
                ->whereNull('preinvoice_order_id')
                ->whereIn('reservation_scope', ['temporary_online', 'temporary_in_person'])
                ->whereNull('released_at')
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->get();
        }

        $reserved = [];
        foreach ($reservedRows as $row) {
            $key = ((int) $row->product_id) . ':' . ((int) $row->variant_id);
            $reserved[$key] = ($reserved[$key] ?? 0) + (int) $row->quantity;
        }

        foreach ($required as $key => $row) {
            $coveredQty = (int) ($reserved[$key] ?? 0);
            $missingQty = max(0, (int) $row['quantity'] - $coveredQty);
            if ($missingQty > 0) {
                $this->reserveStockForItem((int) $row['product_id'], (int) $row['variant_id'], $missingQty);
            }
        }

        foreach ($reservedRows as $row) {
            $key = ((int) $row->product_id) . ':' . ((int) $row->variant_id);
            $requiredQty = (int) ($required[$key]['quantity'] ?? 0);
            $reservedQty = (int) $row->quantity;

            if ($requiredQty <= 0) {
                $this->releaseStockForItem((int) $row->product_id, (int) $row->variant_id, $reservedQty);
                $row->delete();
                continue;
            }

            if ($reservedQty > $requiredQty) {
                $this->releaseStockForItem((int) $row->product_id, (int) $row->variant_id, $reservedQty - $requiredQty);
                $row->quantity = $requiredQty;
            }

            $row->preinvoice_order_id = $order->id;
            $row->converted_at = now();
            $row->expires_at = $reservationMeta['expires_at'];
            $row->reservation_tier = $reservationMeta['tier'];
            $row->reservation_scope = 'official';
            $row->save();
        }
    }


    public function syncPreinvoiceReservations(PreinvoiceOrder $order, bool $stockAlreadyAdjusted = false, ?array $reservationMeta = null): void
    {
        $reservationMeta ??= $this->reservationExpirationForCustomer($order->customer);
        $order->loadMissing('items');

        $required = [];
        foreach ($order->items as $item) {
            $productId = (int) $item->product_id;
            $variantId = (int) $item->variant_id;
            $quantity = (int) $item->quantity;
            if ($productId <= 0 || $variantId <= 0 || $quantity <= 0) {
                continue;
            }

            $key = $productId . ':' . $variantId;
            if (! isset($required[$key])) {
                $required[$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => 0];
            }
            $required[$key]['quantity'] += $quantity;
        }

        $rows = PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
            ->whereNull('released_at')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (PreinvoiceDraftReservation $row) => ((int) $row->product_id) . ':' . ((int) $row->variant_id));

        foreach ($required as $key => $row) {
            $reservation = $rows->get($key);
            $currentQty = (int) ($reservation?->quantity ?? 0);
            $requiredQty = (int) $row['quantity'];
            $delta = $requiredQty - $currentQty;

            if ($delta > 0 && ! $stockAlreadyAdjusted) {
                $this->reserveStockForItem((int) $row['product_id'], (int) $row['variant_id'], $delta);
            } elseif ($delta < 0 && ! $stockAlreadyAdjusted) {
                $this->releaseStockForItem((int) $row['product_id'], (int) $row['variant_id'], abs($delta));
            }

            if ($reservation) {
                $reservation->quantity = $requiredQty;
                $reservation->expires_at = $reservationMeta['expires_at'];
                $reservation->converted_at = $reservation->converted_at ?? now();
                $reservation->reservation_tier = $reservationMeta['tier'];
                $reservation->reservation_scope = 'official';
                $reservation->save();
            } else {
                PreinvoiceDraftReservation::query()->create([
                    'token' => (string) Str::uuid(),
                    'user_id' => $order->created_by,
                    'preinvoice_order_id' => $order->id,
                    'product_id' => (int) $row['product_id'],
                    'variant_id' => (int) $row['variant_id'],
                    'quantity' => $requiredQty,
                    'expires_at' => $reservationMeta['expires_at'],
                    'converted_at' => now(),
                    'reservation_tier' => $reservationMeta['tier'],
                    'reservation_scope' => 'official',
                ]);
            }
        }

        foreach ($rows as $key => $reservation) {
            if (isset($required[$key])) {
                continue;
            }

            if (! $stockAlreadyAdjusted) {
                $this->releaseStockForItem((int) $reservation->product_id, (int) $reservation->variant_id, (int) $reservation->quantity);
            }
            $reservation->delete();
        }
    }

    private function activeDraftReservationQuantities(?string $reservationToken): array
    {
        if (! $reservationToken || ! auth()->check()) {
            return [];
        }

        return PreinvoiceDraftReservation::query()
            ->where('token', $reservationToken)
            ->where('user_id', auth()->id())
            ->whereNull('converted_at')
            ->whereNull('preinvoice_order_id')
            ->whereIn('reservation_scope', ['temporary_online', 'temporary_in_person'])
            ->whereNull('released_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('quantity', 'variant_id')
            ->mapWithKeys(fn ($quantity, $variantId) => [(int) $variantId => (int) $quantity])
            ->all();
    }

    private function reserveStockForItem(int $productId, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $variant = ProductVariant::query()
            ->with('product:id,name')
            ->whereKey($variantId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();

        $centralStock = WarehouseStock::query()
            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        $available = max(0, (int) ($centralStock?->quantity ?? $variant->stock));
        if ($available < $quantity) {
            $productName = (string) ($variant->product?->name ?? 'نامشخص');
            $variantName = (string) ($variant->variant_name ?? $variant->variety_name ?? $variant->variant_code ?? $variant->id);
            throw ValidationException::withMessages([
                'products' => "موجودی کافی برای ثبت نهایی وجود ندارد. کالا: {$productName} | تنوع: {$variantName} | تعداد درخواستی: {$quantity} | موجودی قابل فروش: {$available}",
            ]);
        }

        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, -$quantity, $variantId);

        $variant->reserved = (int) $variant->reserved + $quantity;
        $variant->save();

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = (int) $product->reserved + $quantity;
            $product->save();
        }
    }

    private function releaseStockForItem(int $productId, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
        if ($variant) {
            $variant->reserved = max(0, (int) $variant->reserved - $quantity);
            $variant->save();
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = max(0, (int) $product->reserved - $quantity);
            $product->save();
        }

        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), $productId, $quantity, $variantId);
    }

    private function reservationExpirationForCustomer(?Customer $customer): array
    {
        $tier = $this->normalizeReservationTier($customer?->reservation_tier);

        return [
            'tier' => $tier,
            'expires_at' => match ($tier) {
                'vip' => null,
                'new_or_low_purchase' => now()->addHour(),
                default => now()->addHours(3),
            },
        ];
    }

    private function normalizeReservationTier(?string $tier): string
    {
        $tier = trim((string) $tier);

        return in_array($tier, ['vip', 'normal', 'new_or_low_purchase'], true)
            ? $tier
            : 'normal';
    }

    private function finalSubmitSuccessMessage(array $reservationMeta): string
    {
        $reservationMessage = match ($reservationMeta['tier'] ?? 'normal') {
            'vip' => 'رزرو این مشتری بدون محدودیت زمانی ثبت شد.',
            'new_or_low_purchase' => 'رزرو تا ۱ ساعت معتبر است.',
            default => 'رزرو تا ۳ ساعت معتبر است.',
        };

        return '✅ پیش‌فاکتور ثبت نهایی شد، موجودی رزرو شد و به صف تایید مالی ارسال شد. ' . $reservationMessage;
    }

    private function reserveOrderStock(PreinvoiceOrder $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $this->reserveStockForItem((int) $item->product_id, (int) $item->variant_id, (int) $item->quantity);
        }
    }

    private function hasActiveFreeze(PreinvoiceOrder $order): bool
    {
        if (! is_null($order->stock_released_at) || $order->status === PreinvoiceOrder::STATUS_DRAFT) {
            return false;
        }

        if (! is_null($order->stock_frozen_until)) {
            return true;
        }

        return PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
            ->where('reservation_scope', 'official')
            ->exists();
    }

    private function moveConsumedInvoiceStockBackToReservation(array $oldItems, array $newItems): void
    {
        foreach ($oldItems as $row) {
            $this->releaseStockForItem((int) $row['product_id'], (int) $row['variant_id'], (int) $row['quantity']);
        }

        foreach ($newItems as $row) {
            $this->reserveStockForItem((int) $row['product_id'], (int) $row['variant_id'], (int) $row['quantity']);
        }
    }

    private function applyFrozenStockDelta(array $oldItems, array $newItems, bool $centralStockMovedToReserve = true): void
    {
        $oldMap = [];
        foreach ($oldItems as $row) {
            $key = ((int) $row['product_id']) . ':' . ((int) $row['variant_id']);
            $oldMap[$key] = ($oldMap[$key] ?? 0) + (int) $row['quantity'];
        }
        $newMap = [];
        foreach ($newItems as $row) {
            $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
            $variantId = (int) ($row['variant_id'] ?? $row['variety_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            $key = $productId . ':' . $variantId;
            $newMap[$key] = ($newMap[$key] ?? 0) + $qty;
        }

        foreach (array_unique(array_merge(array_keys($oldMap), array_keys($newMap))) as $key) {
            [$productId, $variantId] = array_map('intval', explode(':', $key));
            $delta = ($newMap[$key] ?? 0) - ($oldMap[$key] ?? 0);
            if ($delta === 0) continue;

            if ($delta > 0) {
                if ($centralStockMovedToReserve) {
                    $this->reserveStockForItem($productId, $variantId, $delta);
                } else {
                    $this->changeReservedOnly($productId, $variantId, $delta);
                }
            } else {
                if ($centralStockMovedToReserve) {
                    $this->releaseStockForItem($productId, $variantId, abs($delta));
                } else {
                    $this->changeReservedOnly($productId, $variantId, $delta);
                }
            }
        }
    }

    private function releaseReservedStock(PreinvoiceOrder $order): void
    {
        $order->loadMissing('items');
        $centralStockMovedToReserve = $this->hasCentralStockMovedToReserve($order);

        foreach ($order->items as $item) {
            if ($centralStockMovedToReserve) {
                $this->releaseStockForItem((int) $item->product_id, (int) $item->variant_id, (int) $item->quantity);
            } else {
                $this->changeReservedOnly((int) $item->product_id, (int) $item->variant_id, -((int) $item->quantity));
            }
        }

        PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
            ->delete();
    }

    private function hasCentralStockMovedToReserve(PreinvoiceOrder $order): bool
    {
        if (! is_null($order->stock_frozen_until)) {
            return true;
        }

        return PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
            ->where('reservation_scope', 'official')
            ->exists();
    }

    private function coverReservationShortfalls($requiredByVariant, bool $centralStockMovedToReserve): void
    {
        if ($requiredByVariant->isEmpty()) {
            return;
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $requiredByVariant->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($requiredByVariant as $variantId => $requiredQty) {
            $variant = $variants->get((int) $variantId);
            if (! $variant) {
                continue;
            }

            $shortfall = (int) $requiredQty - (int) $variant->reserved;
            if ($shortfall <= 0) {
                continue;
            }

            if ($centralStockMovedToReserve) {
                $this->reserveStockForItem((int) $variant->product_id, (int) $variant->id, $shortfall);
                continue;
            }

            $available = max(0, (int) $variant->stock - (int) $variant->reserved);
            if ($available >= $shortfall) {
                $this->changeReservedOnly((int) $variant->product_id, (int) $variant->id, $shortfall);
            }
        }
    }

    private function changeReservedOnly(int $productId, int $variantId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();
        if ($variant) {
            $variant->reserved = max(0, (int) $variant->reserved + $delta);
            $variant->save();
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        if ($product) {
            $product->reserved = max(0, (int) $product->reserved + $delta);
            $product->save();
        }
    }

    private function officialCodeForPreinvoiceConversion(PreinvoiceOrder $order): string
    {
        if (is_string($order->uuid) && preg_match('/^\d{5}$/', $order->uuid) === 1) {
            return $order->uuid;
        }

        $code = DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class);
        $order->update(['uuid' => $code]);

        return $code;
    }


    private function redirectLegacyWarehouseFlow(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('preinvoice.draft.index')
            ->with('warning', 'روند تایید انبار قدیمی غیرفعال شده است. پیش‌فاکتورها اکنون مستقیم در صف مالی بررسی می‌شوند.');
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole(['Admin', 'admin', 'finance', 'Accountant', 'Manager', 'manager']) || $user->can('finance.approve') || $user->can('preinvoices.finance.view'));
    }

    private function canHandleWarehouseActions(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasAnyRole(['Admin', 'warehouse', 'StorageManager']) || $user->can('warehouse.approve');
    }

    public function finance(string $uuid)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $order = PreinvoiceOrder::with(['items.product', 'items.variant', 'creator:id,name', 'customer:id,crm_customer_id,reservation_tier'])
            ->where('uuid', $uuid)
            ->firstOrFail();
        abort_if(! in_array($order->status, [
            PreinvoiceOrder::STATUS_PENDING_FINANCE,
            PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
        ], true), 403);

        $customerBalanceStatus = 'تسویه شده';
        $customerBalanceAmount = 0;

        if (!empty($order->customer_id)) {
            $customer = Customer::query()
                ->withBalance()
                ->find((int) $order->customer_id);

            if ($customer) {
                $balance = (int) $customer->balance;

                if ($balance > 0) {
                    $customerBalanceStatus = 'بدهکار';
                    $customerBalanceAmount = $balance;
                } elseif ($balance < 0) {
                    $customerBalanceStatus = 'بستانکار';
                    $customerBalanceAmount = abs($balance);
                }
            }
        }

        return view('preinvoice.finance', compact('order', 'customerBalanceStatus', 'customerBalanceAmount'));
    }

    public function finalize(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $order = PreinvoiceOrder::with(['items', 'invoice'])->where('uuid', $uuid)->firstOrFail();
        $this->reservationService->assertFinanceApprovable($order, auth()->user());

        if ($order->invoice || $order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE) {
            if ($order->invoice) {
                return redirect()->route('invoices.show', $order->invoice->uuid)->with('success', 'این پیش‌فاکتور قبلاً به فاکتور تبدیل شده است.');
            }
            abort(409, 'این پیش‌فاکتور قبلاً تبدیل شده است.');
        }
        abort_if(! in_array($order->status, [
            PreinvoiceOrder::STATUS_PENDING_FINANCE,
            PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
        ], true), 403);

        $validated = $request->validate([
            'payments' => 'nullable|array',
            'payments.*.method' => 'required_with:payments|in:cash,cheque',
            'payments.*.amount' => 'required_with:payments|integer|min:1',
            'payments.*.paid_at' => 'required_with:payments|date',
            'payments.*.note' => 'nullable|string|max:2000',
            'payments.*.bank_name' => 'nullable|string|max:255',
            'payments.*.cheque_bank_name' => 'nullable|string|max:255',
            'payments.*.cheque_branch_name' => 'nullable|string|max:255',
            'payments.*.cheque_number' => 'nullable|string|max:255',
            'payments.*.cheque_amount' => 'nullable|integer|min:1',
            'payments.*.cheque_due_date' => 'nullable|date',
            'payments.*.cheque_received_at' => 'nullable|date',
            'payments.*.cheque_customer_name' => 'nullable|string|max:255',
            'payments.*.cheque_customer_code' => 'nullable|string|max:255',
            'payments.*.cheque_account_number' => 'nullable|string|max:255',
            'payments.*.cheque_account_holder' => 'nullable|string|max:255',
            'payments.*.cheque_status' => 'nullable|in:pending,cleared,bounced,registered,unregistered',
        ]);

        $invoice = DB::transaction(function () use ($order, $validated) {
            $lockedOrder = PreinvoiceOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();
            $order = $lockedOrder;
            $officialInvoiceUuid = $this->officialCodeForPreinvoiceConversion($order);
            $existingInvoice = Invoice::query()
                ->where('preinvoice_order_id', $order->id)
                ->orWhere('uuid', $officialInvoiceUuid)
                ->lockForUpdate()
                ->first();

            if ($existingInvoice && (int) $existingInvoice->preinvoice_order_id !== (int) $order->id) {
                throw ValidationException::withMessages([
                    'invoice' => "شماره فاکتور {$officialInvoiceUuid} قبلاً برای پیش‌فاکتور دیگری ثبت شده است. لطفاً با مدیر سیستم تماس بگیرید.",
                ]);
            }

            if ($order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE) {
                if ($existingInvoice) {
                    return $existingInvoice;
                }

                abort(409, 'این پیش‌فاکتور قبلاً تبدیل شده است، اما فاکتور مرتبط پیدا نشد.');
            }

            $this->reservationService->assertFinanceApprovable($order, auth()->user());

            if (! in_array($order->status, [
                PreinvoiceOrder::STATUS_PENDING_FINANCE,
                PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            ], true)) {
                abort(403);
            }

            $shouldConsumeReservedOnFinalize = true;
            $centralStockMovedToReserve = $this->hasCentralStockMovedToReserve($order);

            foreach ($order->items as $it) {
                $variant = ProductVariant::query()->with('product:id,name')->whereKey((int) $it->variant_id)->lockForUpdate()->first();
                $snapshotPrice = (int) ($it->price ?? 0);

                if ((int) $it->quantity > 0 && $snapshotPrice <= 0) {
                    $name = trim(($variant?->product?->name ?? 'نامشخص') . ' / ' . ($variant?->variant_name ?: $variant?->variety_name ?: ('#' . (int) $it->variant_id)));
                    throw ValidationException::withMessages([
                        'price' => "قیمت کالا/تنوع {$name} صفر است و امکان ثبت فاکتور وجود ندارد.",
                    ]);
                }

                $it->price = $snapshotPrice;
            }
            $subtotal = (int) $order->items->sum(fn($it) => ((int) $it->price) * ((int) $it->quantity));
            $itemDiscount = (int) $order->items->sum(fn($it) => (int) ($it->line_discount_amount ?? 0));
            $discount = max((int) $order->discount_amount, $itemDiscount);

            $total = max($subtotal - $discount, 0);

            $requiredByVariant = $order->items
                ->groupBy('variant_id')
                ->map(fn($rows) => (int) $rows->sum('quantity'));

            $this->coverReservationShortfalls($requiredByVariant, $centralStockMovedToReserve);

            $reservedByVariant = ProductVariant::query()
                ->whereIn('id', $requiredByVariant->keys())
                ->lockForUpdate()
                ->pluck('reserved', 'id');

            foreach ($requiredByVariant as $variantId => $requiredQty) {
                $reservedQty = (int) ($reservedByVariant[(int) $variantId] ?? 0);

                if ($reservedQty < $requiredQty) {
                    $variant = ProductVariant::query()->with('product:id,name')->whereKey((int) $variantId)->first();
                    $productName = (string) ($variant?->product?->name ?? 'نامشخص');

                    throw ValidationException::withMessages([
                        'products' => "موجودی رزروشده برای محصول «{$productName}» کافی نیست. رزروشده: {$reservedQty} | درخواست: {$requiredQty}",
                    ]);
                }
            }

            $invoice = $existingInvoice;

            if ($invoice) {
                $invoice->items()->delete();
                $invoice->update([
                    'customer_id' => $order->customer_id ?? null,
                    'customer_name' => $order->customer_name,
                    'customer_mobile' => $order->customer_mobile,
                    'customer_address' => $order->customer_address,
                    'province_id' => $order->province_id,
                    'city_id' => $order->city_id,
                    'shipping_id' => $order->shipping_id,
                    'shipping_price' => (int) $order->shipping_price,
                    'discount_amount' => (int) $discount,
                    'subtotal' => (int) $subtotal,
                    'total' => (int) $total,
                    'status' => Invoice::STATUS_PENDING_COLLECTION,
                    'status_changed_at' => now(),
                    'status_changed_by' => auth()->id(),
                ]);
            } else {
                $invoice = Invoice::create([
                    'uuid' => $officialInvoiceUuid,
                    'preinvoice_order_id' => $order->id,

                    'customer_id' => $order->customer_id ?? null,
                    'customer_name' => $order->customer_name,
                    'customer_mobile' => $order->customer_mobile,
                    'customer_address' => $order->customer_address,
                    'province_id' => $order->province_id,
                    'city_id' => $order->city_id,

                    'shipping_id' => $order->shipping_id,
                    'shipping_price' => (int) $order->shipping_price,
                    'discount_amount' => (int) $discount,
                    'subtotal' => (int) $subtotal,
                    'total' => (int) $total,
                    'status' => Invoice::STATUS_PENDING_COLLECTION,
                ]);
            }

            foreach ($order->items as $it) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => (int) $it->product_id,
                    'variant_id' => (int) $it->variant_id,
                    'quantity' => (int) $it->quantity,
                    'price' => (int) $it->price,
                    'line_total' => max(((int) $it->price * (int) $it->quantity) - (int) ($it->line_discount_amount ?? 0), 0),
                    'sort_order' => (int) ($it->sort_order ?: 0),
                    'line_discount_amount' => (int) ($it->line_discount_amount ?? 0),
                ]);

                if ($shouldConsumeReservedOnFinalize) {
                    $product = Product::query()->whereKey((int) $it->product_id)->lockForUpdate()->first();
                    if ($product) {
                        $product->update([
                            'reserved' => max(0, (int) $product->reserved - (int) $it->quantity),
                        ]);
                    }

                    $variant = ProductVariant::query()->whereKey((int) $it->variant_id)->lockForUpdate()->first();
                    if ($variant) {
                        $variant->update([
                            'reserved' => max(0, (int) $variant->reserved - (int) $it->quantity),
                        ]);
                    }
                }
            }

            if (!empty($invoice->customer_id)) {
                CustomerLedger::query()->updateOrCreate(
                    [
                        'customer_id' => (int) $invoice->customer_id,
                        'type' => 'debit',
                        'reference_type' => Invoice::class,
                        'reference_id' => $invoice->id,
                    ],
                    [
                        'amount' => (int) $invoice->total,
                        'note' => 'ثبت/بروزرسانی بدهکاری بابت فاکتور فروش ' . $invoice->uuid,
                    ]
                );
            }

            foreach (($validated['payments'] ?? []) as $paymentRow) {
                $payload = $paymentRow;
                if (($payload['method'] ?? null) === 'cheque') {
                    $payload['cheque_amount'] = (int) ($payload['amount'] ?? 0);
                }

                $this->paymentService->registerForInvoice(
                    $invoice,
                    $payload,
                    $invoice->customer_id ? (int) $invoice->customer_id : null,
                    auth()->id()
                );
            }

            $this->reservationService->consumeOfficialReservationsForOrder($order, auth()->user());

            $order->update([
                'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                'total_price' => (int) $total,
                'stock_frozen_until' => null,
                'stock_released_at' => now(),
            ]);

            try {
                $this->notificationService->notifyRoleAfterCommit(
                    'warehouse',
                    'invoice_created_for_collection',
                    'فاکتور جدید آماده جمع‌آوری است',
                    "فاکتور شماره {$invoice->uuid} برای مشتری {$invoice->customer_name} تایید مالی شد و وارد صف جمع‌آوری انبار شد.",
                    route('vouchers.sales.queue'),
                    ['level' => 'success', 'priority' => 'important', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "warehouse_invoice_ready:{$invoice->id}"]
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit(
                    (int)$order->created_by,
                    'preinvoice_finance_approved',
                    'پیش‌فاکتور شما تایید مالی شد',
                    "پیش‌فاکتور مشتری «{$order->customer_name}» تایید مالی شد و به فاکتور شماره «{$invoice->uuid}» تبدیل شد. وضعیت فعلی: در صف جمع‌آوری انبار.",
                    route('vouchers.sales.show', $invoice->uuid),
                    ['level' => 'success', 'priority' => 'important', 'data' => ['document_type' => 'فاکتور'], 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "operator_finance_approved:{$order->id}:{$order->created_by}"]
                );
            }

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('success', '✅ تایید مالی انجام شد و پیش‌فاکتور به فاکتور/حواله انبار تبدیل شد.');
    }

    public function financeReturn(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($uuid, $data) {
            $order = PreinvoiceOrder::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($order->status, [
                PreinvoiceOrder::STATUS_PENDING_FINANCE,
                PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            ], true), 403);

            $this->reservationService->assertFinanceApprovable($order, auth()->user());
            $this->reservationService->assertFinanceApprovable($order, auth()->user());
            abort_if($order->invoice()->exists(), 422, 'این پیش‌فاکتور قبلاً به فاکتور تبدیل شده است.');
            $oldStatus = (string) $order->status;
            $release = $this->reservationService->releaseOfficialReservationsForOrder($order, PreinvoiceOrder::STATUS_RETURNED_TO_SALES, $data['reason'], auth()->user());
            $order->update([
                'status' => PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
                'warehouse_reject_reason' => $data['reason'],
                'stock_released_at' => now(),
                'stock_frozen_until' => null,
            ]);
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'finance_returned_preinvoice',
                'reason' => $data['reason'],
                'before_items' => $this->snapshotItems($order),
                'after_items' => $this->snapshotItems($order),
            ]);
            ActivityLogger::log('finance_returned_preinvoice', $order->fresh(), 'پیش‌فاکتور توسط مالی جهت اصلاح برگشت داده شد.', [
                'old_status' => $oldStatus,
                'new_status' => PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
                'reason' => $data['reason'],
                'released_reservations' => $release['released_reservations'] ?? 0,
                'released_quantity' => $release['released_quantity'] ?? 0,
            ]);
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit((int) $order->created_by, 'preinvoice_returned_to_sales', 'پیش‌فاکتور برای اصلاح برگشت خورد', 'پیش‌فاکتور مشتری «' . $order->customer_name . '» توسط مالی برگشت داده شد. علت: ' . $data['reason'], route('preinvoice.my.show', $order->uuid), ['level' => 'warning', 'priority' => 'urgent', 'data' => ['document_type' => 'پیش‌فاکتور', 'reason' => $data['reason']], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_finance_returned:{$order->id}:{$order->created_by}"]);
            }
        });

        return redirect()->route('preinvoice.draft.index')->with('success', '✅ پیش‌فاکتور به فروشنده ارجاع شد.');
    }

    public function financeCancel(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($uuid, $data) {
            $order = PreinvoiceOrder::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($order->status, [
                PreinvoiceOrder::STATUS_PENDING_FINANCE,
                PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            ], true), 403);

            $oldStatus = (string) $order->status;
            $release = $this->reservationService->releaseOfficialReservationsForOrder($order, PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, $data['reason'], auth()->user());
            $order->update([
                'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                'warehouse_reject_reason' => $data['reason'],
                'stock_released_at' => now(),
                'stock_frozen_until' => null,
            ]);
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'finance_cancelled_preinvoice',
                'reason' => $data['reason'],
                'before_items' => $this->snapshotItems($order),
                'after_items' => $this->snapshotItems($order),
            ]);
            ActivityLogger::log('finance_cancelled_preinvoice', $order->fresh(), 'پیش‌فاکتور توسط مالی کنسل شد.', [
                'old_status' => $oldStatus,
                'new_status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                'reason' => $data['reason'],
                'released_reservations' => $release['released_reservations'] ?? 0,
                'released_quantity' => $release['released_quantity'] ?? 0,
            ]);
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUserAfterCommit((int) $order->created_by, 'preinvoice_cancelled_by_finance', 'پیش‌فاکتور توسط مالی کنسل شد', 'پیش‌فاکتور مشتری «' . $order->customer_name . '» توسط مالی کنسل شد. علت: ' . $data['reason'], route('preinvoice.my.show', $order->uuid), ['level' => 'danger', 'priority' => 'urgent', 'data' => ['document_type' => 'پیش‌فاکتور', 'reason' => $data['reason']], 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_finance_cancelled:{$order->id}:{$order->created_by}"]);
            }
        });

        return redirect()->route('preinvoice.draft.index')->with('success', '✅ پیش‌فاکتور با دلیل کنسل شد.');
    }

}
