<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceiveWarehouseInboundReceiptRequest;
use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInboundReceipt;
use App\Services\WarehouseInboundService;
use Illuminate\Http\Request;

class WarehouseInboundController extends Controller
{
    public function __construct(private readonly WarehouseInboundService $service)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'requested_by' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:30'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'in:newest,oldest'],
        ]);

        $status = $filters['status'] ?? WarehouseInboundReceipt::STATUS_PENDING;
        $allowedStatuses = array_keys(WarehouseInboundReceipt::statusLabels());
        if ($status !== 'all' && ! in_array($status, $allowedStatuses, true)) {
            $status = WarehouseInboundReceipt::STATUS_PENDING;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        $sourceType = trim((string) ($filters['source_type'] ?? ''));
        if ($sourceType !== '' && ! array_key_exists($sourceType, WarehouseInboundReceipt::sourceLabels())) {
            $sourceType = '';
        }

        $receipts = WarehouseInboundReceipt::query()
            ->with(['requester:id,name', 'reviewer:id,name', 'items.suggestedWarehouse:id,name,type', 'items.receivedWarehouse:id,name,type'])
            ->withCount('items')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($sourceType !== '', fn ($query) => $query->where('source_type', $sourceType))
            ->when(! empty($filters['requested_by']), fn ($query) => $query->where('requested_by', (int) $filters['requested_by']))
            ->when(! empty($filters['warehouse_id']), function ($query) use ($filters) {
                $warehouseId = (int) $filters['warehouse_id'];
                $query->whereHas('items', fn ($items) => $items->where(function ($destinations) use ($warehouseId) {
                    $destinations->where('suggested_warehouse_id', $warehouseId)
                        ->orWhere('received_warehouse_id', $warehouseId);
                }));
            })
            ->when(! empty($filters['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($search) use ($q) {
                    $search->where('receipt_number', 'like', "%{$q}%")
                        ->orWhere('source_number_snapshot', 'like', "%{$q}%")
                        ->orWhere('customer_name_snapshot', 'like', "%{$q}%")
                        ->orWhereHas('items', function ($items) use ($q) {
                            $items->where('product_name_snapshot', 'like', "%{$q}%")
                                ->orWhere('variant_name_snapshot', 'like', "%{$q}%")
                                ->orWhere('sku_snapshot', 'like', "%{$q}%");
                        });

                    if (ctype_digit($q)) {
                        $search->orWhere('id', (int) $q);
                    }
                });
            })
            ->when(($filters['sort'] ?? 'newest') === 'oldest', fn ($query) => $query->orderBy('created_at')->orderBy('id'))
            ->when(($filters['sort'] ?? 'newest') !== 'oldest', fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'pending_count' => WarehouseInboundReceipt::query()->where('status', WarehouseInboundReceipt::STATUS_PENDING)->count(),
            'pending_quantity' => (int) WarehouseInboundReceipt::query()->where('status', WarehouseInboundReceipt::STATUS_PENDING)->sum('expected_quantity'),
            'discrepancy_count' => WarehouseInboundReceipt::query()->where('status', WarehouseInboundReceipt::STATUS_DISCREPANCY)->count(),
            'received_today_quantity' => (int) WarehouseInboundReceipt::query()
                ->whereIn('status', [WarehouseInboundReceipt::STATUS_RECEIVED, WarehouseInboundReceipt::STATUS_DISCREPANCY])
                ->whereDate('reviewed_at', today())
                ->sum('accepted_quantity'),
            'received_today_count' => WarehouseInboundReceipt::query()
                ->whereIn('status', [WarehouseInboundReceipt::STATUS_RECEIVED, WarehouseInboundReceipt::STATUS_DISCREPANCY])
                ->whereDate('reviewed_at', today())
                ->count(),
            'overdue_count' => WarehouseInboundReceipt::query()
                ->where('status', WarehouseInboundReceipt::STATUS_PENDING)
                ->where('created_at', '<=', now()->subDay())
                ->count(),
        ];

        $tabCounts = WarehouseInboundReceipt::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $requesterIds = WarehouseInboundReceipt::query()->whereNotNull('requested_by')->distinct()->pluck('requested_by');
        $requesters = User::query()->whereIn('id', $requesterIds)->orderBy('name')->get(['id', 'name']);
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->whereIn('type', ['central', 'return'])
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('warehouse.inbound-queue.index', [
            'receipts' => $receipts,
            'filters' => $filters + ['status' => $status, 'source_type' => $sourceType],
            'stats' => $stats,
            'tabCounts' => $tabCounts,
            'requesters' => $requesters,
            'warehouses' => $warehouses,
            'sourceLabels' => WarehouseInboundReceipt::sourceLabels(),
            'statusLabels' => WarehouseInboundReceipt::statusLabels(),
        ]);
    }

    public function show(WarehouseInboundReceipt $receipt)
    {
        $receipt->load([
            'items.product',
            'items.variant',
            'items.suggestedWarehouse',
            'items.receivedWarehouse',
            'requester:id,name',
            'reviewer:id,name',
        ]);

        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->whereIn('type', ['central', 'return'])
            ->orderByRaw("case when type = 'central' then 0 else 1 end")
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $sourceUrl = $this->sourceUrl($receipt);

        $html = view('warehouse.inbound-queue.partials.receipt', compact('receipt', 'warehouses', 'sourceUrl'))->render();

        if (request()->expectsJson() || request()->ajax()) {
            return response()->json(['ok' => true, 'html' => $html]);
        }

        return view('warehouse.inbound-queue.show', compact('receipt', 'warehouses', 'sourceUrl'));
    }

    public function receive(ReceiveWarehouseInboundReceiptRequest $request, WarehouseInboundReceipt $receipt)
    {
        $updated = $this->service->receive(
            $receipt,
            $request->validated('items'),
            (int) $request->user()->id,
            $request->validated('review_note')
        );

        $message = $updated->status === WarehouseInboundReceipt::STATUS_DISCREPANCY
            ? 'دریافت با مغایرت ثبت شد و فقط تعداد تأییدشده وارد موجودی شد.'
            : 'دریافت کالا تأیید شد و موجودی با موفقیت بروزرسانی شد.';

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'redirect_url' => route('warehouse.inbound.index')]);
        }

        return redirect()->route('warehouse.inbound.index')->with('success', $message);
    }

    private function sourceUrl(WarehouseInboundReceipt $receipt): ?string
    {
        if ($receipt->source_type === WarehouseInboundReceipt::SOURCE_SALES_RETURN) {
            return SalesReturnDocument::query()->whereKey($receipt->source_id)->exists()
                ? route('vouchers.return-from-sale.show', $receipt->source_id)
                : null;
        }

        if (in_array($receipt->source_type, [
            WarehouseInboundReceipt::SOURCE_INVOICE_CANCEL,
            WarehouseInboundReceipt::SOURCE_INVOICE_ADJUSTMENT,
            WarehouseInboundReceipt::SOURCE_FINANCE_ADJUSTMENT_LEGACY,
        ], true)) {
            $invoice = Invoice::query()->whereKey($receipt->source_id)->first(['uuid']);

            return $invoice ? route('vouchers.sales.show', $invoice->uuid) : null;
        }

        return null;
    }
}
