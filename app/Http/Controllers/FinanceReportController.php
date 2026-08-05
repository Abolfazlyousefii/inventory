<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinanceCommissionBatch;
use App\Models\Invoice;
use App\Models\User;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'report_preinvoices');
        $query = $this->invoiceReportQuery($filters);
        $rows = $this->aggregateQuery($query)
            ->leftJoinSub($this->paymentTotalsQuery(), 'invoice_payment_totals', function ($join): void {
                $join->on('invoice_payment_totals.invoice_id', '=', 'invoices.id');
            })
            ->selectRaw("{$effectiveSeller} as effective_seller_id")
            ->selectRaw('count(*) as invoice_count')
            ->selectRaw('count(distinct invoices.customer_id) as customers_count')
            ->selectRaw('coalesce(sum(invoices.total), 0) as total_sales')
            ->selectRaw('coalesce(sum(coalesce(invoice_payment_totals.paid_amount, 0)), 0) as aggregated_paid_amount')
            ->groupByRaw($effectiveSeller)
            ->orderByDesc('total_sales')
            ->get();

        $users = $this->usersForFilter();
        $userNames = User::query()->whereIn('id', $rows->pluck('effective_seller_id')->filter()->unique())->pluck('name', 'id');

        $rows = $rows->map(function ($row) use ($userNames) {
            $totalSales = (int) $row->total_sales;
            $paidAmount = (int) $row->aggregated_paid_amount;
            $invoiceCount = (int) $row->invoice_count;

            $registeredByUserId = (int) $row->effective_seller_id;

            return [
                'user_id' => $registeredByUserId,
                'effective_seller_id' => $registeredByUserId,
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

        $detailRows = collect();
        $batchedInvoiceIds = collect();
        if ($filters['user_id']) {
            $detailRows = $this->invoiceReportQuery($filters)
                ->with(['seller:id,name', 'preinvoiceOrder:id,seller_id,created_by', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name'])
                ->orderByDesc(DB::raw('COALESCE(invoices.document_date, invoices.created_at)'))
                ->get();
            $batchedInvoiceIds = DB::table('finance_commission_batch_items as items')
                ->join('finance_commission_batches as batches', 'batches.id', '=', 'items.batch_id')
                ->where('batches.status', FinanceCommissionBatch::STATUS_ACTIVE)
                ->whereIn('items.invoice_id', $detailRows->pluck('id'))
                ->pluck('items.invoice_id');
        }

        return view('finance.reports.sales-visitors', [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $totals,
            'users' => $users,
            'customers' => $this->customersForFilter(),
            'detailRows' => $detailRows,
            'batchedInvoiceIds' => $batchedInvoiceIds,
        ]);
    }

    public function storeCommissionBatch(Request $request)
    {
        $data = $request->validate([
            'visitor_id' => ['required', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'string'],
            'date_to' => ['nullable', 'string'],
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $from = $this->dateInput($data['date_from'] ?? null, 'date_from');
        $to = $this->dateInput($data['date_to'] ?? null, 'date_to');
        if ($from && $to && $from->isAfter($to)) {
            throw ValidationException::withMessages(['date_from' => 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.']);
        }

        $batch = DB::transaction(function () use ($data, $from, $to, $request): FinanceCommissionBatch {
            $filters = [
                'date_from_boundary' => $from?->startOfDay(),
                'date_to_boundary' => $to?->endOfDay(),
                'user_id' => (int) $data['visitor_id'],
                'customer_id' => null,
            ];
            $ids = array_map('intval', $data['invoice_ids']);
            $invoices = $this->invoiceReportQuery($filters)
                ->whereIn('invoices.id', $ids)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('finance_commission_batch_items as existing_items')
                        ->join('finance_commission_batches as existing_batches', 'existing_batches.id', '=', 'existing_items.batch_id')
                        ->whereColumn('existing_items.invoice_id', 'invoices.id')
                        ->where('existing_batches.status', FinanceCommissionBatch::STATUS_ACTIVE);
                })
                ->lockForUpdate()
                ->get();

            if ($invoices->count() !== count($ids)) {
                throw ValidationException::withMessages(['invoice_ids' => 'یک یا چند فاکتور با فروشنده، بازه یا وضعیت انتخاب‌شده مطابقت ندارند.']);
            }

            $batch = FinanceCommissionBatch::query()->create([
                'visitor_id' => (int) $data['visitor_id'],
                'from_date' => $from?->toDateString(),
                'to_date' => $to?->toDateString(),
                'invoice_count' => $invoices->count(),
                'total_amount' => (int) $invoices->sum('total'),
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'status' => FinanceCommissionBatch::STATUS_ACTIVE,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($invoices as $invoice) {
                $batch->items()->create([
                    'invoice_id' => $invoice->id,
                    'invoice_uuid' => (string) $invoice->uuid,
                    'invoice_date' => $invoice->display_document_date,
                    'customer_name' => $invoice->customer_name,
                    'customer_mobile' => $invoice->customer_mobile,
                    'invoice_total' => (int) $invoice->total,
                    'invoice_status' => $invoice->status,
                ]);
            }

            return $batch;
        });

        return redirect()->route('finance.reports.sales-visitors.commission-batches.show', $batch);
    }

    public function showCommissionBatch(FinanceCommissionBatch $batch): View
    {
        $batch->load(['visitor:id,name', 'approver:id,name', 'items']);

        return view('finance.reports.commission-batch-show', compact('batch'));
    }

    public function printCommissionBatch(FinanceCommissionBatch $batch): View
    {
        $batch->load(['visitor:id,name', 'approver:id,name', 'items']);

        return view('finance.reports.commission-batch-show', ['batch' => $batch, 'printMode' => true]);
    }

    public function exportCommissionBatch(Request $request, FinanceCommissionBatch $batch): StreamedResponse
    {
        $batch->load(['visitor:id,name', 'items']);
        $excel = $request->query('format') === 'excel';
        $filename = 'commission-batch-'.$batch->id.($excel ? '.xls' : '.csv');

        return response()->streamDownload(function () use ($batch): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['seller_id', 'seller_name', 'invoice_number', 'invoice_date', 'customer_name', 'customer_mobile', 'invoice_total', 'invoice_status']);
            foreach ($batch->items as $item) {
                fputcsv($handle, [
                    $batch->visitor_id,
                    $batch->visitor?->name,
                    $item->invoice_uuid,
                    optional($item->invoice_date)->format('Y-m-d H:i:s'),
                    $item->customer_name,
                    $item->customer_mobile,
                    $item->invoice_total,
                    $item->invoice_status,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function invoiceReportQuery(array $filters): Builder
    {
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'report_preinvoices');
        $query = Invoice::query()
            ->select('invoices.*')
            ->selectRaw("{$effectiveSeller} as effective_seller_id")
            ->leftJoin('preinvoice_orders as report_preinvoices', 'report_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->whereNotNull(DB::raw($effectiveSeller))
            ->whereExists(function ($userQuery): void {
                $userQuery->selectRaw('1')
                    ->from('users as seller_users')
                    ->whereColumn('seller_users.id', DB::raw(Invoice::effectiveSellerSql('invoices', 'report_preinvoices')));
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
            $query->whereRaw("{$effectiveSeller} = ?", [$filters['user_id']]);
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
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'filter_preinvoices');
        $historicalIds = Invoice::query()
            ->leftJoin('preinvoice_orders as filter_preinvoices', 'filter_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->selectRaw("{$effectiveSeller} as effective_seller_id")
            ->whereNotNull(DB::raw($effectiveSeller))
            ->pluck('effective_seller_id');

        return User::query()
            ->where(function (Builder $query) use ($historicalIds): void {
                $query->activeSellers()->orWhereIn('id', $historicalIds);
            })
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    private function customersForFilter()
    {
        return Customer::query()->select('id', 'first_name', 'last_name')->orderBy('first_name')->orderBy('last_name')->limit(200)->get();
    }
}
