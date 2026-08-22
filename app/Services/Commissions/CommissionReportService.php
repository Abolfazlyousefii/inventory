<?php

namespace App\Services\Commissions;

use App\Models\CommissionAdjustment;
use App\Models\CommissionCorrectionEntry;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommissionReportService
{
    public function periodSummary(CommissionPeriod $period, ?int $sellerId = null): array
    {
        $query = $this->active($period)->when($sellerId, fn ($builder) => $builder->where('seller_id', $sellerId));
        $netSales = (int) (clone $query)->sum('net_amount_snapshot');
        $totalCommission = (int) (clone $query)->sum('total_commission_amount');

        $corrections = CommissionCorrectionEntry::query()->where('commission_period_id', $period->id)
            ->when($sellerId, fn ($builder) => $builder->where('seller_id', $sellerId));
        $returnReversals = (int) (clone $corrections)->whereIn('event_type', ['return_reversal', 'return_reversal_cancelled'])->sum('total_commission_amount');
        $sellerCorrections = (int) (clone $corrections)->where('event_type', 'seller_reassignment_correction')->sum('total_commission_amount');
        $manualAdjustments = (int) CommissionAdjustment::query()->where('commission_period_id', $period->id)->where('status', 'approved')
            ->when($sellerId, fn ($builder) => $builder->where('seller_id', $sellerId))->sum('amount');

        return [
            'eligible_invoice_count' => (clone $query)->distinct()->count('invoice_id'),
            'eligible_item_count' => (clone $query)->count(),
            'net_sales_amount' => $netSales,
            'base_commission_amount' => (int) (clone $query)->sum('base_commission_amount'),
            'campaign_commission_amount' => (int) (clone $query)->sum('campaign_commission_amount'),
            'total_commission_amount' => $totalCommission,
            'return_reversal_amount' => $returnReversals,
            'seller_correction_amount' => $sellerCorrections,
            'manual_adjustment_amount' => $manualAdjustments,
            'net_commission_amount' => $totalCommission + $returnReversals + $sellerCorrections + $manualAdjustments,
            'effective_rate' => $netSales > 0 ? ($totalCommission * 100 / $netSales) : 0,
            'missing_rate_item_count' => (clone $query)->where('missing_rate', true)->count(),
            'missing_seller_invoice_count' => $sellerId ? 0 : $period->calculationWarnings()->where('code', 'missing_seller')->distinct()->count('invoice_id'),
        ];
    }

    public function sellerSummaries(CommissionPeriod $period, ?int $sellerId = null): Collection
    {
        $rows = $this->active($period)->join('users', 'users.id', '=', 'commission_ledger_entries.seller_id')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->groupBy('seller_id', 'users.name')->orderBy('users.name')
            ->selectRaw('seller_id, users.name as seller_name, count(distinct invoice_id) as invoice_count, count(*) as item_count')
            ->selectRaw('sum(net_amount_snapshot) as net_sales, sum(base_commission_amount) as base_commission, sum(campaign_commission_amount) as campaign_commission, sum(total_commission_amount) as total_commission')
            ->selectRaw('case when sum(net_amount_snapshot) > 0 then (sum(total_commission_amount) * 100.0 / sum(net_amount_snapshot)) else 0 end as effective_rate')
            ->selectRaw('sum(case when missing_rate = 1 then 1 else 0 end) as missing_rate_count')->get();
        $correctionQuery = CommissionCorrectionEntry::query()->where('commission_period_id', $period->id)
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->select(['seller_id', 'event_type', DB::raw('total_commission_amount as amount')]);
        $adjustmentQuery = CommissionAdjustment::query()->where('commission_period_id', $period->id)->where('status', 'approved')
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId))
            ->select(['seller_id', DB::raw("'manual_adjustment' as event_type"), 'amount']);
        $financialEvents = $correctionQuery->unionAll($adjustmentQuery)->get()->groupBy('seller_id');

        $missingSellerIds = $financialEvents->keys()->diff($rows->pluck('seller_id'));
        if ($missingSellerIds->isNotEmpty()) {
            $users = User::query()->whereIn('id', $missingSellerIds)->pluck('name', 'id');
            foreach ($missingSellerIds as $missingSellerId) {
                $rows->push((object) ['seller_id' => $missingSellerId, 'seller_name' => $users[$missingSellerId] ?? '#'.$missingSellerId,
                    'invoice_count' => 0, 'item_count' => 0, 'net_sales' => 0, 'base_commission' => 0, 'campaign_commission' => 0,
                    'total_commission' => 0, 'effective_rate' => 0, 'missing_rate_count' => 0]);
            }
        }

        return $rows->map(function ($row) use ($financialEvents) {
            $sellerCorrections = $financialEvents->get($row->seller_id, collect());
            $row->sales_commission = (int) $row->total_commission;
            $row->return_reversal = (int) $sellerCorrections->whereIn('event_type', ['return_reversal', 'return_reversal_cancelled'])->sum('amount');
            $row->historical_corrections = (int) $sellerCorrections->where('event_type', 'seller_reassignment_correction')->sum('amount');
            $row->manual_adjustments = (int) $sellerCorrections->where('event_type', 'manual_adjustment')->sum('amount');
            $row->net_commission = $row->sales_commission + $row->return_reversal + $row->historical_corrections + $row->manual_adjustments;

            return $row;
        });
    }

    public function sellerSummariesFor(CommissionPeriod $period, User $viewer): Collection
    {
        $canViewAll = $viewer->hasPermission('commissions.view_seller_details');

        return $this->sellerSummaries($period, $canViewAll ? null : $viewer->id);
    }

    public function sellerDetails(CommissionPeriod $period, User $seller, int $perPage = 30, array $filters = []): LengthAwarePaginator
    {
        $ledger = DB::table('commission_ledger_entries')
            ->where('commission_period_id', $period->id)
            ->where('seller_id', $seller->id)
            ->where('status', CommissionLedgerEntry::STATUS_ACTIVE)
            ->whereNotNull('invoice_id')
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, COUNT(*) as items_count')
            ->selectRaw('MAX(invoice_number_snapshot) as invoice_number_snapshot, MAX(invoice_date_snapshot) as invoice_date_snapshot')
            ->selectRaw('COALESCE(SUM(net_amount_snapshot), 0) as net_sales_amount')
            ->selectRaw('COALESCE(SUM(base_commission_amount), 0) as base_commission_amount')
            ->selectRaw('COALESCE(SUM(campaign_commission_amount), 0) as campaign_commission_amount')
            ->selectRaw('COALESCE(SUM(total_commission_amount), 0) as total_commission_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN missing_rate = 1 THEN 1 ELSE 0 END), 0) as missing_rate_count');

        $query = DB::query()->fromSub($ledger, 'ledger')
            ->leftJoin('invoices', 'invoices.id', '=', 'ledger.invoice_id')
            ->select([
                'ledger.*', 'invoices.uuid', 'invoices.customer_name', 'invoices.status as invoice_status',
                'invoices.total as invoice_total', 'invoices.document_date',
            ]);

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($nested) use ($like): void {
                $nested->where('ledger.invoice_number_snapshot', 'like', $like)
                    ->orWhere('invoices.uuid', 'like', $like)
                    ->orWhere('invoices.customer_name', 'like', $like);
            });
        }
        if (! empty($filters['status'])) {
            $query->where('invoices.status', $filters['status']);
        }
        if (! empty($filters['missing_rate'])) {
            $query->where('ledger.missing_rate_count', '>', 0);
        }

        return $query->orderByDesc('ledger.invoice_date_snapshot')
            ->orderByDesc('ledger.invoice_id')
            ->paginate($perPage, ['*'], 'invoices_page')
            ->withQueryString();
    }

    public function invoiceEntries(CommissionPeriod $period, User $seller, Invoice $invoice): Collection
    {
        $entries = $this->active($period)
            ->where('seller_id', $seller->id)
            ->where('invoice_id', $invoice->id)
            ->orderBy('invoice_item_id')
            ->get();

        abort_if($entries->isEmpty(), 404);

        $labels = collect();
        foreach (['category' => 'categories', 'product' => 'products', 'variant' => 'product_variants'] as $type => $table) {
            $ids = $entries->where('rate_source_type', $type)->pluck('rate_source_id')->filter()->unique();
            if ($ids->isEmpty()) {
                continue;
            }
            $nameColumn = $type === 'variant' ? 'variant_name' : 'name';
            DB::table($table)->whereIn('id', $ids)->pluck($nameColumn, 'id')
                ->each(fn ($name, $id) => $labels->put($type.':'.$id, $name));
        }

        return $entries->each(function (CommissionLedgerEntry $entry) use ($labels): void {
            $entry->setAttribute('rate_source_label', $labels->get($entry->rate_source_type.':'.$entry->rate_source_id));
        });
    }

    public function sellerAdjustments(CommissionPeriod $period, User $seller, int $perPage = 30): LengthAwarePaginator
    {
        return CommissionAdjustment::query()
            ->where('commission_period_id', $period->id)
            ->where('seller_id', $seller->id)
            ->latest()
            ->paginate($perPage, ['*'], 'adjustments_page');
    }

    public function conflictingSellerInvoices(CommissionPeriod $period, ?int $sellerId = null): Collection
    {
        return $this->active($period)
            ->whereNotNull('invoice_id')
            ->when($sellerId, fn ($query) => $query->whereIn('invoice_id', $this->active($period)
                ->where('seller_id', $sellerId)->whereNotNull('invoice_id')->select('invoice_id')))
            ->groupBy('invoice_id')
            ->havingRaw('COUNT(DISTINCT seller_id) > 1')
            ->selectRaw('invoice_id, COUNT(DISTINCT seller_id) as seller_count')
            ->pluck('seller_count', 'invoice_id');
    }

    public function sellerAudit(CommissionPeriod $period, User $seller): array
    {
        $invoiceRows = $this->sellerDetails($period, $seller, PHP_INT_MAX)->getCollection();
        $summary = $this->periodSummary($period, $seller->id);
        $invoiceCommission = (int) $invoiceRows->sum('total_commission_amount');

        return [
            'seller' => $seller,
            'period' => $period,
            'invoices' => $invoiceRows,
            'invoice_count' => $invoiceRows->count(),
            'ledger_item_count' => (int) $invoiceRows->sum('items_count'),
            'missing_rate_count' => (int) $invoiceRows->sum('missing_rate_count'),
            'invoice_commission_sum' => $invoiceCommission,
            'return_adjustments' => (int) $summary['return_reversal_amount'],
            'reassignment_adjustments' => (int) $summary['seller_correction_amount'],
            'manual_adjustments' => (int) $summary['manual_adjustment_amount'],
            'final_expected' => $invoiceCommission + (int) $summary['return_reversal_amount']
                + (int) $summary['seller_correction_amount'] + (int) $summary['manual_adjustment_amount'],
            'displayed_total' => (int) $summary['net_commission_amount'],
            'difference' => (int) $summary['net_commission_amount'] - ($invoiceCommission
                + (int) $summary['return_reversal_amount'] + (int) $summary['seller_correction_amount']
                + (int) $summary['manual_adjustment_amount']),
            'conflicting_invoice_ids' => $this->conflictingSellerInvoices($period, $seller->id)->keys()->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function sellerCorrections(CommissionPeriod $period, User $seller, string $type, int $perPage = 30): LengthAwarePaginator
    {
        return CommissionCorrectionEntry::query()->with(['invoice:id,uuid', 'sourcePeriod:id,label', 'salesReturn:id,document_number,applied_at'])
            ->where('commission_period_id', $period->id)->where('seller_id', $seller->id)
            ->when($type === 'returns', fn ($query) => $query->whereIn('event_type', ['return_reversal', 'return_reversal_cancelled']))
            ->when($type === 'reassignments', fn ($query) => $query->where('event_type', 'seller_reassignment_correction'))
            ->latest()->paginate($perPage, ['*'], $type.'_page');
    }

    private function active(CommissionPeriod $period)
    {
        return CommissionLedgerEntry::query()->where('commission_period_id', $period->id)->where('status', CommissionLedgerEntry::STATUS_ACTIVE);
    }
}
