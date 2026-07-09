<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceReportController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->invoiceReportQuery($filters);
        $summary = $this->summaryFromQuery($query);

        $chequeSummary = class_exists(Cheque::class)
            ? [
                'count' => Cheque::query()->whereIn('status', ['pending', 'registered', 'in_progress'])->count(),
                'amount' => (int) Cheque::query()->whereIn('status', ['pending', 'registered', 'in_progress'])->sum('amount'),
            ]
            : null;

        return view('finance.reports.index', [
            'filters' => $filters,
            'summary' => $summary,
            'chequeSummary' => $chequeSummary,
            'users' => $this->usersForFilter(),
            'customers' => $this->customersForFilter(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function salesVisitors(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->invoiceReportQuery($filters);
        $creatorColumn = $this->creatorColumn();

        if ($creatorColumn) {
            $rows = $this->aggregateQuery($query)
                ->selectRaw("invoices.{$creatorColumn} as user_id")
                ->selectRaw('count(*) as invoice_count')
                ->selectRaw('count(distinct invoices.customer_id) as customers_count')
                ->selectRaw('coalesce(sum(invoices.total), 0) as total_sales')
                ->selectRaw('coalesce(sum((' . $this->paidSubquerySql() . ')), 0) as paid_amount')
                ->groupBy("invoices.{$creatorColumn}")
                ->orderByDesc('total_sales')
                ->get();
        } else {
            $summary = $this->summaryFromQuery($query);
            $rows = collect([(object) [
                'user_id' => null,
                'invoice_count' => $summary['invoice_count'],
                'customers_count' => $summary['customers_count'],
                'total_sales' => $summary['total_sales'],
                'paid_amount' => $summary['paid_amount'],
            ]]);
        }

        $users = $this->usersForFilter();
        $userNames = $users->pluck('name', 'id');

        $rows = $rows->map(function ($row) use ($userNames) {
            $totalSales = (int) $row->total_sales;
            $paidAmount = (int) $row->paid_amount;
            $invoiceCount = (int) $row->invoice_count;

            return [
                'user_id' => $row->user_id,
                'user_name' => $row->user_id ? ($userNames[$row->user_id] ?? ('کاربر #' . $row->user_id)) : 'نامشخص',
                'invoice_count' => $invoiceCount,
                'customers_count' => (int) $row->customers_count,
                'total_sales' => $totalSales,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $totalSales - $paidAmount,
                'average_sale' => $invoiceCount > 0 ? (int) round($totalSales / $invoiceCount) : 0,
            ];
        });

        $totals = [
            'invoice_count' => $rows->sum('invoice_count'),
            'customers_count' => $rows->sum('customers_count'),
            'total_sales' => $rows->sum('total_sales'),
            'paid_amount' => $rows->sum('paid_amount'),
            'remaining_amount' => $rows->sum('remaining_amount'),
            'average_sale' => $rows->sum('invoice_count') > 0 ? (int) round($rows->sum('total_sales') / $rows->sum('invoice_count')) : 0,
        ];

        return view('finance.reports.sales-visitors', [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'users' => $users,
            'customers' => $this->customersForFilter(),
            'statuses' => $this->statusOptions(),
            'creatorColumn' => $creatorColumn,
        ]);
    }

    private function invoiceReportQuery(array $filters): Builder
    {
        $query = Invoice::query()
            ->select('invoices.*')
            ->selectSub($this->paidSubquery(), 'paid_total')
            ->whereIn('invoices.status', $this->validInvoiceStatuses());

        if ($filters['date_from']) {
            $query->whereDate('invoices.created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('invoices.created_at', '<=', $filters['date_to']);
        }

        if ($filters['status']) {
            $query->where('invoices.status', $filters['status']);
        }

        if ($filters['customer_id']) {
            $query->where('invoices.customer_id', $filters['customer_id']);
        }

        $creatorColumn = $this->creatorColumn();
        if ($creatorColumn && $filters['user_id']) {
            $query->where("invoices.{$creatorColumn}", $filters['user_id']);
        }

        return $query;
    }

    private function summaryFromQuery(Builder $query): array
    {
        $row = $this->aggregateQuery($query)
            ->selectRaw('count(*) as invoice_count')
            ->selectRaw('count(distinct invoices.customer_id) as customers_count')
            ->selectRaw('coalesce(sum(invoices.total), 0) as total_sales')
            ->selectRaw('coalesce(sum((' . $this->paidSubquerySql() . ')), 0) as paid_amount')
            ->first();

        $totalSales = (int) ($row->total_sales ?? 0);
        $paidAmount = (int) ($row->paid_amount ?? 0);

        return [
            'invoice_count' => (int) ($row->invoice_count ?? 0),
            'customers_count' => (int) ($row->customers_count ?? 0),
            'total_sales' => $totalSales,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $totalSales - $paidAmount,
        ];
    }


    private function aggregateQuery(Builder $query): Builder
    {
        $aggregateQuery = clone $query;
        $aggregateQuery->getQuery()->columns = null;

        return $aggregateQuery;
    }

    private function paidSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('invoice_payments')
            ->selectRaw('coalesce(sum(amount), 0)')
            ->whereColumn('invoice_payments.invoice_id', 'invoices.id');
    }

    private function paidSubquerySql(): string
    {
        return 'select coalesce(sum(amount), 0) from invoice_payments where invoice_payments.invoice_id = invoices.id';
    }

    private function filters(Request $request): array
    {
        return [
            'date_from' => $request->date('date_from')?->toDateString(),
            'date_to' => $request->date('date_to')?->toDateString(),
            'user_id' => $request->integer('user_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
            'status' => $request->filled('status') ? (string) $request->input('status') : null,
        ];
    }

    private function validInvoiceStatuses(): array
    {
        $invalid = ['cancelled', 'canceled', 'draft', 'returned_to_sales_after_collection', 'cancelled_by_finance', 'reservation_expired', 'not_shipped'];
        $defined = array_keys(Invoice::statusLabels());

        if ($defined === []) {
            return ['processing', 'pending_collection', 'warehouse_received', 'collecting', 'pending_finance_reapproval', 'ready_to_ship', 'shipped', 'delivered'];
        }

        return array_values(array_diff($defined, $invalid));
    }

    private function statusOptions(): array
    {
        return collect(Invoice::statusLabels())
            ->only($this->validInvoiceStatuses())
            ->all();
    }

    private function creatorColumn(): ?string
    {
        if (Schema::hasColumn('invoices', 'created_by')) {
            return 'created_by';
        }

        return null;
    }

    private function usersForFilter()
    {
        return User::query()->select('id', 'name')->orderBy('name')->get();
    }

    private function customersForFilter()
    {
        return Customer::query()->select('id', 'first_name', 'last_name')->orderBy('first_name')->orderBy('last_name')->limit(200)->get();
    }
}
