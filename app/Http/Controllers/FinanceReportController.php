<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceReportController extends Controller
{
    public function index(): View
    {
        return view('finance.reports.index', [
            'reports' => [[
                'title' => 'اسناد فروش و پورسانت فروشندگان',
                'description' => 'ثبت و مدیریت فاکتورهای منتخب هر فروشنده برای محاسبات واحد مالی',
                'url' => route('finance.seller-sales.index'),
            ]],
        ]);
    }

    public function salesVisitors(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->invoiceReportQuery($filters);
        $rows = $this->aggregateQuery($query)
            ->leftJoinSub($this->paymentTotalsQuery(), 'invoice_payment_totals', function ($join): void {
                $join->on('invoice_payment_totals.invoice_id', '=', 'invoices.id');
            })
            ->selectRaw('report_preinvoices.created_by as registered_by_user_id')
            ->selectRaw('count(*) as invoice_count')
            ->selectRaw('count(distinct invoices.customer_id) as customers_count')
            ->selectRaw('coalesce(sum(invoices.total), 0) as total_sales')
            ->selectRaw('coalesce(sum(coalesce(invoice_payment_totals.paid_amount, 0)), 0) as aggregated_paid_amount')
            ->groupBy('report_preinvoices.created_by')
            ->orderByDesc('total_sales')
            ->get();

        $users = $this->usersForFilter();
        $userNames = User::query()->whereIn('id', $rows->pluck('registered_by_user_id')->filter()->unique())->pluck('name', 'id');

        $rows = $rows->map(function ($row) use ($userNames) {
            $totalSales = (int) $row->total_sales;
            $paidAmount = (int) $row->aggregated_paid_amount;
            $invoiceCount = (int) $row->invoice_count;

            $registeredByUserId = (int) $row->registered_by_user_id;

            return [
                'user_id' => $registeredByUserId,
                'registered_by_user_id' => $registeredByUserId,
                'user_name' => $userNames[$registeredByUserId] ?? ('کاربر #' . $registeredByUserId),
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
        ]);
    }

    private function invoiceReportQuery(array $filters): Builder
    {
        $query = Invoice::query()
            ->select('invoices.*')
            ->leftJoin('preinvoice_orders as report_preinvoices', 'report_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->whereNotNull('report_preinvoices.created_by')
            ->whereExists(function ($userQuery): void {
                $userQuery->selectRaw('1')
                    ->from('users as registrar_users')
                    ->whereColumn('registrar_users.id', 'report_preinvoices.created_by');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('invoices.status')
                    ->orWhereNotIn('invoices.status', Invoice::cancelledStatuses());
            });

        if ($filters['date_from_boundary']) {
            $query->whereRaw('coalesce(invoices.document_date, invoices.created_at) >= ?', [$filters['date_from_boundary']]);
        }

        if ($filters['date_to_boundary']) {
            $query->whereRaw('coalesce(invoices.document_date, invoices.created_at) <= ?', [$filters['date_to_boundary']]);
        }

        if ($filters['customer_id']) {
            $query->where('invoices.customer_id', $filters['customer_id']);
        }

        if ($filters['user_id']) {
            $registeredByUserId = $filters['user_id'];
            $query->where('report_preinvoices.created_by', $registeredByUserId);
        }

        return $query;
    }

    private function aggregateQuery(Builder $query): Builder
    {
        $aggregateQuery = clone $query;
        $aggregateQuery->getQuery()->columns = null;

        return $aggregateQuery;
    }

    private function paymentTotalsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('invoice_payments')
            ->select('invoice_id')
            ->selectRaw('coalesce(sum(amount), 0) as paid_amount')
            ->groupBy('invoice_id');
    }

    private function filters(Request $request): array
    {
        $dateFrom = $this->dateInput($request->query('date_from'), 'date_from');
        $dateTo = $this->dateInput($request->query('date_to'), 'date_to');

        if ($dateFrom && $dateTo && $dateFrom->isAfter($dateTo)) {
            throw ValidationException::withMessages([
                'date_from' => 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.',
            ]);
        }

        return [
            'date_from' => $dateFrom ? JalaliDate::date($dateFrom, '') : '',
            'date_to' => $dateTo ? JalaliDate::date($dateTo, '') : '',
            'date_from_boundary' => $dateFrom?->startOfDay(),
            'date_to_boundary' => $dateTo?->endOfDay(),
            'user_id' => $request->integer('user_id') ?: null,
            'customer_id' => $request->integer('customer_id') ?: null,
        ];
    }

    private function dateInput(mixed $value, string $field): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        $value = strtr(trim((string) $value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        try {
            if (preg_match('/^(19|20)\d{2}-\d{2}-\d{2}$/', $value)) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
            }

            $gregorian = JalaliDate::toGregorianDate($value);
            if ($gregorian) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $gregorian, config('app.timezone'));
            }
        } catch (\Throwable) {
            // The validation error below is the stable public contract.
        }

        throw ValidationException::withMessages([$field => 'تاریخ واردشده معتبر نیست.']);
    }

    private function usersForFilter()
    {
        return User::query()->activeErpUsers()->select('id', 'name')->orderBy('name')->get();
    }

    private function customersForFilter()
    {
        return Customer::query()->select('id', 'first_name', 'last_name')->orderBy('first_name')->orderBy('last_name')->limit(200)->get();
    }
}
