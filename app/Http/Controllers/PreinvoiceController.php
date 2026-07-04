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
use App\Models\StockMovement;
use App\Support\Currency;
use App\Support\SalesDocumentTotals;
use App\Support\IranLocations;
use App\Support\DocumentCodeGenerator;
use App\Support\ActivityLogger;
use App\Services\WarehouseReviewAuditService;
use App\Services\WarehousePendingRefreshService;
use App\Services\Sales\SalePriceGuard;
use App\Services\WarehouseStockService;
use App\Services\CentralInventoryService;
use App\Services\SalesDocumentAccessService;
use App\Services\SalesPrintDocumentService;
use App\Services\PaymentRegistrationService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    ) {}

    public function create()
    {
        $shippingMethods = ShippingMethod::query()
            ->select(['id', 'name', 'price'])
            ->orderBy('name')
            ->get();

        return view('preinvoice.create', compact('shippingMethods'));
    }

    public function warehouseQueue()
    {

        $orders = PreinvoiceOrder::query()
            ->where('status', PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE)
            ->with(['creator:id,name'])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('preinvoice.warehouse-index', compact('orders'));
    }

    public function warehouseReview(string $uuid)
    {
        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()
            ->with([
                'items.product:id,name',
                'items.variant:id,product_id,variant_name,stock,reserved,is_active',
                'creator:id,name',
                'warehouseReviewer:id,name',
                'reviews.user:id,name',
                'invoice:id,uuid,preinvoice_order_id,status,created_at,document_date',
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
                $this->syncPreinvoiceReservations($lockedOrder, true);
            }
        });

        $order->refresh()->load([
            'items.product:id,name',
            'items.variant:id,product_id,variant_name,stock,reserved,is_active',
            'creator:id,name',
            'warehouseReviewer:id,name',
            'reviews.user:id,name',
            'invoice:id,uuid,preinvoice_order_id,status,created_at,document_date',
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
        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()->with('items')->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        $this->logWarehouseReviewRequestItems($order, $request);
        $data = $this->validateWarehouseReviewPayload($request, false, $order);

        DB::transaction(function () use ($order, $data) {
            $order = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);
            $this->assertWarehouseCanOnlyEditExistingItems($order, $data['items']);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id());
            $this->validateWarehouseChangeReasons($order, $data);

            $before = $this->snapshotItems($order);
            $stockLocked = $this->hasActiveFreeze($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $this->replaceOrderItems($order, $data['items']);
            if ($stockLocked) {
                $this->syncPreinvoiceReservationsAfterItemChange($order->fresh('items'), $oldItems);
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
                $this->notificationService->notifyUser(
                    (int)$order->created_by,
                    'preinvoice_warehouse_changed',
                    'پیش‌فاکتور شما توسط انبار اصلاح شد',
                    "آیتم‌های پیش‌فاکتور مشتری {$order->customer_name} توسط انبار اصلاح شد.",
                    route('preinvoice.my.show', $order->uuid),
                    ['level' => 'warning', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_warehouse_changed:{$order->id}:{$order->created_by}"]
                );
            }
        });

        return back()->with('success', '✅ تغییرات انبار ذخیره شد.');
    }

    public function warehouseApprove(string $uuid, Request $request)
    {
        abort_unless($this->canHandleWarehouseActions(), 403);

        $order = PreinvoiceOrder::query()->with('items')->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);

        $this->logWarehouseReviewRequestItems($order, $request);
        $data = $this->validateWarehouseReviewPayload($request, true, $order);

        DB::transaction(function () use ($order, $data) {
            $order = PreinvoiceOrder::query()->with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($order->status !== PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 403);
            $this->assertWarehouseCanOnlyEditExistingItems($order, $data['items']);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id());
            $this->validateWarehouseChangeReasons($order, $data);

            $before = $this->snapshotItems($order);
            $stockLocked = $this->hasActiveFreeze($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $this->replaceOrderItems($order, $data['items']);
            if ($stockLocked) {
                $this->syncPreinvoiceReservationsAfterItemChange($order->fresh('items'), $oldItems);
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
            $this->notificationService->notifyRole(
                'finance',
                'preinvoice_submitted_to_finance',
                'پیش‌فاکتور جدید در انتظار تایید مالی',
                "پیش‌فاکتور مشتری {$order->customer_name} توسط انبار تایید شد و آماده بررسی مالی است.",
                route('preinvoice.draft.finance', $order->uuid),
                ['level' => 'info', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "finance_preinvoice_ready:{$order->id}"]
            );
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUser(
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
                $this->notificationService->notifyUser((int)$order->created_by, 'preinvoice_warehouse_rejected', 'پیش‌فاکتور شما توسط انبار برگشت خورد', 'علت: ' . $data['warehouse_reject_reason'], route('preinvoice.my.show', $order->uuid), ['level' => 'danger', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "operator_warehouse_rejected:{$order->id}:{$order->created_by}"]);
            }
        });

        return redirect()->route('preinvoice.warehouse.index')->with('success', '✅ پیش‌فاکتور رد شد.');
    }

    public function draftIndex()
    {
        $orders = PreinvoiceOrder::query()
            ->where('status', PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE)
            ->with(['creator:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        $reapprovalInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)
            ->with(['preinvoiceOrder.creator:id,name'])
            ->orderByDesc('updated_at')
            ->get();

        $canFinanceApprove = $this->canHandleFinanceActions();

        return view('preinvoice.drafts-index', compact('orders', 'canFinanceApprove', 'reapprovalInvoices'));
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
            ->with(['invoice:id,uuid,preinvoice_order_id,status,created_at,document_date'])
            ->withCount('items');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $orders = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $statusLabels = PreinvoiceOrder::statusLabels();

        return view('preinvoice.my-index', compact('orders', 'status', 'statusLabels'));
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
                'invoice:id,uuid,preinvoice_order_id,status,created_at,document_date',
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
        $validated = $this->validateDraftPayload($request);

        DB::transaction(function () use ($validated) {
            $customer = $this->resolveCustomer($validated);
            $shippingId = (int) $validated['shipping_id'];

            $order = PreinvoiceOrder::create([
                'uuid' => DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class),
                'created_by' => auth()->id(),
                'status' => PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,

                'customer_id' => $customer?->id,
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),

                'shipping_id' => $shippingId,
                'shipping_price' => (int) $this->resolveShippingPrice($shippingId),
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'total_price' => 0,
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ]);

            $validated['products'] = $this->validateAndHydrateDraftItemsForSale($validated['products'], $validated['reservation_token'] ?? null);
            $this->syncItems($order, $validated['products']);
            $this->finalizeDraftReservations($order, $validated['reservation_token'] ?? null, $validated['products']);
            $this->syncPreinvoiceReservations($order, true);
            $order->update([
                'total_price' => $this->calculateOrderTotal($order),
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ]);
            $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id(), null);

            $this->notificationService->notifyRole(
                'finance',
                'preinvoice_submitted_to_finance',
                'پیش‌فاکتور جدید در انتظار تایید مالی',
                "پیش‌فاکتور مشتری {$order->customer_name} با مبلغ " . Currency::formatRialNumber($order->total_price) . " ریال ثبت شد و مستقیم منتظر بررسی مالی است.",
                route('preinvoice.draft.finance', $order->uuid),
                ['level' => 'info', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "finance_preinvoice_submitted:{$order->id}"]
            );
        });

        return redirect()->route('preinvoice.create')
            ->with('success', '✅ پیش‌فاکتور ثبت و مستقیم برای تایید مالی ارسال شد.');
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

        $validated = $this->validateDraftPayload($request, false, $order);

        $itemsChanged = DB::transaction(function () use ($order, $validated) {
            $order = PreinvoiceOrder::query()
                ->with(['items', 'invoice.items'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $this->accessService->canSellerEditPreinvoiceItems($order, auth()->user())) {
                throw ValidationException::withMessages([
                    'preinvoice' => 'این پیش‌فاکتور به فاکتور تبدیل شده است و فقط واحد مالی مجاز به ویرایش آن است.',
                ]);
            }

            $customer = $this->resolveCustomer($validated);
            $shippingId = (int) $validated['shipping_id'];
            $before = $this->snapshotItems($order);
            $oldItemSignature = $this->itemChangeSignatureFromOrder($order);
            $oldItems = $order->items->map(fn($it) => ['product_id' => (int) $it->product_id, 'variant_id' => (int) $it->variant_id, 'quantity' => (int) $it->quantity])->all();
            $newItems = collect($validated['products'])->map(fn($p) => [
                'product_id' => (int) $p['id'],
                'variant_id' => (int) $p['variety_id'],
                'quantity' => (int) $p['quantity'],
            ])->all();
            $itemsActuallyChanged = $oldItemSignature !== $this->itemChangeSignatureFromDraftRows($validated['products']);

            $stockLocked = $this->hasActiveFreeze($order);
            if ($stockLocked || ! $order->invoice) {
                $this->assertCentralStockForPositiveDeltas($oldItems, $newItems);
            }

            $order->update([
                'customer_id' => $customer?->id,
                'customer_name' => $this->orderCustomerName($validated, $customer),
                'customer_mobile' => $this->orderCustomerMobile($validated, $customer),
                'customer_address' => $this->orderCustomerAddress($validated, $customer, $shippingId),
                'description' => $this->orderDescription($validated),
                'province_id' => $this->orderProvinceId($validated, $customer, $shippingId),
                'city_id' => $this->orderCityId($validated, $customer, $shippingId),

                'shipping_id' => $shippingId,
                'shipping_price' => (int) $this->resolveShippingPrice($shippingId),
                'discount_amount' => (int) ($validated['discount_amount'] ?? 0),
                'total_price' => 0,
            ]);

            $validated['products'] = $this->validateAndHydrateDraftItemsForSale($validated['products'], $validated['reservation_token'] ?? null, $order);
            $this->syncItems($order, $validated['products'], true);

            if ($stockLocked) {
                $this->syncPreinvoiceReservationsAfterItemChange($order->fresh('items'), $oldItems);
            } elseif ($order->invoice) {
                $this->moveConsumedInvoiceStockBackToReservation($oldItems, $newItems);
            }

            $order->refresh()->load(['items.product', 'items.variant', 'invoice.items']);
            $oldStatus = (string) $order->status;
            $updatePayload = [
                'total_price' => $this->calculateOrderTotal($order),
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ];

            if ($itemsActuallyChanged) {
                $updatePayload = array_merge($updatePayload, [
                    'status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                    'warehouse_review_note' => null,
                    'warehouse_reject_reason' => null,
                    'warehouse_reviewed_by' => null,
                    'warehouse_reviewed_at' => null,
                    'items_updated_at' => now(),
                    'items_updated_by' => auth()->id(),
                ]);
            }

            $order->update($updatePayload);

            if ($itemsActuallyChanged) {
                $this->warehouseReviewAuditService->ensureBeforeSnapshot($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), auth()->id(), $oldStatus);
                $this->warehouseReviewAuditService->log($order->fresh(), \App\Models\WarehouseReviewLog::ACTION_RESUBMITTED_TO_WAREHOUSE, auth()->id(), $oldStatus, PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE, 'پیش‌فاکتور بعد از اصلاح دوباره به صف انبار ارسال شد.');
                $this->warehousePendingRefreshService->refreshActiveWarehousePendingForDocument($order->fresh(['items.product', 'items.variant', 'creator', 'customer']), 'preinvoice', auth()->id());

                $this->syncExistingInvoiceFromOrderForReapproval($order->fresh(['items', 'invoice.items']));

                $order->reviews()->create([
                    'user_id' => auth()->id(),
                    'action' => 'seller_items_changed_reapproval_required',
                    'reason' => 'اقلام سند توسط فروشنده/مدیر تغییر کرد و تاییدهای قبلی باطل شد.',
                    'before_items' => $before,
                    'after_items' => $this->snapshotItems($order->fresh('items.product', 'items.variant')),
                ]);

                ActivityLogger::log('seller_items_reapproval', $order->fresh(), 'اقلام سند تغییر کرد و برای بررسی مجدد به انبار و مالی ارسال شد.', [
                    'old_status' => $oldStatus,
                    'new_status' => PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                    'user_id' => auth()->id(),
                ]);

                if (!empty($order->created_by)) {
                    $this->notificationService->notifyRole(
                        'warehouse',
                        'preinvoice_items_changed_reapproval_required',
                        'پیش‌فاکتور نیازمند بررسی مجدد انبار است',
                        "اقلام پیش‌فاکتور مشتری {$order->customer_name} تغییر کرد و دوباره به صف انبار برگشت.",
                        route('preinvoice.warehouse.review', $order->uuid),
                        ['level' => 'warning', 'notifiable_type' => PreinvoiceOrder::class, 'notifiable_id' => $order->id, 'unique_key' => "warehouse_reapproval_required:{$order->id}:" . now()->timestamp]
                    );
                }
            }

            return $itemsActuallyChanged;
        });

        return back()->with('success', $itemsChanged
            ? '✅ اقلام سند تغییر کرد و برای بررسی مجدد به انبار و مالی ارسال شد.'
            : '✅ اطلاعات پیش‌فاکتور ذخیره شد؛ چون اقلام واقعی تغییر نکرده بود، تاییدهای قبلی باطل نشد.');
    }

    private function validateDraftPayload(Request $request, bool $checkCurrentStock = true, ?PreinvoiceOrder $editingOrder = null): array
    {
        $shippingId = (int) $request->input('shipping_id');
        $isInPerson = $this->isInPersonShippingId($shippingId);

        $validated = $request->validate([
            'reservation_token' => 'nullable|uuid',
            'draft_token' => 'nullable|uuid',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'required|string|max:255',
            'customer_mobile' => 'required|string|max:20',
            'customer_address' => $isInPerson ? 'nullable|string|max:1000' : 'required|string|max:1000',
            'description' => 'nullable|string|max:2000',
            'province_id' => $isInPerson ? 'nullable|integer' : 'required|integer',
            'city_id' => 'nullable|integer',

            'shipping_id' => 'required|integer|exists:shipping_methods,id',
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
            'customer_address.required' => 'برای روش‌های ارسال غیرحضوری، آدرس الزامی است.',
            'province_id.required' => 'برای روش‌های ارسال غیرحضوری، استان الزامی است.',
            'products.required' => 'حداقل یک محصول باید ثبت شود.',
            'products.min' => 'حداقل یک محصول باید ثبت شود.',
        ]);

        $validated['reservation_token'] = $validated['reservation_token'] ?? ($validated['draft_token'] ?? null);

        if ($checkCurrentStock && empty($validated['reservation_token'])) {
            throw ValidationException::withMessages([
                'reservation_token' => 'توکن رزرو پیش‌فاکتور معتبر نیست. لطفاً صفحه را دوباره باز کنید.',
            ]);
        }

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
            $this->validateDraftItemsBusinessRules($validated['products'] ?? [], $validated['reservation_token'] ?? null, $editingOrder);
        }

        return $validated;
    }

    private function validateDraftItemsBusinessRules(array $products, ?string $reservationToken = null, ?PreinvoiceOrder $existingOrder = null): void
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
        $existingDocumentQuantities = $this->existingPreinvoiceItemQuantities($existingOrder);
        $centralAvailableByVariant = $variants->mapWithKeys(fn (ProductVariant $variant, int|string $id) => [
            (int) $id => $this->centralAvailableQty($variant),
        ])->all();
        Log::debug('PREINVOICE_STORE_STOCK_CHECK', [
            'user_id' => auth()->id(),
            'draft_token' => $reservationToken,
            'requested_by_variant' => $qtyByVariant,
            'draft_reserved_by_variant' => $draftReservations,
            'existing_document_qty_by_variant' => $existingDocumentQuantities,
            'central_available_by_variant' => $centralAvailableByVariant,
        ]);

        foreach ($qtyByVariant as $variantId => $requiredQty) {
            $variant = $variants->get((int) $variantId);
            if ((int) ($variant->sell_price ?? 0) <= 0) {
                $name = $this->variantSaleLabel($variant);
                throw ValidationException::withMessages([
                    'products' => "قیمت فروش برای کالای {$name} ثبت نشده است و امکان افزودن به پیش‌فاکتور وجود ندارد.",
                ]);
            }

            $draftReservedQty = (int) ($draftReservations[(int) $variantId] ?? 0);
            $existingQty = (int) ($existingDocumentQuantities[(int) $variantId] ?? 0);
            $centralAvailable = $this->centralAvailableQty($variant);
            $availableQty = $centralAvailable + $draftReservedQty + $existingQty;

            if ($requiredQty > $availableQty) {
                $name = $this->variantSaleLabel($variant);
                throw ValidationException::withMessages([
                    'products' => "موجودی قابل فروش کالای {$name} کافی نیست. تعداد قبلی همین سند: {$existingQty}، رزرو موقت همین فرم: {$draftReservedQty}، موجودی آزاد مرکزی: {$centralAvailable}، تعداد درخواستی: {$requiredQty}.",
                ]);
            }
        }
    }

    private function validateAndHydrateDraftItemsForSale(array $products, ?string $reservationToken = null, ?PreinvoiceOrder $existingOrder = null): array
    {
        $variantIds = collect($products)->pluck('variety_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $variants = ProductVariant::query()
            ->with('product:id,name,is_sellable')
            ->whereIn('id', $variantIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $draftReservations = $this->activeDraftReservationQuantities($reservationToken);
        $existingDocumentQuantities = $this->existingPreinvoiceItemQuantities($existingOrder);
        $requestedByVariant = collect($products)
            ->groupBy(fn (array $row) => (int) ($row['variety_id'] ?? 0))
            ->map(fn ($rows) => (int) collect($rows)->sum(fn (array $row) => (int) ($row['quantity'] ?? 0)))
            ->all();
        $variantStock = $variants->mapWithKeys(fn (ProductVariant $variant, int|string $id) => [
            (int) $id => (int) ($variant->stock ?? 0),
        ])->all();
        $variantReserved = $variants->mapWithKeys(fn (ProductVariant $variant, int|string $id) => [
            (int) $id => (int) ($variant->reserved ?? 0),
        ])->all();
        $availableForThisSubmit = $variants->mapWithKeys(fn (ProductVariant $variant, int|string $id) => [
            (int) $id => $this->centralAvailableQty($variant)
                + (int) ($draftReservations[(int) $id] ?? 0)
                + (int) ($existingDocumentQuantities[(int) $id] ?? 0),
        ])->all();

        Log::debug('PREINVOICE_FINAL_STOCK_CHECK', [
            'user_id' => auth()->id(),
            'reservation_token' => $reservationToken,
            'requested_by_variant' => $requestedByVariant,
            'draft_reserved_by_variant' => $draftReservations,
            'existing_document_qty_by_variant' => $existingDocumentQuantities,
            'variant_stock' => $variantStock,
            'variant_reserved' => $variantReserved,
            'available_for_this_submit' => $availableForThisSubmit,
        ]);

        return collect($products)->map(function (array $row, int $index) use ($variants, $draftReservations, $existingDocumentQuantities) {
            $productId = (int) ($row['id'] ?? 0);
            $variantId = (int) ($row['variety_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            $variant = $variants->get($variantId);

            if (! $variant || (int) $variant->product_id !== $productId || ! (bool) $variant->is_active) {
                throw ValidationException::withMessages([
                    "products.{$index}.variety_id" => 'تنوع انتخابی برای این کالا نامعتبر یا غیرفعال است.',
                ]);
            }

            $name = $this->variantSaleLabel($variant);
            if ((int) ($variant->sell_price ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    "products.{$index}.price" => "قیمت فروش برای کالای {$name} ثبت نشده است و امکان افزودن به پیش‌فاکتور وجود ندارد.",
                ]);
            }

            $draftReservedQty = (int) ($draftReservations[$variantId] ?? 0);
            $existingQty = (int) ($existingDocumentQuantities[$variantId] ?? 0);
            $centralAvailable = $this->centralAvailableQty($variant);
            $availableQty = $centralAvailable + $draftReservedQty + $existingQty;
            if ($quantity > $availableQty) {
                throw ValidationException::withMessages([
                    "products.{$index}.quantity" => "موجودی قابل فروش کالای {$name} کافی نیست. تعداد قبلی همین سند: {$existingQty}، رزرو موقت همین فرم: {$draftReservedQty}، موجودی آزاد مرکزی: {$centralAvailable}، تعداد درخواستی: {$quantity}.",
                ]);
            }

            $row['price'] = (int) $variant->sell_price;

            return $row;
        })->all();
    }

    private function existingPreinvoiceItemQuantities(?PreinvoiceOrder $order): array
    {
        if (! $order) {
            return [];
        }

        return $order->items()
            ->selectRaw('variant_id, SUM(quantity) as qty')
            ->whereNotNull('variant_id')
            ->groupBy('variant_id')
            ->pluck('qty', 'variant_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    private function centralAvailableQty(ProductVariant $variant): int
    {
        return max(0, (int) ($variant->stock ?? 0));
    }

    private function variantSaleLabel(ProductVariant $variant): string
    {
        $variant->loadMissing('product:id,name');
        $parts = array_filter([
            (string) ($variant->product?->name ?? ''),
            (string) ($variant->variant_name ?? ''),
            (string) ($variant->variety_name ?? ''),
        ]);

        return $parts ? implode(' - ', $parts) : 'انتخابی';
    }

    private function logWarehouseReviewRequestItems(PreinvoiceOrder $order, Request $request): void
    {
        $rawItems = $request->input('items', []);

        Log::debug('warehouse approval request items', [
            'order_id' => $order->id ?? null,
            'uuid' => $order->uuid ?? null,
            'items_keys' => is_array($rawItems) ? array_keys($rawItems) : [],
            'item_70' => data_get($rawItems, '70'),
            'item_166' => data_get($rawItems, '166'),
            'items_count' => is_array($rawItems) ? count($rawItems) : 0,
        ]);
    }

    private function validateWarehouseReviewPayload(Request $request, bool $forApprove = false, ?PreinvoiceOrder $order = null): array
    {
        $items = $this->normalizeWarehouseReviewItems($request->input('items', []), $order, $request->input('removed_items', []));

        Log::debug('warehouse approval validation debug', [
            'order_id' => $order->id ?? null,
            'uuid' => $order->uuid ?? null,
            'raw_items_keys' => is_array($request->input('items', [])) ? array_keys($request->input('items', [])) : [],
            'raw_item_70' => data_get($request->input('items', []), '70'),
            'normalized_items' => $items,
        ]);

        $request->merge([
            'items' => $items,
        ]);

        $validated = $request->validate([
            'warehouse_review_note' => $forApprove ? 'required|string|max:2000' : 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.item_id' => 'nullable|integer',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'required|integer',
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
            $price = (int) ($row['price'] ?? 0);
            app(SalePriceGuard::class)->assertInvoiceUnitPrice($price, "items.{$index}.price");

            $variantForPrice = ProductVariant::query()->whereKey((int) $row['variant_id'])->first(['id', 'sell_price']);
            if ($variantForPrice) {
                app(SalePriceGuard::class)->assertVariantHasSalePrice($variantForPrice, "items.{$index}.price");
            }

            $itemId = (int) ($row['item_id'] ?? $row['id'] ?? 0);
            $existing = $order && $itemId > 0
                ? $order->items->firstWhere('id', $itemId)
                : null;

            if ($existing) {
                continue;
            }

            $productExists = Product::query()
                ->whereKey((int) $row['product_id'])
                ->where('is_sellable', true)
                ->exists();

            if (! $productExists) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'کالای انتخابی معتبر نیست.',
                ]);
            }

            $isValidVariant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->where('is_active', true)
                ->exists();

            if (! $isValidVariant) {
                throw ValidationException::withMessages([
                    "items.{$index}.variant_id" => 'تنوع انتخابی برای کالا معتبر نیست.',
                ]);
            }
        }

        return $validated;
    }

    private function normalizeWarehouseReviewItems(mixed $items, ?PreinvoiceOrder $order = null, mixed $removedItems = []): array
    {
        if (! is_array($items)) {
            return [];
        }

        $existingItems = $order
            ? $order->items->keyBy(fn ($item) => (int) $item->id)
            : collect();
        $existingByProductVariant = $order
            ? $order->items->keyBy(fn ($item) => ((int) $item->product_id) . ':' . ((int) $item->variant_id))
            : collect();
        $removedKeys = collect(is_array($removedItems) ? $removedItems : [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn ($row) => ((int) ($row['product_id'] ?? 0)) . ':' . ((int) ($row['variant_id'] ?? 0)))
            ->filter(fn ($key) => $key !== '0:0')
            ->flip();
        $normalized = [];
        $seenItemIds = [];

        foreach ($items as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (['marked_deleted', 'deleted', '_delete', 'remove'] as $flag) {
                if (filter_var($row[$flag] ?? false, FILTER_VALIDATE_BOOL)) {
                    continue 2;
                }
            }

            $hasAnyValue = false;
            foreach (['id', 'item_id', 'product_id', 'variant_id', 'quantity', 'price'] as $field) {
                if (trim((string) ($row[$field] ?? '')) !== '') {
                    $hasAnyValue = true;
                    break;
                }
            }

            if (! $hasAnyValue) {
                continue;
            }

            $itemId = (int) ($row['item_id'] ?? $row['id'] ?? (is_numeric($key) ? $key : 0));
            $existing = $itemId > 0 ? $existingItems->get($itemId) : null;

            if (! $existing && $order && ! empty($row['product_id']) && ! empty($row['variant_id'])) {
                $existing = $existingByProductVariant->get(((int) $row['product_id']) . ':' . ((int) $row['variant_id']));
            }

            if ($order && ! $existing) {
                continue;
            }

            if ($existing) {
                $row['id'] = (int) $existing->id;
                $row['item_id'] = (int) $existing->id;
                $row['product_id'] = (int) $existing->product_id;
                $row['variant_id'] = (int) $existing->variant_id;
                $row['quantity'] = filled($row['quantity'] ?? null) ? (int) $row['quantity'] : (int) $existing->quantity;
                $row['price'] = filled($row['price'] ?? null) ? (int) $row['price'] : (int) $existing->price;
                $seenItemIds[(int) $existing->id] = true;
            }

            $normalized[] = $row;
        }

        if ($order) {
            foreach ($order->items as $existing) {
                $itemId = (int) $existing->id;
                $productVariantKey = ((int) $existing->product_id) . ':' . ((int) $existing->variant_id);

                if (isset($seenItemIds[$itemId]) || isset($removedKeys[$productVariantKey])) {
                    continue;
                }

                $normalized[] = [
                    'id' => $itemId,
                    'item_id' => $itemId,
                    'product_id' => (int) $existing->product_id,
                    'variant_id' => (int) $existing->variant_id,
                    'quantity' => (int) $existing->quantity,
                    'price' => (int) $existing->price,
                ];
            }
        }

        return array_values($normalized);
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
        $existingByKey = $order->items()->get()->keyBy(fn ($item) => ((int) $item->product_id) . ':' . ((int) $item->variant_id));
        $keepIds = [];

        foreach (array_values($items) as $index => $row) {
            $variant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->firstOrFail(['id', 'sell_price']);
            app(SalePriceGuard::class)->assertVariantHasSalePrice($variant, "items.{$index}.price");
            $price = (int) ($row['price'] ?? $variant->sell_price ?? 0);
            app(SalePriceGuard::class)->assertInvoiceUnitPrice($price, "items.{$index}.price");
            $attrs = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
                'quantity' => (int) $row['quantity'],
                'price' => $price,
                'sort_order' => $index + 1,
            ];
            $key = $attrs['product_id'] . ':' . $attrs['variant_id'];
            $existing = $existingByKey->get($key);
            if ($existing) {
                $existing->fill($attrs);
                if ($existing->isDirty()) {
                    $existing->save();
                }
                $keepIds[] = (int) $existing->id;
                continue;
            }

            $keepIds[] = (int) $order->items()->create($attrs)->id;
        }

        $order->items()->whereNotIn('id', $keepIds)->delete();
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
        $order->loadMissing('items');

        return SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price)['grand_total'];
    }

    private function itemChangeSignatureFromOrder(PreinvoiceOrder $order): array
    {
        $order->loadMissing('items');

        return $order->items
            ->map(fn ($item) => [
                'product_id' => (int) $item->product_id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_discount_amount' => (int) ($item->line_discount_amount ?? 0),
            ])
            ->sortBy(fn ($row) => $row['product_id'] . ':' . $row['variant_id'])
            ->values()
            ->all();
    }

    private function itemChangeSignatureFromDraftRows(array $rows): array
    {
        return collect($rows)
            ->map(fn ($row) => [
                'product_id' => (int) ($row['id'] ?? 0),
                'variant_id' => (int) ($row['variety_id'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'price' => (int) ($row['price'] ?? 0),
                'line_discount_amount' => (int) ($row['line_discount_amount'] ?? 0),
            ])
            ->sortBy(fn ($row) => $row['product_id'] . ':' . $row['variant_id'])
            ->values()
            ->all();
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

    private function assertWarehouseCanOnlyEditExistingItems(PreinvoiceOrder $order, array $newItems): void
    {
        $oldMap = $this->itemQuantityMap($order->items->map(fn($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
        ])->all());
        $newMap = $this->itemQuantityMap($newItems);

        foreach (array_keys($newMap) as $key) {
            if (! array_key_exists($key, $oldMap)) {
                abort(403, 'انبار مجاز به افزودن کالای جدید نیست.');
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

        $totals = SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price);
        $subtotal = $totals['subtotal_before_discount'];
        $total = $totals['grand_total'];

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
            'status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
            'status_changed_at' => now(),
            'status_changed_by' => auth()->id(),
            'items_updated_at' => now(),
            'items_updated_by' => auth()->id(),
        ]);

        ActivityLogger::log('invoice_items_reapproval', $invoice->fresh(), 'اقلام فاکتور تغییر کرد و فاکتور به وضعیت نیازمند تایید انبار برگشت.', [
            'old_status' => $oldStatus,
            'new_status' => Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
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

    private function orderCustomerAddress(array $validated, ?Customer $customer, int $shippingId): string
    {
        if ($this->isInPersonShippingId($shippingId)) {
            return '';
        }

        $address = trim((string) ($validated['customer_address'] ?? ''));
        if ($address !== '') return $address;

        if ($customer && !empty($customer->address)) {
            return (string) $customer->address;
        }

        return '';
    }

    private function orderProvinceId(array $validated, ?Customer $customer, int $shippingId): int
    {
        if ($this->isInPersonShippingId($shippingId)) {
            return 0;
        }

        if (!empty($validated['province_id'])) {
            return (int) $validated['province_id'];
        }

        if ($customer && !empty($customer->province_id)) {
            return (int) $customer->province_id;
        }

        return 0;
    }

    private function orderCityId(array $validated, ?Customer $customer, int $shippingId): ?int
    {
        if ($this->isInPersonShippingId($shippingId)) {
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
        $existingItems = $preserveExistingOrder ? $order->items()->get() : collect();
        $existingById = $preserveExistingOrder ? $existingItems->keyBy('id') : collect();
        $existingByKey = $preserveExistingOrder ? $existingItems->keyBy(fn ($item) => ((int) $item->product_id) . ':' . ((int) $item->variant_id)) : collect();
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
                'price' => (int) ($variant->sell_price ?? 0),
                'line_discount_amount' => (int) ($p['line_discount_amount'] ?? 0),
            ];
            $itemId = (int) ($p['item_id'] ?? 0);
            $item = null;
            if ($preserveExistingOrder && $itemId > 0 && $existingById->has($itemId)) {
                $item = $existingById->get($itemId);
            } elseif ($preserveExistingOrder) {
                $item = $existingByKey->get($attrs['product_id'] . ':' . $attrs['variant_id']);
            }

            if ($item) {
                $item->fill($attrs);
                if ($item->isDirty()) {
                    $item->save();
                }
                $keepIds[] = (int) $item->id;
            } else {
                $attrs['sort_order'] = $preserveExistingOrder ? ++$nextOrder : ($index + 1);
                $keepIds[] = (int) $order->items()->create($attrs)->id;
            }
        }
        if ($preserveExistingOrder) {
            $order->items()->whereNotIn('id', $keepIds)->delete();
        }
    }



    private function finalizeDraftReservations(PreinvoiceOrder $order, ?string $reservationToken, array $products): void
    {
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
            $row->expires_at = null;
            $row->save();
        }
    }


    private function syncPreinvoiceReservationsAfterItemChange(PreinvoiceOrder $order, array $oldItems): void
    {
        $order->loadMissing('items');
        $newItems = $order->items->map(fn ($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => (int) $item->variant_id,
            'quantity' => (int) $item->quantity,
        ])->all();

        $this->applyFrozenStockDelta($oldItems, $newItems, true);
        $this->syncPreinvoiceReservations($order, true);
    }


    public function syncPreinvoiceReservations(PreinvoiceOrder $order, bool $stockAlreadyAdjusted = false): void
    {
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
            ->lockForUpdate()
            ->get()
            ->groupBy(fn (PreinvoiceDraftReservation $row) => ((int) $row->product_id) . ':' . ((int) $row->variant_id));

        foreach ($required as $key => $row) {
            $reservationRows = $rows->get($key, collect());
            $reservation = $reservationRows->first();
            $currentQty = (int) $reservationRows->sum('quantity');
            $requiredQty = (int) $row['quantity'];
            $delta = $requiredQty - $currentQty;

            if ($delta > 0 && ! $stockAlreadyAdjusted) {
                $this->reserveStockForItem((int) $row['product_id'], (int) $row['variant_id'], $delta);
            } elseif ($delta < 0 && ! $stockAlreadyAdjusted) {
                $this->releaseStockForItem((int) $row['product_id'], (int) $row['variant_id'], abs($delta));
            }

            if ($reservation) {
                $reservation->quantity = $requiredQty;
                $reservation->expires_at = null;
                $reservation->converted_at = $reservation->converted_at ?? now();
                $reservation->save();

                $duplicateIds = $reservationRows
                    ->skip(1)
                    ->pluck('id')
                    ->filter()
                    ->all();

                if (! empty($duplicateIds)) {
                    PreinvoiceDraftReservation::query()
                        ->whereIn('id', $duplicateIds)
                        ->delete();
                }
            } else {
                PreinvoiceDraftReservation::query()->create([
                    'token' => (string) Str::uuid(),
                    'user_id' => $order->created_by,
                    'preinvoice_order_id' => $order->id,
                    'product_id' => (int) $row['product_id'],
                    'variant_id' => (int) $row['variant_id'],
                    'quantity' => $requiredQty,
                    'expires_at' => null,
                    'converted_at' => now(),
                ]);
            }
        }

        foreach ($rows as $key => $reservationRows) {
            if (isset($required[$key])) {
                continue;
            }

            $releaseQuantity = (int) $reservationRows->sum('quantity');
            $firstReservation = $reservationRows->first();
            if (! $stockAlreadyAdjusted) {
                $this->releaseStockForItem((int) $firstReservation->product_id, (int) $firstReservation->variant_id, $releaseQuantity);
            }
            PreinvoiceDraftReservation::query()
                ->whereIn('id', $reservationRows->pluck('id')->filter()->all())
                ->delete();
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
            ->whereKey($variantId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) ($variant->sell_price ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'products' => 'قیمت فروش برای کالای ' . $this->variantSaleLabel($variant) . ' ثبت نشده است و امکان افزودن به پیش‌فاکتور وجود ندارد.',
            ]);
        }

        $available = $this->centralAvailableQty($variant);
        if ($available < $quantity) {
            $name = $this->variantSaleLabel($variant);
            throw ValidationException::withMessages([
                'products' => "موجودی آزاد مرکزی کالای {$name} کافی نیست. موجودی آزاد مرکزی: {$available} عدد.",
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

    private function reserveOrderStock(PreinvoiceOrder $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $this->reserveStockForItem((int) $item->product_id, (int) $item->variant_id, (int) $item->quantity);
        }
    }

    private function hasActiveFreeze(PreinvoiceOrder $order): bool
    {
        return is_null($order->stock_released_at);
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
        return is_null($order->stock_released_at) && PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->whereNotNull('converted_at')
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

    private function safeLegacyConflictInvoiceUuid(PreinvoiceOrder $order): string
    {
        $base = (string) $order->uuid . '-P' . (int) $order->id;
        $candidate = $base;
        $suffix = 2;

        while (Invoice::query()->where('uuid', $candidate)->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole(['Admin', 'finance', 'Accountant']) || $user->can('finance.approve'));
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

        $order = PreinvoiceOrder::with(['items.product', 'items.variant', 'creator:id,name'])
            ->where('uuid', $uuid)
            ->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE && ! ($order->invoice && (string) $order->invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL), 403);

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
        if ($order->status !== PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE && ! ($order->invoice && (string) $order->invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)) {
            if ($order->invoice) {
                return redirect()->route('invoices.show', $order->invoice->uuid)
                    ->with('success', 'این سند قبلاً تایید مالی شده و تغییر جدیدی برای تایید ندارد.');
            }

            abort(403);
        }

        $reapprovableInvoiceStatuses = [
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
            Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
        ];

        if ($order->invoice && ! in_array((string) $order->invoice->status, $reapprovableInvoiceStatuses, true)) {
            return redirect()->route('invoices.show', $order->invoice->uuid)
                ->with('success', 'این سند قبلاً تایید مالی شده و تغییر جدیدی برای تایید ندارد.');
        }

        $validated = $request->validate([
            'payments' => 'nullable|array',
            'payments.*.method' => 'required_with:payments|in:cash,cheque',
            'payments.*.amount' => 'required_with:payments|integer|min:1',
            'payments.*.paid_at' => 'required_with:payments|date',
            'payments.*.note' => 'nullable|string|max:2000',
            'payments.*.bank_name' => 'nullable|string|max:255',
            'payments.*.cheque_number' => 'nullable|string|max:255',
            'payments.*.received_at' => 'nullable|date',
            'payments.*.due_date' => 'nullable|date',
        ]);

        foreach (($validated['payments'] ?? []) as $index => $paymentRow) {
            if (($paymentRow['method'] ?? null) !== 'cheque') {
                continue;
            }

            $missing = [];
            foreach (['amount', 'cheque_number', 'bank_name', 'received_at', 'due_date'] as $field) {
                if (blank($paymentRow[$field] ?? null)) {
                    $missing["payments.{$index}.{$field}"] = 'این فیلد برای پرداخت چکی الزامی است.';
                }
            }

            if (! empty($missing)) {
                throw ValidationException::withMessages($missing);
            }
        }

        $invoice = DB::transaction(function () use ($order, $validated, $reapprovableInvoiceStatuses) {
            $lockedOrder = PreinvoiceOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();
            $order = $lockedOrder;
            $officialInvoiceUuid = $this->officialCodeForPreinvoiceConversion($order);
            $existingInvoices = Invoice::query()
                ->where('preinvoice_order_id', $order->id)
                ->lockForUpdate()
                ->get();

            if ($existingInvoices->count() > 1) {
                throw ValidationException::withMessages([
                    'preinvoice' => 'برای این پیش‌فاکتور بیش از یک فاکتور ثبت شده است و نیاز به بررسی دستی دارد.',
                ]);
            }

            $existingInvoice = $existingInvoices->first();
            $legacyConflictInvoice = Invoice::query()
                ->where('uuid', $officialInvoiceUuid)
                ->where('preinvoice_order_id', '!=', $order->id)
                ->lockForUpdate()
                ->first();

            if (! $existingInvoice && $legacyConflictInvoice) {
                $officialInvoiceUuid = $this->safeLegacyConflictInvoiceUuid($order);
            }

            if ($order->status !== PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE && ! ($existingInvoice && (string) $existingInvoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL)) {
                abort(403);
            }

            if ($existingInvoice && ! in_array((string) $existingInvoice->status, $reapprovableInvoiceStatuses, true)) {
                throw ValidationException::withMessages([
                    'preinvoice' => 'این سند قبلاً تایید مالی شده و تغییر جدیدی برای تایید ندارد.',
                ]);
            }

            $isFinanceReapproval = $existingInvoice && (string) $existingInvoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL;
            $oldInvoiceStatus = $existingInvoice ? (string) $existingInvoice->status : null;
            $oldInvoiceTotal = $existingInvoice ? (int) $existingInvoice->total : null;
            $shouldDeductOnFinalize = false;
            $centralStockMovedToReserve = $this->hasCentralStockMovedToReserve($order);

            foreach ($order->items as $it) {
                $variant = ProductVariant::query()->whereKey((int) $it->variant_id)->lockForUpdate()->first();
                if ($variant) {
                    $it->price = (int) ($variant->sell_price ?? 0);
                    $variant->save();
                }
            }
            $totals = SalesDocumentTotals::calculate($order->items, (int) $order->discount_amount, (int) $order->shipping_price);
            $subtotal = $totals['subtotal_before_discount'];
            $total = $totals['grand_total'];

            $requiredByVariant = $order->items
                ->groupBy('variant_id')
                ->map(fn($rows) => (int) $rows->sum('quantity'));

            if ($shouldDeductOnFinalize) {
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
                    'discount_amount' => (int) $order->discount_amount,
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
                    'document_date' => $order->display_document_date,

                    'customer_id' => $order->customer_id ?? null,
                    'customer_name' => $order->customer_name,
                    'customer_mobile' => $order->customer_mobile,
                    'customer_address' => $order->customer_address,
                    'province_id' => $order->province_id,
                    'city_id' => $order->city_id,

                    'shipping_id' => $order->shipping_id,
                    'shipping_price' => (int) $order->shipping_price,
                    'discount_amount' => (int) $order->discount_amount,
                    'subtotal' => (int) $subtotal,
                    'total' => (int) $total,
                    'status' => Invoice::STATUS_PENDING_COLLECTION,
                    'status_changed_at' => now(),
                    'status_changed_by' => auth()->id(),
                ]);
            }

            if ($legacyConflictInvoice && $invoice->wasRecentlyCreated) {
                ActivityLogger::log('legacy_invoice_number_conflict_resolved', $invoice->fresh(), 'به دلیل تداخل شماره فاکتور قدیمی، شماره امن جدید برای فاکتور تولید شد.', [
                    'preinvoice_order_id' => $order->id,
                    'preinvoice_uuid' => $order->uuid,
                    'generated_invoice_uuid' => $invoice->uuid,
                    'conflicting_invoice_id' => $legacyConflictInvoice->id,
                    'conflicting_invoice_uuid' => $legacyConflictInvoice->uuid,
                    'conflicting_preinvoice_order_id' => $legacyConflictInvoice->preinvoice_order_id,
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

                if ($shouldDeductOnFinalize) {
                    $product = Product::query()->whereKey((int) $it->product_id)->lockForUpdate()->first();
                    $before = (int) ($product?->stock ?? 0);

                    if (! $centralStockMovedToReserve) {
                        WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $it->product_id, -((int) $it->quantity), (int) $it->variant_id);
                        $product = Product::query()->whereKey((int) $it->product_id)->lockForUpdate()->first();
                    }

                    if ($product) {
                        $after = (int) $product->stock;
                        $product->update([
                            'reserved' => max(0, (int) $product->reserved - (int) $it->quantity),
                        ]);

                        StockMovement::create([
                            'product_id' => $product->id,
                            'user_id' => auth()->id(),
                            'type' => 'out',
                            'reason' => 'sale',
                            'quantity' => (int) $it->quantity,
                            'stock_before' => $before,
                            'stock_after' => $after,
                            'reference' => $invoice->uuid,
                            'note' => $centralStockMovedToReserve ? 'مصرف موجودی رزروشده بابت حواله فروش' : 'خروج از انبار مرکزی و مصرف رزرو بابت حواله فروش',
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

            ActivityLogger::log($isFinanceReapproval ? 'finance_reapproved' : 'finance_approved', $invoice->fresh(), $isFinanceReapproval ? 'فاکتور ویرایش‌شده توسط انبار مجدداً تایید مالی شد و به صف جمع‌آوری برگشت.' : 'پیش‌فاکتور توسط مالی تایید و به فاکتور/حواله در صف جمع‌آوری تبدیل شد.', [
                'preinvoice_order_id' => $order->id,
                'invoice_uuid' => $invoice->uuid,
                'old_status' => $oldInvoiceStatus,
                'new_status' => Invoice::STATUS_PENDING_COLLECTION,
                'old_total' => $oldInvoiceTotal,
                'new_total' => (int) $invoice->total,
                'total_changed' => $oldInvoiceTotal !== null && $oldInvoiceTotal !== (int) $invoice->total,
            ]);

            foreach (($validated['payments'] ?? []) as $paymentRow) {
                $payload = $paymentRow;

                $this->paymentService->registerForInvoice(
                    $invoice,
                    $payload,
                    $invoice->customer_id ? (int) $invoice->customer_id : null,
                    auth()->id()
                );
            }

            $order->update([
                'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                'total_price' => (int) $total,
                'stock_frozen_until' => null,
                'stock_released_at' => null,
            ]);

            if (! $isFinanceReapproval) {
                $this->notificationService->notifyRole(
                    'warehouse',
                    'invoice_ready_for_collection',
                    'فاکتور جدید آماده جمع‌آوری است',
                    "فاکتور شماره {$invoice->uuid} برای مشتری {$invoice->customer_name} تایید مالی شد و وارد بخش در حال جمع‌آوری شد.",
                    route('vouchers.sales.print', $invoice->uuid),
                    ['level' => 'success', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => "warehouse_invoice_ready:{$invoice->id}"]
                );
            }
            if (!empty($order->created_by)) {
                $this->notificationService->notifyUser(
                    (int)$order->created_by,
                    $isFinanceReapproval ? 'invoice_finance_reapproved' : 'preinvoice_finance_approved',
                    $isFinanceReapproval ? 'فاکتور شما مجدداً تایید مالی شد' : 'پیش‌فاکتور شما تایید مالی شد',
                    $isFinanceReapproval ? "فاکتور شماره {$invoice->uuid} برای مشتری {$order->customer_name} پس از ویرایش انبار مجدداً تایید مالی شد." : "پیش‌فاکتور مشتری {$order->customer_name} به فاکتور شماره {$invoice->uuid} تبدیل شد.",
                    route('invoices.show', $invoice->uuid),
                    ['level' => 'success', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => ($isFinanceReapproval ? "operator_finance_reapproved:{$order->id}:{$order->created_by}:" . now()->timestamp : "operator_finance_approved:{$order->id}:{$order->created_by}")]
                );
            }

            return $invoice;
        });

        return redirect()->route('invoices.show', $invoice->uuid)
            ->with('success', $invoice->wasRecentlyCreated
                ? '✅ تایید مالی انجام شد و فاکتور وارد بخش در حال جمع‌آوری شد.'
                : '✅ تایید مالی مجدد انجام شد و فاکتور به بخش در حال جمع‌آوری برگشت.');
    }

    public function financeCancel(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $order = PreinvoiceOrder::query()->with('invoice.items')->where('uuid', $uuid)->firstOrFail();
        abort_if($order->status !== PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE, 403);

        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        DB::transaction(function () use ($order, $data) {
            $oldStatus = (string) $order->status;
            $order->update([
                'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                'warehouse_reject_reason' => $data['reason'],
            ]);
            if ($order->invoice && (string) $order->invoice->status === Invoice::STATUS_PENDING_FINANCE_REAPPROVAL) {
                foreach ($order->invoice->items as $item) {
                    WarehouseStockService::change(WarehouseStockService::centralWarehouseId(), (int) $item->product_id, (int) $item->quantity, (int) $item->variant_id);
                }
                CustomerLedger::query()->where('reference_type', Invoice::class)->where('reference_id', $order->invoice->id)->delete();
                $order->invoice->update(['status' => Invoice::STATUS_NOT_SHIPPED, 'status_changed_at' => now(), 'status_changed_by' => auth()->id()]);
            } else {
                $this->releaseReservedStock($order);
            }
            $order->reviews()->create([
                'user_id' => auth()->id(),
                'action' => 'finance_rejected',
                'reason' => $data['reason'],
                'before_items' => $this->snapshotItems($order),
                'after_items' => $this->snapshotItems($order),
            ]);
            ActivityLogger::log('finance_rejected', $order->fresh(), 'پیش‌فاکتور توسط مالی رد شد.', [
                'old_status' => $oldStatus,
                'new_status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                'reason' => $data['reason'],
            ]);
        });

        return redirect()->route('preinvoice.draft.index')->with('success', '✅ پیش‌فاکتور با دلیل کنسل شد.');
    }
}
