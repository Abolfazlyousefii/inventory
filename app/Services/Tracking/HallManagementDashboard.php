<?php

namespace App\Services\Tracking;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only projection. Money in the source tables is already in rials. */
final class HallManagementDashboard
{
    private const STAGES = [
        'reserved' => 'رزرو فعال', 'finance' => 'صف مالی',
        'warehouse' => 'صف انبار', 'shipping' => 'صف ارسال',
    ];

    public static function stage(string $type, string $status): ?string
    {
        if ($type === 'preinvoice') {
            return match ($status) {
                'draft', 'returned_to_sales' => 'draft',
                'pending_finance', 'reserved_waiting_warehouse' => 'reserved',
                'finance_reviewing', 'warehouse_approved_waiting_finance' => 'finance',
                'warehouse_reviewing', 'returned_to_warehouse' => 'warehouse',
                default => null,
            };
        }

        return match ($status) {
            'returned_to_sales_after_collection' => 'draft',
            'pending_finance_reapproval' => 'finance',
            'pending_warehouse_approval', 'pending_collection', 'warehouse_received',
            'collecting', 'checking_discrepancy', 'final_check' => 'warehouse',
            'packing', 'ready_to_ship' => 'shipping',
            default => null,
        };
    }

    public function snapshot(): array
    {
        // Keep conversion and its parent exclusion in one consistent read snapshot.
        return DB::transaction(fn () => $this->buildSnapshot());
    }

    private function buildSnapshot(): array
    {
        $now = CarbonImmutable::now();
        $invoices = $this->invoices();
        $orders = $this->preinvoices($now);
        $invoiceHistory = $invoices->isEmpty() ? collect() : DB::table('sales_havaleh_histories')
            ->whereIn('invoice_id', $invoices->pluck('id'))->where('field_name', 'status')
            ->orderByDesc('done_at')->orderByDesc('id')
            ->get(['invoice_id', 'old_value', 'new_value', 'done_at'])->groupBy('invoice_id');
        $orderHistory = $orders->isEmpty() ? collect() : DB::table('warehouse_review_logs')
            ->whereIn('preinvoice_order_id', $orders->pluck('id'))->whereNotNull('status_to')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['preinvoice_order_id', 'status_from', 'status_to', 'created_at'])->groupBy('preinvoice_order_id');

        $documents = $invoices->map(fn ($row) => $this->document($row, 'invoice', $invoiceHistory->get($row->id, collect()), $now))
            ->concat($orders->map(fn ($row) => $this->document($row, 'preinvoice', $orderHistory->get($row->id, collect()), $now)))
            ->sortBy('created_at')->values();

        if ($documents->isNotEmpty() && Schema::hasTable('hall_queue_transitions')) {
            $entries = DB::table('hall_queue_transitions')->whereIn('workflow_key', $documents->pluck('key'))
                ->orderByDesc('entered_at')->orderByDesc('id')->get()->groupBy('workflow_key');
            $documents = $documents->map(function (array $doc) use ($entries, $now): array {
                $entry = $entries->get($doc['key'])?->first();
                if ($entry && $doc['stage'] !== 'reserved' && $entry->source_type === $doc['type'] && (int) $entry->source_id === $doc['source_id'] && self::stage($entry->source_type, $entry->status_to) === $doc['stage']) {
                    $entered = $this->time($entry->entered_at);
                    $doc['stage_entered_at'] = $entered->toIso8601String();
                    $doc['stage_time_known'] = true;
                    $doc['stage_seconds'] = max(0, $now->timestamp - $entered->timestamp);
                }

                return $doc;
            });
        }

        return [
            'version' => 2,
            'updated_at' => $now->toIso8601String(),
            'refresh_seconds' => max(5, (int) config('hall_display.refresh_seconds', 15)),
            'metrics' => $this->metrics($now),
            'summary' => ['total' => $documents->count()],
            'stages' => collect(self::STAGES)->map(function ($label, $key) use ($documents) {
                $items = $documents->where('stage', $key);
                $known = $items->where('stage_time_known', true);

                return [
                    'key' => $key, 'label' => $label, 'count' => $items->count(),
                    'average_seconds' => $known->isEmpty() ? null : (int) round($known->avg('stage_seconds')),
                    'known_time_count' => $known->count(),
                    'warning_minutes' => max(1, (int) config('hall_display.warning_minutes.'.$key, 45)),
                ];
            })->values()->all(),
            'documents' => $documents->all(),
            'events' => $this->events(),
        ];
    }

    private function invoices(): Collection
    {
        $counts = DB::table('invoice_items')->where('quantity', '>', 0)->groupBy('invoice_id')
            ->selectRaw('invoice_id, COUNT(*) AS item_count, SUM(quantity) AS unit_count');

        return DB::table('invoices as d')
            ->leftJoin('preinvoice_orders as parent', 'parent.id', '=', 'd.preinvoice_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('users as seller', 'seller.id', '=', DB::raw('COALESCE(d.seller_id, parent.seller_id, parent.created_by)'))
            ->leftJoinSub($counts, 'lines', 'lines.invoice_id', '=', 'd.id')
            ->whereIn('d.status', $this->statuses('invoice'))
            ->get([
                'd.id', 'd.uuid', 'd.status', 'd.preinvoice_order_id', 'd.created_at', 'd.updated_at',
                'd.customer_name', 'c.name', 'c.company_name', 'c.first_name', 'c.last_name',
                'seller.name as seller_name', 'lines.item_count', 'lines.unit_count',
                'd.total as amount', 'parent.created_at as origin_created_at',
            ]);
    }

    private function preinvoices(CarbonImmutable $now): Collection
    {
        $counts = DB::table('preinvoice_order_items')->where('quantity', '>', 0)->groupBy('preinvoice_order_id')
            ->selectRaw('preinvoice_order_id, COUNT(*) AS item_count, SUM(quantity) AS unit_count');
        $held = DB::table('preinvoice_draft_reservations')
            ->where('reservation_scope', 'official')->where('quantity', '>', 0)
            ->whereNull('converted_at')->whereNull('released_at')->whereNull('release_reason')
            ->groupBy('preinvoice_order_id')
            ->selectRaw('preinvoice_order_id, MIN(created_at) AS reserved_at, SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS expired_lines', [$now]);
        $cutoff = $now->subHours(min(24, max(1, (int) config('hall_display.reservation_hours', 24))));

        return DB::table('preinvoice_orders as d')
            ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
            ->leftJoin('users as seller', 'seller.id', '=', DB::raw('COALESCE(d.seller_id, d.created_by)'))
            ->leftJoinSub($counts, 'lines', 'lines.preinvoice_order_id', '=', 'd.id')
            ->joinSub($held, 'held', 'held.preinvoice_order_id', '=', 'd.id')
            ->whereIn('d.status', $this->statuses('preinvoice'))
            ->whereIn('d.status', PreinvoiceOrder::reservationHoldingStatuses())
            ->whereNull('d.stock_released_at')
            ->where(fn ($q) => $q->whereNull('d.stock_frozen_until')->orWhere('d.stock_frozen_until', '>', $now))
            ->where('held.expired_lines', 0)->where('held.reserved_at', '>', $cutoff)->where('held.reserved_at', '<=', $now)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices')->whereColumn('invoices.preinvoice_order_id', 'd.id'))
            ->get([
                'd.id', 'd.uuid', 'd.status', 'd.created_at', 'd.updated_at', 'd.customer_name',
                'c.name', 'c.company_name', 'c.first_name', 'c.last_name', 'seller.name as seller_name',
                'lines.item_count', 'lines.unit_count', 'd.total_price as amount', 'held.reserved_at',
            ]);
    }

    private function statuses(string $type): array
    {
        $statuses = $type === 'invoice' ? array_keys(Invoice::statusLabels()) : array_keys(PreinvoiceOrder::statusLabels());

        return array_values(array_filter($statuses, fn ($status) => array_key_exists(self::stage($type, $status) ?? '', self::STAGES)));
    }

    private function document(object $row, string $type, Collection $history, CarbonImmutable $now): array
    {
        $stage = self::stage($type, $row->status);
        $created = $this->time($row->origin_created_at ?? $row->created_at);
        $entered = null;
        foreach ($history as $event) {
            $from = self::stage($type, (string) ($event->old_value ?? $event->status_from ?? ''));
            $to = self::stage($type, (string) ($event->new_value ?? $event->status_to ?? ''));
            if ($from === $to) {
                continue;
            } // Internal review/collection changes do not reset a queue clock.
            if ($to === $stage) {
                $entered = $this->time($event->done_at ?? $event->created_at);
            }
            break;
        }
        if ($type === 'preinvoice' && $stage === 'reserved') {
            $entered = $this->time($row->reserved_at);
        }
        $customer = trim((string) ($row->customer_name ?: $row->name ?: $row->company_name ?: trim(($row->first_name ?? '').' '.($row->last_name ?? ''))));

        return [
            'key' => 'order:'.($row->preinvoice_order_id ?? ($type === 'preinvoice' ? $row->id : 'invoice:'.$row->id)),
            'type' => $type, 'source_id' => (int) $row->id, 'number' => (string) $row->uuid, 'stage' => $stage,
            'status' => $row->status,
            'status_label' => ($type === 'invoice' ? Invoice::statusLabels() : PreinvoiceOrder::statusLabels())[$row->status] ?? $row->status,
            'customer' => $customer !== '' ? $customer : 'مشتری ثبت نشده',
            'seller' => (string) ($row->seller_name ?? ''),
            'amount_rial' => (int) $row->amount, 'item_count' => (int) ($row->item_count ?? 0),
            'unit_count' => (int) ($row->unit_count ?? 0),
            'created_at' => $created?->toIso8601String(),
            'stage_entered_at' => $entered?->toIso8601String(),
            'stage_time_known' => $entered !== null,
            'stage_seconds' => $entered ? max(0, $now->timestamp - $entered->timestamp) : null,
            'total_seconds' => $created ? max(0, $now->timestamp - $created->timestamp) : null,
        ];
    }

    private function metrics(CarbonImmutable $now): array
    {
        // Same receipt basis as PaymentReportService: one receipt per payment,
        // latest cheque metadata, cheque received date rather than its due date.
        $day = $now->setTimezone('Asia/Tehran')->toDateString();
        $end = CarbonImmutable::parse($day, 'Asia/Tehran')->addDay()->toDateString();
        $sales = DB::table('invoices')->where('status', '!=', 'not_shipped')
            ->whereRaw('COALESCE(document_date, created_at) >= ?', [$day])
            ->whereRaw('COALESCE(document_date, created_at) < ?', [$end]);
        $latest = DB::table('cheques')->selectRaw('invoice_payment_id, MAX(id) AS latest_id')->groupBy('invoice_payment_id');
        $receipts = DB::table('invoice_payments as p')
            ->leftJoinSub($latest, 'latest_cheque', 'latest_cheque.invoice_payment_id', '=', 'p.id')
            ->leftJoin('cheques as c', 'c.id', '=', 'latest_cheque.latest_id')
            ->whereIn('p.method', ['cash', 'online', 'cheque'])
            ->whereRaw("CASE WHEN p.method = 'cheque' THEN COALESCE(c.received_at, p.paid_at) ELSE p.paid_at END >= ?", [$day])
            ->whereRaw("CASE WHEN p.method = 'cheque' THEN COALESCE(c.received_at, p.paid_at) ELSE p.paid_at END < ?", [$end])
            ->selectRaw('p.method, COUNT(*) AS count, COALESCE(SUM(p.amount), 0) AS amount')->groupBy('p.method')->get()->keyBy('method');

        return [
            'day' => $day, 'sales_rial' => (int) (clone $sales)->sum('total'), 'sales_count' => (clone $sales)->count(),
            'cash_rial' => (int) ($receipts->get('cash')->amount ?? 0) + (int) ($receipts->get('online')->amount ?? 0),
            'cheque_rial' => (int) ($receipts->get('cheque')->amount ?? 0),
            'cheque_count' => (int) ($receipts->get('cheque')->count ?? 0),
        ];
    }

    private function events(): array
    {
        $invoices = DB::table('sales_havaleh_histories as h')->join('invoices as d', 'd.id', '=', 'h.invoice_id')
            ->where('h.field_name', 'status')->orderByDesc('h.done_at')->orderByDesc('h.id')->limit(12)
            ->get(['h.id', 'h.old_value', 'h.new_value', 'h.done_at', 'd.uuid', 'd.preinvoice_order_id', 'd.id as invoice_id'])
            ->map(fn ($row) => [
                'id' => 'invoice-history:'.$row->id,
                'key' => 'order:'.($row->preinvoice_order_id ?? 'invoice:'.$row->invoice_id),
                'number' => (string) $row->uuid, 'from' => self::stage('invoice', (string) $row->old_value),
                'to' => self::stage('invoice', (string) $row->new_value),
                'label' => Invoice::statusLabels()[$row->new_value] ?? 'وضعیت سند تغییر کرد',
                'at' => $this->time($row->done_at)?->toIso8601String(),
            ]);
        $orders = DB::table('warehouse_review_logs as h')->join('preinvoice_orders as d', 'd.id', '=', 'h.preinvoice_order_id')
            ->whereNotNull('h.status_to')->whereColumn('h.status_to', '!=', 'h.status_from')
            ->orderByDesc('h.created_at')->orderByDesc('h.id')->limit(12)
            ->get(['h.id', 'h.status_from', 'h.status_to', 'h.created_at', 'd.uuid', 'd.id as order_id'])
            ->map(fn ($row) => [
                'id' => 'preinvoice-history:'.$row->id, 'key' => 'order:'.$row->order_id,
                'number' => (string) $row->uuid, 'from' => self::stage('preinvoice', (string) $row->status_from),
                'to' => self::stage('preinvoice', (string) $row->status_to),
                'label' => PreinvoiceOrder::statusLabels()[$row->status_to] ?? 'وضعیت سند تغییر کرد',
                'at' => $this->time($row->created_at)?->toIso8601String(),
            ]);

        // A current terminal state also explains a card leaving the queues even
        // when a legacy cancellation path did not write a dedicated history row.
        $terminal = DB::table('preinvoice_orders')->whereIn('status', ['cancelled_by_warehouse', 'cancelled_by_finance', 'reservation_expired'])
            ->where('updated_at', '>=', now()->subDay())->orderByDesc('updated_at')->limit(12)
            ->get(['id', 'uuid', 'status', 'updated_at'])->map(fn ($row) => [
                'id' => 'preinvoice-outcome:'.$row->id.':'.$row->status, 'key' => 'order:'.$row->id,
                'number' => (string) $row->uuid, 'from' => null, 'to' => null,
                'label' => PreinvoiceOrder::statusLabels()[$row->status],
                'at' => $this->time($row->updated_at)?->toIso8601String(),
            ]);

        $tracked = collect();
        if (Schema::hasTable('hall_queue_transitions')) {
            $tracked = DB::table('hall_queue_transitions')
                ->where(fn ($q) => $q->whereIn('from_stage', array_keys(self::STAGES))->orWhereIn('to_stage', array_keys(self::STAGES)))
                ->orderByDesc('entered_at')->orderByDesc('id')->limit(12)->get()
                ->map(fn ($row) => [
                    'id' => 'hall:'.$row->id, 'key' => $row->workflow_key, 'number' => $row->document_number,
                    'from' => self::stage($row->source_type, (string) $row->status_from), 'to' => self::stage($row->source_type, $row->status_to),
                    'label' => $row->status_to === 'deleted' ? 'حذف شد' : (($row->source_type === 'invoice' ? Invoice::statusLabels() : PreinvoiceOrder::statusLabels())[$row->status_to] ?? 'وضعیت سند تغییر کرد'),
                    'at' => $this->time($row->entered_at)?->toIso8601String(),
                ]);
        }

        return $invoices->concat($orders)->concat($terminal)->concat($tracked)->sortByDesc('at')->take(12)->values()->all();
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, config('app.timezone')) : null;
    }
}
