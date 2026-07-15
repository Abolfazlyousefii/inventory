<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Models\WarehouseReviewLog;
use App\Services\WarehouseReviewAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WarehouseReviewController extends Controller
{
    public function __construct(private readonly WarehouseReviewAuditService $auditService)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'uuid' => ['nullable', 'string', 'max:50'],
            'customer' => ['nullable', 'string', 'max:255'],
            'creator_id' => ['nullable', 'integer', 'exists:users,id'],
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'max:80'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'changed_only' => ['nullable', 'boolean'],
            'rejected_only' => ['nullable', 'boolean'],
        ]);

        $orders = PreinvoiceOrder::query()
            ->with(['creator:id,name', 'warehouseReviewer:id,name'])
            ->withCount(['items', 'warehouseReviewItemLogs as changes_count'])
            ->withMax('warehouseReviewLogs as last_review_action_at', 'created_at')
            ->where(function (Builder $query) {
                $query->whereHas('warehouseReviewSnapshots')
                    ->orWhereHas('warehouseReviewLogs')
                    ->orWhereIn('status', [
                        PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                        PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
                        PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                        PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
                    ]);
            })
            ->when(!empty($filters['uuid']), fn (Builder $query) => $query->where('uuid', 'like', '%' . $filters['uuid'] . '%'))
            ->when(!empty($filters['customer']), fn (Builder $query) => $query->where('customer_name', 'like', '%' . $filters['customer'] . '%'))
            ->when(!empty($filters['creator_id']), fn (Builder $query) => $query->where('created_by', (int) $filters['creator_id']))
            ->when(!empty($filters['reviewer_id']), fn (Builder $query) => $query->where('warehouse_reviewed_by', (int) $filters['reviewer_id']))
            ->when(!empty($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['date_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->when((bool) ($filters['changed_only'] ?? false), fn (Builder $query) => $query->whereHas('warehouseReviewItemLogs'))
            ->when((bool) ($filters['rejected_only'] ?? false), fn (Builder $query) => $query->where('status', PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE))
            ->orderByDesc('last_review_action_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $users = User::query()->orderBy('name')->get(['id', 'name']);
        $statusLabels = PreinvoiceOrder::statusLabels();

        return view('warehouse-reviews.index', compact('orders', 'filters', 'users', 'statusLabels'));
    }

    public function show(PreinvoiceOrder $preinvoiceOrder)
    {
        $order = $this->loadOrder($preinvoiceOrder);
        $comparisonRows = $this->auditService->compareRows($order);
        $timeline = $order->warehouseReviewLogs->sortBy('created_at')->values();
        $hasHistoricalSnapshot = $order->warehouseReviewSnapshots->isNotEmpty();

        return view('warehouse-reviews.show', compact('order', 'comparisonRows', 'timeline', 'hasHistoricalSnapshot'));
    }

    public function print(PreinvoiceOrder $preinvoiceOrder)
    {
        $order = $this->loadOrder($preinvoiceOrder);
        $comparisonRows = $this->auditService->compareRows($order);
        $timeline = $order->warehouseReviewLogs->sortBy('created_at')->values();

        return view('warehouse-reviews.print', compact('order', 'comparisonRows', 'timeline'));
    }

    private function loadOrder(PreinvoiceOrder $order): PreinvoiceOrder
    {
        return $order->load([
            'items.product',
            'items.variant',
            'creator:id,name',
            'warehouseReviewer:id,name',
            'warehouseReviewSnapshots' => fn ($query) => $query->latest(),
            'warehouseReviewLogs.user:id,name',
            'warehouseReviewItemLogs.user:id,name',
            'invoice:id,uuid,preinvoice_order_id,created_at,document_date',
        ]);
    }
}
