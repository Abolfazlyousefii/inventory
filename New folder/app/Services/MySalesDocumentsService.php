<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class MySalesDocumentsService
{
    public const TAB_ACTIVE = 'active';

    public const TAB_DRAFTS = 'drafts';

    public const TAB_SHIPPED = 'shipped';

    public const TAB_NEEDS_CORRECTION = 'needs-correction';

    public const BUCKET_ACTIVE = 'active';

    public const BUCKET_DRAFT = 'draft';

    public const BUCKET_SHIPPED = 'shipped';

    public const BUCKET_NEEDS_CORRECTION = 'needs_correction';

    public const TAB_NEEDS_ACTION = self::TAB_NEEDS_CORRECTION;

    public const TAB_DOCUMENTS = self::TAB_ACTIVE;

    public const BUCKET_NEEDS_ACTION = self::BUCKET_NEEDS_CORRECTION;

    public const BUCKET_DOCUMENT = self::BUCKET_ACTIVE;

    public function filters(Request $request): array
    {
        return [
            'status' => (string) $request->query('status', ''),
            'q' => trim((string) $request->query('q', '')),
            'customer' => trim((string) $request->query('customer', '')),
            'type' => (string) $request->query('type', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
            'changed_only' => $request->boolean('changed_only'),
        ];
    }

    public function validTabs(): array
    {
        return [self::TAB_ACTIVE, self::TAB_DRAFTS, self::TAB_SHIPPED, self::TAB_NEEDS_CORRECTION];
    }

    public function tabToBucket(string $tab): string
    {
        return match ($tab) {
            self::TAB_DRAFTS => self::BUCKET_DRAFT,
            self::TAB_SHIPPED => self::BUCKET_SHIPPED,
            self::TAB_NEEDS_CORRECTION => self::BUCKET_NEEDS_CORRECTION,
            default => self::BUCKET_ACTIVE,
        };
    }

    public function bucketStatuses(string $bucket): array
    {
        return match ($bucket) {
            self::BUCKET_NEEDS_CORRECTION => [
                'preinvoice' => [
                    PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
                    PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
                    PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                ],
                'invoice' => [Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION],
            ],
            self::BUCKET_DRAFT => [
                'preinvoice' => [PreinvoiceOrder::STATUS_DRAFT],
                'invoice' => [],
            ],
            self::BUCKET_SHIPPED => [
                'preinvoice' => [],
                'invoice' => [Invoice::STATUS_SHIPPED],
            ],
            default => [
                'preinvoice' => [
                    PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
                    PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
                    PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
                    PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
                    PreinvoiceOrder::STATUS_PENDING_FINANCE,
                    PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
                    PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                ],
                'invoice' => [
                    Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL,
                    Invoice::STATUS_PENDING_COLLECTION,
                    Invoice::STATUS_WAREHOUSE_RECEIVED,
                    Invoice::STATUS_COLLECTING,
                    Invoice::STATUS_CHECKING_DISCREPANCY,
                    Invoice::STATUS_FINAL_CHECK,
                    Invoice::STATUS_PACKING,
                    Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
                    Invoice::STATUS_READY_TO_SHIP,
                ],
            ],
        };
    }

    public function defaultTab(array $counts, ?string $explicitTab): string
    {
        if ($explicitTab && in_array($explicitTab, $this->validTabs(), true)) {
            return $explicitTab;
        }

        return self::TAB_ACTIVE;
    }

    public function baseQuery(int $sellerId): Builder
    {
        $greatest = $this->greatestFunction();
        $invoiceActivitySql = "select {$greatest}(coalesce(invoices.updated_at, '1000-01-01'), coalesce(invoices.items_updated_at, '1000-01-01'), coalesce(invoices.shipped_at, '1000-01-01'), coalesce(invoices.status_changed_at, '1000-01-01')) from invoices where invoices.preinvoice_order_id = preinvoice_orders.id order by invoices.id desc limit 1";

        return PreinvoiceOrder::query()
            ->createdBySeller($sellerId)
            ->withoutTemporaryAutosaves()
            ->select('preinvoice_orders.*')
            ->selectSub("coalesce(($invoiceActivitySql), preinvoice_orders.updated_at)", 'activity_at')
            ->withCount('items')
            ->withSum('items as items_quantity_sum', 'quantity')
            ->with([
                'customer:id,first_name,last_name,mobile',
                'seller:id,name',
                'warehouseReviewer:id,name',
                'invoice' => fn ($invoiceQuery) => $invoiceQuery
                    ->select('id', 'uuid', 'preinvoice_order_id', 'status', 'total', 'items_updated_at', 'status_changed_at', 'status_changed_by', 'created_at', 'updated_at', 'customer_name', 'customer_mobile', 'shipped_at', 'shipping_id', 'collection_note')
                    ->withCount('items')
                    ->withSum('items as items_quantity_sum', 'quantity')
                    ->withSum('payments as paid_total', 'amount')
                    ->with(['shippingMethod:id,name', 'statusChangedByUser:id,name']),
            ]);
    }

    public function applyFilters(Builder $query, array $filters, array $allowedStatuses): Builder
    {
        if ($filters['q'] !== '') {
            $needle = '%'.$filters['q'].'%';
            $query->where(function ($q) use ($needle) {
                $q->where('uuid', 'like', $needle)
                    ->orWhere('customer_name', 'like', $needle)
                    ->orWhere('customer_mobile', 'like', $needle)
                    ->orWhereHas('customer', function ($cq) use ($needle) {
                        $cq->where('first_name', 'like', $needle)
                            ->orWhere('last_name', 'like', $needle)
                            ->orWhereRaw("concat(coalesce(first_name, ''), ' ', coalesce(last_name, '')) like ?", [$needle])
                            ->orWhere('mobile', 'like', $needle);
                    })
                    ->orWhereHas('invoice', function ($iq) use ($needle) {
                        $iq->where('uuid', 'like', $needle)
                            ->orWhere('customer_name', 'like', $needle)
                            ->orWhere('customer_mobile', 'like', $needle);
                    });
            });
        }
        if ($filters['customer'] !== '') {
            $needle = '%'.$filters['customer'].'%';
            $query->where(fn ($q) => $q->where('customer_name', 'like', $needle)->orWhere('customer_mobile', 'like', $needle)->orWhereHas('customer', fn ($cq) => $cq->where('first_name', 'like', $needle)->orWhere('last_name', 'like', $needle)->orWhere('mobile', 'like', $needle))->orWhereHas('invoice', fn ($iq) => $iq->where('customer_name', 'like', $needle)->orWhere('customer_mobile', 'like', $needle)));
        }
        if ($filters['type'] === 'preinvoice') {
            $query->doesntHave('invoice');
        }
        if ($filters['type'] === 'invoice') {
            $query->has('invoice');
        }
        if ($filters['date_from'] !== '') {
            $query->whereDate('preinvoice_orders.created_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $query->whereDate('preinvoice_orders.created_at', '<=', $filters['date_to']);
        }
        if ($filters['changed_only']) {
            $query->whereHas('invoice', fn ($iq) => $iq->whereNotNull('items_updated_at')->orWhereColumn('invoices.total', '<>', 'preinvoice_orders.total_price'));
        }

        if ($filters['status'] !== '' && in_array($filters['status'], array_merge($allowedStatuses['preinvoice'], $allowedStatuses['invoice']), true)) {
            $status = $filters['status'];
            $query->where(fn ($q) => $q->where(fn ($pq) => $pq->doesntHave('invoice')->where('status', $status))->orWhereHas('invoice', fn ($iq) => $iq->where('status', $status)));
        }

        return $query;
    }

    public function applyBucket(Builder $query, string $bucket): Builder
    {
        $statuses = $this->bucketStatuses($bucket);

        return $query->where(function ($q) use ($statuses) {
            $q->where(fn ($pq) => $pq->doesntHave('invoice')->whereIn('status', $statuses['preinvoice']))
                ->orWhereHas('invoice', fn ($iq) => $iq->whereIn('status', $statuses['invoice']));
        });
    }

    public function counts(int $sellerId): array
    {
        $out = [];
        foreach ([self::TAB_ACTIVE, self::TAB_DRAFTS, self::TAB_SHIPPED, self::TAB_NEEDS_CORRECTION] as $tab) {
            $out[$tab] = (clone $this->applyBucket($this->baseQuery($sellerId), $this->tabToBucket($tab)))->toBase()->getCountForPagination();
        }

        return $out;
    }

    public function paginate(int $sellerId, string $tab, array $filters): LengthAwarePaginator
    {
        $bucket = $this->tabToBucket($tab);
        $query = $this->applyBucket($this->baseQuery($sellerId), $bucket);
        $this->applyFilters($query, $filters, $this->bucketStatuses($bucket));
        if ($bucket === self::BUCKET_NEEDS_CORRECTION) {
            $greatest = $this->greatestFunction();
            $query->selectRaw("{$greatest}(coalesce((select invoices.status_changed_at from invoices where invoices.preinvoice_order_id = preinvoice_orders.id order by invoices.id desc limit 1), '1000-01-01'), coalesce(preinvoice_orders.stock_released_at, '1000-01-01'), coalesce(preinvoice_orders.items_updated_at, '1000-01-01'), coalesce(preinvoice_orders.updated_at, '1000-01-01')) as action_required_at")
                ->orderByDesc('action_required_at');
        } else {
            $query->orderByDesc('activity_at');
        }

        return $query->orderByDesc('preinvoice_orders.id')->paginate(20)->withQueryString();
    }

    private function greatestFunction(): string
    {
        return PreinvoiceOrder::query()->getConnection()->getDriverName() === 'sqlite' ? 'max' : 'greatest';
    }
}
