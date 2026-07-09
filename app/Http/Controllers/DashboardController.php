<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AssetDocument;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SalesHavalehHistory;
use App\Models\StockCountDocument;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Morilog\Jalali\Jalalian;
use App\Support\Currency;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->startOfDay();
        $user = $request->user();
        $monthStart = now()->startOfMonth();
        $last30DaysStart = now()->subDays(29)->startOfDay();

        $lowStockThreshold = (int) config('inventory.low_stock_threshold', 5);


        $quickActions = collect([
            ['title' => 'ثبت پیش‌فاکتور', 'description' => 'ثبت سفارش جدید مشتری', 'route_name' => 'preinvoice.create', 'icon' => 'receipt-cutoff', 'roles' => null],
            ['title' => 'پیش‌فاکتورهای من', 'description' => 'پیگیری سفارش‌های ثبت‌شده', 'route_name' => 'preinvoice.my.index', 'icon' => 'person-check', 'roles' => null],
            ['title' => 'حواله‌های انبار', 'description' => 'جمع‌آوری و ارسال کالا', 'route_name' => 'vouchers.index', 'icon' => 'boxes', 'roles' => ['admin', 'Admin', 'warehouse', 'StorageManager', 'Manager']],
            ['title' => 'ثبت خرید کالا', 'description' => 'ورود موجودی جدید به انبار', 'route_name' => 'purchases.create', 'icon' => 'cart-plus', 'roles' => ['admin', 'Admin', 'warehouse', 'StorageManager', 'Manager']],
            ['title' => 'فاکتورها', 'description' => 'مشاهده و پیگیری فاکتورهای فروش', 'route_name' => 'invoices.index', 'icon' => 'file-earmark-text', 'roles' => null],
            ['title' => 'گردش حساب مشتری', 'description' => 'بررسی حساب و مانده مشتریان', 'route_name' => 'account-statements.index', 'icon' => 'cash-stack', 'roles' => ['admin', 'Admin', 'finance', 'Accountant']],
            ['title' => 'نقشه انبار', 'description' => 'جانمایی و پیدا کردن کالاها', 'route_name' => 'warehouse-map.index', 'icon' => 'map', 'roles' => null],
        ])->filter(fn (array $action) => Route::has($action['route_name']) && $this->userCanSeeDashboardLink($user, $action['roles']))
            ->map(fn (array $action) => $action + ['route' => route($action['route_name'])])
            ->values();

        $kpis = [
            'todayPreinvoices' => PreinvoiceOrder::query()->whereDate('created_at', $today)->count(),
            'todayInvoices' => Invoice::query()->whereDate('created_at', $today)->count(),
            'financeQueue' => PreinvoiceOrder::query()->where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE)->count(),
            'warehousePending' => Invoice::query()->where('status', Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL)->count(),
            'lowStock' => Product::query()->where('stock', '>', 0)->where('stock', '<=', $lowStockThreshold)->count(),
            'todayReceipts' => Currency::toRial((int) InvoicePayment::query()->whereDate('paid_at', $today)->sum('amount')),
        ];

        $outOfStockCount = $this->safeCount(fn () => Product::query()->where('stock', '<=', 0)->count());
        $unmappedVariantCount = $this->safeCount(fn () => ProductVariant::query()
            ->whereDoesntHave('locationStocks', fn ($query) => $query->where('quantity', '>', 0))
            ->count());
        $collectingVoucherCount = $this->safeCount(fn () => Invoice::query()->whereIn('status', [
            Invoice::STATUS_COLLECTING,
            Invoice::STATUS_CHECKING_DISCREPANCY,
            Invoice::STATUS_FINAL_CHECK,
            Invoice::STATUS_PACKING,
        ])->count());

        $actionItems = collect([
            ['title' => 'در انتظار تایید مالی', 'count' => $kpis['financeQueue'], 'route_name' => 'preinvoice.draft.index', 'roles' => ['admin', 'Admin', 'finance', 'Accountant', 'Manager']],
            ['title' => 'حواله‌های منتظر تایید انبار', 'count' => $kpis['warehousePending'], 'route_name' => 'vouchers.index', 'roles' => ['admin', 'Admin', 'warehouse', 'StorageManager', 'Manager']],
            ['title' => 'حواله‌های در حال جمع‌آوری', 'count' => $collectingVoucherCount, 'route_name' => 'vouchers.sale-delivery.index', 'roles' => ['admin', 'Admin', 'warehouse', 'StorageManager', 'Manager']],
            ['title' => 'چک‌های نزدیک سررسید', 'count' => $this->safeCount(fn () => Cheque::query()->where('status', 'pending')->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count()), 'route_name' => 'finance.cheques.registered', 'roles' => ['admin', 'Admin', 'finance', 'Accountant']],
            ['title' => 'کالاهای کم‌موجودی', 'count' => $kpis['lowStock'], 'route_name' => 'products.index', 'route_params' => ['stock_status' => 'low'], 'roles' => null],
            ['title' => 'کالاهای صفرموجودی', 'count' => $outOfStockCount, 'route_name' => 'products.index', 'route_params' => ['stock_status' => 'out'], 'roles' => null],
            ['title' => 'تنوع‌های بدون مکان در نقشه انبار', 'count' => $unmappedVariantCount, 'route_name' => 'warehouse-map.index', 'roles' => null],
        ])->filter(fn (array $item) => Route::has($item['route_name']) && $this->userCanSeeDashboardLink($user, $item['roles']))
            ->map(fn (array $item) => $item + ['route' => route($item['route_name'], $item['route_params'] ?? [])])
            ->sortByDesc('count')
            ->values()
            ->all();

        $salesSummary = [
            'preinvoicesThisMonth' => PreinvoiceOrder::query()->where('created_at', '>=', $monthStart)->count(),
            'invoicesThisMonth' => Invoice::query()->where('created_at', '>=', $monthStart)->count(),
            'salesAmountThisMonth' => (int) Invoice::query()->where('created_at', '>=', $monthStart)->sum('total'),
            'returnFromSaleCount' => StockMovement::query()
                ->where('type', 'in')
                ->where('reason', 'return_from_sale')
                ->where('created_at', '>=', $monthStart)
                ->count(),
            'latestPreinvoices' => PreinvoiceOrder::query()
                ->latest()
                ->take(5)
                ->get(['uuid', 'customer_name', 'total_price', 'created_at']),
        ];

        $warehouseSummary = [
            'todayHavalehCount' => Invoice::query()->whereDate('created_at', $today)->count(),
            'pendingWarehouse' => $kpis['warehousePending'],
            'lowStock' => $kpis['lowStock'],
            'outOfStock' => Product::query()->where('stock', '<=', 0)->count(),
            'latestStocktakes' => StockCountDocument::query()
                ->with('warehouse:id,name')
                ->latest()
                ->take(5)
                ->get(['id', 'warehouse_id', 'document_number', 'status', 'created_at']),
            'latestScrapVouchers' => StockMovement::query()
                ->where('reason', 'scrap')
                ->latest()
                ->take(5)
                ->get(['reference', 'quantity', 'created_at']),
        ];

        $financeSummary = [
            'financeQueue' => $kpis['financeQueue'],
            'todayReceipts' => $kpis['todayReceipts'],
            'todayCashPayments' => InvoicePayment::query()->whereDate('paid_at', $today)->where('method', 'cash')->count(),
            'todayChequePayments' => InvoicePayment::query()->whereDate('paid_at', $today)->where('method', 'cheque')->count(),
            'latestInvoices' => Invoice::query()
                ->latest()
                ->take(5)
                ->get(['uuid', 'customer_name', 'total', 'status', 'created_at']),
            'importantAccounts' => Customer::query()
                ->withBalance()
                ->get(['id', 'first_name', 'last_name', 'opening_balance'])
                ->sortByDesc(fn (Customer $customer) => abs((int) $customer->balance))
                ->take(5)
                ->values(),
        ];

        $statusHistory = SalesHavalehHistory::query()
            ->with(['invoice:id,uuid', 'actor:id,name'])
            ->where('field_name', 'status')
            ->latest('done_at')
            ->first();

        $recentActivity = [
            'latestPreinvoice' => PreinvoiceOrder::query()->latest()->first(['uuid', 'customer_name', 'created_at']),
            'latestHavaleh' => Invoice::query()->latest()->first(['uuid', 'customer_name', 'created_at']),
            'latestStatusChange' => $statusHistory,
            'latestAssetDocument' => AssetDocument::query()->latest()->first(['id', 'document_number', 'status', 'created_at']),
            'latestUserActivities' => ActivityLog::query()
                ->with('user:id,name')
                ->latest('occurred_at')
                ->take(6)
                ->get(['id', 'user_id', 'description', 'occurred_at']),
        ];

        $warnings = [
            [
                'title' => 'کالاهای کم‌موجود',
                'count' => $kpis['lowStock'],
                'description' => "موجودی کمتر یا مساوی {$lowStockThreshold}",
                'route' => route('products.index', ['stock_status' => 'low']),
                'variant' => 'warning',
            ],
            [
                'title' => 'کالاهای صفر موجودی',
                'count' => Product::query()->where('stock', '<=', 0)->count(),
                'description' => 'نیازمند تامین فوری',
                'route' => route('products.index', ['stock_status' => 'out']),
                'variant' => 'danger',
            ],
            [
                'title' => 'سفارش‌های معطل مالی',
                'count' => PreinvoiceOrder::query()
                    ->where('status', PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE)
                    ->where('created_at', '<=', now()->subDays(2))
                    ->count(),
                'description' => 'بیش از ۲ روز در صف مانده‌اند',
                'route' => route('preinvoice.draft.index'),
                'variant' => 'secondary',
            ],
            [
                'title' => 'چک‌های نزدیک سررسید',
                'count' => Cheque::query()
                    ->where('status', 'pending')
                    ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                    ->count(),
                'description' => 'تا ۷ روز آینده',
                'route' => route('invoices.index'),
                'variant' => 'info',
            ],
            [
                'title' => 'اسناد قدیمی نهایی‌نشده',
                'count' => StockCountDocument::query()
                    ->where('status', 'draft')
                    ->where('created_at', '<=', now()->subDays(7))
                    ->count()
                    + AssetDocument::query()
                        ->where('status', AssetDocument::STATUS_DRAFT)
                        ->where('created_at', '<=', now()->subDays(7))
                        ->count(),
                'description' => 'پیش‌نویس‌های قدیمی‌تر از ۷ روز',
                'route' => route('stocktake.index'),
                'variant' => 'dark',
            ],
        ];

        $jalaliNow = Jalalian::fromDateTime(now());
        $selectedYear = (int) $request->integer('report_year', $jalaliNow->getYear());
        $selectedMonth = (int) $request->integer('report_month', $jalaliNow->getMonth());

        $monthlyReport = $this->buildMonthlyReport($selectedYear, $selectedMonth);

        $rolling30Summary = [
            'sales' => (int) Invoice::query()->where('created_at', '>=', $last30DaysStart)->sum('total'),
            'invoices' => Invoice::query()->where('created_at', '>=', $last30DaysStart)->count(),
            'receipts' => (int) InvoicePayment::query()->where('paid_at', '>=', $last30DaysStart->toDateString())->sum('amount'),
        ];

        $moduleShortcuts = collect([
            ['title' => 'کالاها', 'description' => 'مدیریت کالا و موجودی', 'route_name' => 'products.index', 'icon' => 'box-seam', 'roles' => null],
            ['title' => 'انبارداری', 'description' => 'حواله‌ها و انبارگردانی', 'route_name' => 'vouchers.index', 'icon' => 'boxes', 'roles' => ['admin', 'Admin', 'warehouse', 'StorageManager', 'Manager']],
            ['title' => 'بازرگانی و فروش', 'description' => 'پیش‌فاکتور و مشتریان', 'route_name' => 'preinvoice.create', 'icon' => 'cart-check', 'roles' => null],
            ['title' => 'مالی', 'description' => 'صف مالی و فاکتورها', 'route_name' => 'preinvoice.draft.index', 'icon' => 'cash-coin', 'roles' => ['admin', 'Admin', 'finance', 'Accountant', 'Manager']],
            ['title' => 'پیکربندی', 'description' => 'تنظیمات پایه سیستم', 'route_name' => 'users.index', 'icon' => 'gear', 'roles' => ['admin', 'Admin']],
        ])->filter(fn (array $module) => Route::has($module['route_name']) && $this->userCanSeeDashboardLink($user, $module['roles']))
            ->map(fn (array $module) => $module + ['route' => route($module['route_name'])])
            ->values();

        $reportMonths = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
            7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        $reportYears = range($jalaliNow->getYear() - 3, $jalaliNow->getYear() + 1);

        $todaySummary = [
            ['title' => 'فروش امروز', 'value' => Currency::toRial((int) Invoice::query()->whereDate('created_at', $today)->sum('total')), 'suffix' => 'ریال'],
            ['title' => 'تعداد فاکتور امروز', 'value' => $kpis['todayInvoices'], 'suffix' => 'عدد'],
            ['title' => 'خرید امروز', 'value' => Currency::toRial((int) Purchase::query()->whereDate('purchased_at', $today)->sum('total_amount')), 'suffix' => 'ریال'],
            ['title' => 'دریافت امروز', 'value' => $kpis['todayReceipts'], 'suffix' => 'ریال'],
            ['title' => 'پیش‌فاکتورهای امروز', 'value' => $kpis['todayPreinvoices'], 'suffix' => 'عدد'],
        ];

        return view('dashboard.index', [
            'todayDateLabel' => Jalalian::fromDateTime(now())->format('%A %d %B %Y'),
            'todayDateTimeLabel' => Jalalian::fromDateTime(now())->format('Y/m/d H:i'),
            'quickActions' => $quickActions,
            'todaySummary' => $todaySummary,
            'userName' => auth()->user()?->name,
            'kpis' => $kpis,
            'actionItems' => $actionItems,
            'salesSummary' => $salesSummary,
            'warehouseSummary' => $warehouseSummary,
            'financeSummary' => $financeSummary,
            'warnings' => $warnings,
            'recentActivity' => $recentActivity,
            'moduleShortcuts' => $moduleShortcuts,
            'monthlyReport' => $monthlyReport,
            'reportMonths' => $reportMonths,
            'reportYears' => $reportYears,
            'selectedReportMonth' => $selectedMonth,
            'selectedReportYear' => $selectedYear,
            'rolling30Summary' => $rolling30Summary,
        ]);
    }

    public function globalSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $results = [
            'products' => collect(),
            'variants' => collect(),
            'invoices' => collect(),
            'preinvoices' => collect(),
            'customers' => collect(),
        ];

        if ($q !== '') {
            $results['products'] = Product::query()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhere('short_barcode', 'like', "%{$q}%"))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'name', 'sku', 'code', 'barcode', 'stock']);

            $results['variants'] = ProductVariant::query()
                ->with('product:id,name')
                ->where(fn ($query) => $query->where('variant_code', 'like', "%{$q}%")
                    ->orWhere('variety_code', 'like', "%{$q}%")
                    ->orWhere('variant_name', 'like', "%{$q}%"))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'product_id', 'variant_name', 'variant_code', 'stock']);

            $results['invoices'] = Invoice::query()
                ->where(fn ($query) => $query->where('uuid', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_mobile', 'like', "%{$q}%"))
                ->latest('id')
                ->limit(10)
                ->get(['uuid', 'customer_name', 'customer_mobile', 'total', 'created_at']);

            $results['preinvoices'] = PreinvoiceOrder::query()
                ->where(fn ($query) => $query->where('uuid', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_mobile', 'like', "%{$q}%"))
                ->latest('id')
                ->limit(10)
                ->get(['uuid', 'customer_name', 'customer_mobile', 'total_price', 'created_at']);

            $results['customers'] = Customer::query()
                ->where(fn ($query) => $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%"))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'first_name', 'last_name', 'mobile']);
        }

        return view('dashboard.search', compact('q', 'results'));
    }

    private function userCanSeeDashboardLink($user, ?array $roles): bool
    {
        if (! $roles) {
            return true;
        }

        if (! $user || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        return $user->hasAnyRole(array_unique(array_merge(['admin', 'Admin'], $roles)));
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function monthlyReport(Request $request): JsonResponse
    {
        $jalaliNow = Jalalian::fromDateTime(now());
        $year = (int) $request->integer('report_year', $jalaliNow->getYear());
        $month = (int) $request->integer('report_month', $jalaliNow->getMonth());

        return response()->json($this->buildMonthlyReport($year, $month));
    }

    private function buildMonthlyReport(int $jalaliYear, int $jalaliMonth): array
    {
        $jalaliMonth = max(1, min(12, $jalaliMonth));

        $startJalali = new Jalalian($jalaliYear, $jalaliMonth, 1);
        $nextMonthJalali = $jalaliMonth === 12
            ? new Jalalian($jalaliYear + 1, 1, 1)
            : new Jalalian($jalaliYear, $jalaliMonth + 1, 1);

        $start = $startJalali->toCarbon()->startOfDay();
        $end = $nextMonthJalali->toCarbon()->subSecond();

        $metrics = [
            ['key' => 'sales', 'label' => 'مبلغ فروش', 'unit' => 'ریال', 'value' => Currency::toRial((int) Invoice::query()->whereBetween('created_at', [$start, $end])->sum('total')), 'color' => 'primary'],
            ['key' => 'warehouse_vouchers', 'label' => 'حواله‌های انبار', 'unit' => 'عدد', 'value' => Invoice::query()->whereBetween('created_at', [$start, $end])->count(), 'color' => 'info'],
            ['key' => 'receipts', 'label' => 'دریافتی‌ها', 'unit' => 'ریال', 'value' => Currency::toRial((int) InvoicePayment::query()->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])->sum('amount')), 'color' => 'success'],
            ['key' => 'invoice_count', 'label' => 'تعداد فاکتورها', 'unit' => 'عدد', 'value' => Invoice::query()->whereBetween('created_at', [$start, $end])->count(), 'color' => 'secondary'],
            ['key' => 'pending_orders', 'label' => 'سفارش‌های در انتظار', 'unit' => 'عدد', 'value' => PreinvoiceOrder::query()->where('status', PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE)->whereBetween('created_at', [$start, $end])->count(), 'color' => 'warning'],
        ];

        $max = max(1, collect($metrics)->max('value'));
        $metrics = collect($metrics)->map(fn (array $metric) => $metric + [
            'percent' => (float) min(100, round(($metric['value'] / $max) * 100, 2)),
            'display_value' => number_format($metric['value']),
        ])->values()->all();

        $monthNames = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
            7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];

        return [
            'report_year' => $jalaliYear,
            'report_month' => $jalaliMonth,
            'range_label' => ($monthNames[$jalaliMonth] ?? 'ماه') . " {$jalaliYear}",
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'summary' => [
                'preinvoices' => PreinvoiceOrder::query()->whereBetween('created_at', [$start, $end])->count(),
                'invoices' => Invoice::query()->whereBetween('created_at', [$start, $end])->count(),
                'sales_amount' => Currency::toRial((int) Invoice::query()->whereBetween('created_at', [$start, $end])->sum('total')),
            ],
            'metrics' => $metrics,
        ];
    }
}
