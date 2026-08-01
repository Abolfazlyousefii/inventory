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
use App\Models\SalesHavalehHistory;
use App\Models\StockCountDocument;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Currency;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $now = now();
        $today = $now->copy()->startOfDay();

        $sellerDashboardEnabled = $this->userHasAnyPermission($user, [
            'preinvoices.create',
            'preinvoices.own.view',
        ]);
        $canViewOwnPreinvoices = $this->canUseRoute($user, 'preinvoice.my.index');
        $canViewManagementReports = $this->canViewManagementReports($user);
        $canViewFinanceReports = $canViewManagementReports || $this->userHasAnyPermission($user, [
            'finance.reports.view',
            'account_statements.view',
            'payments.view',
            'preinvoices.finance.view',
        ]);
        $canViewWarehouseReports = $canViewManagementReports || $this->userHasAnyPermission($user, [
            'inventory.view',
            'inventory.count.view',
            'warehouse.collection.queue.view',
            'warehouse.shipping.queue.view',
        ]);

        $sellerQuickActions = collect([
            [
                'key' => 'create',
                'title' => 'ثبت پیش‌فاکتور جدید',
                'description' => 'ثبت سریع سفارش مشتری و انتخاب کالاها',
                'route_name' => 'preinvoice.create',
                'emphasis' => true,
            ],
            [
                'key' => 'mine',
                'title' => 'پیش‌فاکتورهای من',
                'description' => 'ادامه پیش‌نویس‌ها و پیگیری سفارش‌های ثبت‌شده',
                'route_name' => 'preinvoice.my.index',
                'emphasis' => false,
            ],
            [
                'key' => 'customers',
                'title' => 'مشتریان',
                'description' => 'جست‌وجو، مشاهده حساب و ثبت مشتری',
                'route_name' => 'customers.index',
                'emphasis' => false,
            ],
            [
                'key' => 'invoices',
                'title' => 'فاکتورها',
                'description' => 'مشاهده سفارش‌های نهایی‌شده و وضعیت ارسال',
                'route_name' => 'invoices.index',
                'emphasis' => false,
            ],
        ])->filter(fn (array $action): bool => $this->canUseRoute($user, $action['route_name']))
            ->map(fn (array $action): array => $action + ['route' => route($action['route_name'])])
            ->values();

        $sellerStatusCounts = $this->emptySellerStatusCounts();
        $sellerTodaySummary = [
            'preinvoices' => 0,
            'amount' => 0,
            'converted' => 0,
            'returned' => 0,
            'pending_finance' => 0,
        ];
        $sellerConversionRate = 0.0;
        $sellerWorkItems = collect();
        $sellerRecentPreinvoices = collect();
        $sellerSupplementaryActions = collect();
        $sellerFollowUps = collect();

        // TODO: remove this deployment compatibility guard after seller ownership is migrated everywhere.
        $sellerOwnershipSchemaReady = Schema::hasColumn('preinvoice_orders', 'seller_id');
        if ($sellerDashboardEnabled && $canViewOwnPreinvoices && $sellerOwnershipSchemaReady) {
            $sellerBaseQuery = PreinvoiceOrder::query()
                ->createdBySeller((int) $user->id)
                ->withoutTemporaryAutosaves();

            $groupedCounts = (clone $sellerBaseQuery)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($count): int => (int) $count);

            $sellerStatusCounts = [
                'drafts' => $groupedCounts->get(PreinvoiceOrder::STATUS_DRAFT, 0),
                'pending_finance' => $groupedCounts->get(PreinvoiceOrder::STATUS_PENDING_FINANCE, 0),
                'finance_reviewing' => $groupedCounts->get(PreinvoiceOrder::STATUS_FINANCE_REVIEWING, 0),
                'returned_to_sales' => $groupedCounts->get(PreinvoiceOrder::STATUS_RETURNED_TO_SALES, 0),
                'converted_to_invoice' => $groupedCounts->get(PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE, 0),
                'cancelled_by_finance' => $groupedCounts->get(PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE, 0),
            ];

            $todayAggregate = (clone $sellerBaseQuery)
                ->whereDate('created_at', $today)
                ->selectRaw(
                    'COUNT(*) as total_count,
                    COALESCE(SUM(total_price), 0) as total_amount,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as converted_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as returned_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count',
                    [
                        PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
                        PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
                        PreinvoiceOrder::STATUS_PENDING_FINANCE,
                    ]
                )
                ->first();

            $sellerTodaySummary = [
                'preinvoices' => (int) ($todayAggregate?->total_count ?? 0),
                'amount' => (int) ($todayAggregate?->total_amount ?? 0),
                'converted' => (int) ($todayAggregate?->converted_count ?? 0),
                'returned' => $sellerStatusCounts['returned_to_sales'],
                'pending_finance' => $sellerStatusCounts['pending_finance'],
            ];
            $sellerConversionRate = $sellerTodaySummary['preinvoices'] > 0
                ? round(($sellerTodaySummary['converted'] / $sellerTodaySummary['preinvoices']) * 100, 1)
                : 0.0;

            $sellerWorkItems = $this->buildSellerWorkItems($sellerStatusCounts, $sellerTodaySummary);

            $sellerRecentPreinvoices = (clone $sellerBaseQuery)
                ->with(['invoice:id,uuid,preinvoice_order_id'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(5)
                ->get([
                    'id',
                    'uuid',
                    'customer_name',
                    'total_price',
                    'status',
                    'created_at',
                    'document_date',
                ])
                ->map(fn (PreinvoiceOrder $order): PreinvoiceOrder => $this->attachSellerAction($order, $user));

            $sellerFollowUps = $sellerWorkItems
                ->whereIn('key', ['returned', 'drafts', 'cancelled', 'pending'])
                ->values();
        }

        if ($sellerDashboardEnabled) {
            $sellerSupplementaryActions = collect([
                [
                    'title' => $this->canUseRoute($user, 'customers.store') ? 'ثبت یا مدیریت مشتریان' : 'مدیریت مشتریان',
                    'description' => 'اطلاعات مشتری و سوابق حساب',
                    'route_name' => 'customers.index',
                ],
                [
                    'title' => 'جست‌وجوی کالا',
                    'description' => 'یافتن کالا، کد یا بارکد',
                    'route_name' => 'global-search',
                ],
                [
                    'title' => 'گردش حساب مشتری',
                    'description' => 'مشاهده مانده و گردش حساب',
                    'route_name' => 'account-statements.index',
                ],
                [
                    'title' => 'فاکتورهای فروش',
                    'description' => 'مشاهده فاکتورها و وضعیت ارسال',
                    'route_name' => 'invoices.index',
                ],
            ])->filter(fn (array $action): bool => $this->canUseRoute($user, $action['route_name']))
                ->map(fn (array $action): array => $action + ['route' => route($action['route_name'])])
                ->values();
        }

        $management = $this->buildOperationalReports(
            $user,
            $request,
            $today,
            $canViewManagementReports,
            $canViewFinanceReports,
            $canViewWarehouseReports
        );

        return view('dashboard.index', array_merge($management, [
            'todayDateLabel' => Jalalian::fromDateTime($now)->format('%A %d %B %Y'),
            'todayDateTimeLabel' => Jalalian::fromDateTime($now)->format('Y/m/d H:i'),
            'userName' => $user->name,
            'userRoleLabel' => $this->userRoleLabel($user),
            'sellerDashboardEnabled' => $sellerDashboardEnabled,
            'sellerCanSearch' => $this->canUseRoute($user, 'global-search'),
            'sellerCanCreate' => $this->canUseRoute($user, 'preinvoice.create'),
            'sellerQuickActions' => $sellerQuickActions,
            'sellerStatusCounts' => $sellerStatusCounts,
            'sellerTodaySummary' => $sellerTodaySummary,
            'sellerConversionRate' => $sellerConversionRate,
            'sellerWorkItems' => $sellerWorkItems,
            'sellerRecentPreinvoices' => $sellerRecentPreinvoices,
            'sellerSupplementaryActions' => $sellerSupplementaryActions,
            'sellerFollowUps' => $sellerFollowUps,
            'preinvoiceStatusLabels' => PreinvoiceOrder::statusLabels(),
            'canViewManagementReports' => $canViewManagementReports,
            'canViewFinanceReports' => $canViewFinanceReports,
            'canViewWarehouseReports' => $canViewWarehouseReports,
        ]));
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

    public function monthlyReport(Request $request): JsonResponse
    {
        abort_unless($this->canViewManagementReports($request->user()), 403);

        $jalaliNow = Jalalian::fromDateTime(now());
        $year = (int) $request->integer('report_year', $jalaliNow->getYear());
        $month = (int) $request->integer('report_month', $jalaliNow->getMonth());

        return response()->json($this->buildMonthlyReport($year, $month));
    }

    private function buildSellerWorkItems(array $counts, array $todaySummary)
    {
        $items = [
            [
                'key' => 'returned',
                'title' => 'برگشتی از مالی برای اصلاح',
                'description' => 'سفارش‌هایی که باید اصلاح و دوباره ارسال شوند',
                'count' => $counts['returned_to_sales'],
                'variant' => 'danger',
                'icon' => 'warning',
                'route' => route('preinvoice.my.index', ['tab' => 'needs-correction']),
                'action_label' => 'اصلاح',
            ],
            [
                'key' => 'drafts',
                'title' => 'پیش‌فاکتورهای پیش‌نویس',
                'description' => 'سفارش‌های ناتمامی که می‌توانید ادامه دهید',
                'count' => $counts['drafts'],
                'variant' => 'warning',
                'icon' => 'document',
                'route' => route('preinvoice.my.index', ['tab' => 'drafts']),
                'action_label' => 'ادامه ثبت',
            ],
            [
                'key' => 'pending',
                'title' => 'در انتظار تأیید مالی',
                'description' => 'سفارش‌های ارسال‌شده به واحد مالی',
                'count' => $counts['pending_finance'],
                'variant' => 'info',
                'icon' => 'clock',
                'route' => route('preinvoice.my.index', ['status' => PreinvoiceOrder::STATUS_PENDING_FINANCE]),
                'action_label' => 'مشاهده',
            ],
            [
                'key' => 'reviewing',
                'title' => 'در حال بررسی مالی',
                'description' => 'سفارش‌هایی که مالی در حال بررسی آن‌هاست',
                'count' => $counts['finance_reviewing'],
                'variant' => 'info',
                'icon' => 'clock',
                'route' => route('preinvoice.my.index', ['status' => PreinvoiceOrder::STATUS_FINANCE_REVIEWING]),
                'action_label' => 'مشاهده',
            ],
            [
                'key' => 'converted',
                'title' => 'تأییدشده‌های امروز',
                'description' => 'سفارش‌های امروز که به فاکتور تبدیل شده‌اند',
                'count' => $todaySummary['converted'],
                'variant' => 'success',
                'icon' => 'check',
                'route' => route('preinvoice.my.index', ['status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE]),
                'action_label' => 'مشاهده',
            ],
            [
                'key' => 'cancelled',
                'title' => 'لغوشده توسط مالی',
                'description' => 'سفارش‌هایی که مالی لغو کرده است',
                'count' => $counts['cancelled_by_finance'],
                'variant' => 'danger',
                'icon' => 'warning',
                'route' => route('preinvoice.my.index', ['tab' => 'needs-correction', 'status' => PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE]),
                'action_label' => 'مشاهده جزئیات',
            ],
        ];

        return collect($items)->map(function (array $item): array {
            if ($item['count'] === 0) {
                $item['variant'] = 'muted';
            }

            return $item;
        });
    }

    private function attachSellerAction(PreinvoiceOrder $order, User $user): PreinvoiceOrder
    {
        $route = route('preinvoice.my.show', $order->uuid);
        $label = 'مشاهده جزئیات';

        if ($order->status === PreinvoiceOrder::STATUS_DRAFT && $this->canUseRoute($user, 'preinvoice.draft.edit')) {
            $route = route('preinvoice.draft.edit', $order->uuid);
            $label = 'ادامه ثبت';
        } elseif ($order->status === PreinvoiceOrder::STATUS_RETURNED_TO_SALES && $this->canUseRoute($user, 'preinvoice.draft.edit')) {
            $route = route('preinvoice.draft.edit', $order->uuid);
            $label = 'اصلاح سفارش';
        } elseif ($order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE
            && $order->invoice
            && $this->canUseRoute($user, 'invoices.show')) {
            $route = route('invoices.show', $order->invoice->uuid);
            $label = 'مشاهده فاکتور';
        } elseif (in_array($order->status, [
            PreinvoiceOrder::STATUS_PENDING_FINANCE,
            PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
        ], true)) {
            $label = 'مشاهده';
        }

        $order->setAttribute('dashboard_action_route', $route);
        $order->setAttribute('dashboard_action_label', $label);

        return $order;
    }

    private function buildOperationalReports(
        User $user,
        Request $request,
        Carbon $today,
        bool $canViewManagement,
        bool $canViewFinance,
        bool $canViewWarehouse
    ): array {
        $salesSummary = null;
        $warehouseSummary = null;
        $financeSummary = null;
        $warnings = collect();
        $recentActivity = null;
        $moduleShortcuts = collect();
        $monthlyReport = null;
        $reportMonths = [];
        $reportYears = [];
        $selectedMonth = null;
        $selectedYear = null;

        if ($canViewManagement) {
            $monthStart = now()->startOfMonth();
            $salesSummary = [
                'preinvoicesThisMonth' => PreinvoiceOrder::query()->where('created_at', '>=', $monthStart)->count(),
                'invoicesThisMonth' => Invoice::query()->where('created_at', '>=', $monthStart)->count(),
                'salesAmountThisMonth' => (int) Invoice::query()->where('created_at', '>=', $monthStart)->sum('total'),
                'returnFromSaleCount' => StockMovement::query()
                    ->where('type', 'in')
                    ->where('reason', 'return_from_sale')
                    ->where('created_at', '>=', $monthStart)
                    ->count(),
            ];

            $statusHistory = SalesHavalehHistory::query()
                ->with(['invoice:id,uuid', 'actor:id,name'])
                ->where('field_name', 'status')
                ->latest('done_at')
                ->first();
            $recentActivity = [
                'latestPreinvoice' => PreinvoiceOrder::query()->latest('id')->first(['uuid', 'customer_name', 'created_at']),
                'latestHavaleh' => Invoice::query()->latest('id')->first(['uuid', 'customer_name', 'created_at']),
                'latestStatusChange' => $statusHistory,
                'latestAssetDocument' => AssetDocument::query()->latest('id')->first(['id', 'document_number', 'status', 'created_at']),
                'latestUserActivities' => ActivityLog::query()
                    ->with('user:id,name')
                    ->latest('occurred_at')
                    ->limit(6)
                    ->get(['id', 'user_id', 'description', 'occurred_at']),
            ];

            $jalaliNow = Jalalian::fromDateTime(now());
            $selectedYear = (int) $request->integer('report_year', $jalaliNow->getYear());
            $selectedMonth = (int) $request->integer('report_month', $jalaliNow->getMonth());
            $monthlyReport = $this->buildMonthlyReport($selectedYear, $selectedMonth);
            $reportMonths = [
                1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
                7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
            ];
            $reportYears = range($jalaliNow->getYear() - 3, $jalaliNow->getYear() + 1);
        }

        if ($canViewWarehouse) {
            $lowStockThreshold = (int) config('inventory.low_stock_threshold', 5);
            $lowStock = Product::query()
                ->where('stock', '>', 0)
                ->where('stock', '<=', $lowStockThreshold)
                ->count();
            $outOfStock = Product::query()->where('stock', '<=', 0)->count();
            $warehouseSummary = [
                'todayHavalehCount' => Invoice::query()->whereDate('created_at', $today)->count(),
                'pendingWarehouse' => Invoice::query()->where('status', Invoice::STATUS_PENDING_WAREHOUSE_APPROVAL)->count(),
                'lowStock' => $lowStock,
                'outOfStock' => $outOfStock,
                'latestStocktakes' => StockCountDocument::query()
                    ->with('warehouse:id,name')
                    ->latest('id')
                    ->limit(5)
                    ->get(['id', 'warehouse_id', 'document_number', 'status', 'created_at']),
            ];

            if ($canViewManagement) {
                $warnings->push([
                    'title' => 'کالاهای کم‌موجود',
                    'count' => $lowStock,
                    'description' => "موجودی کمتر یا مساوی {$lowStockThreshold}",
                    'route' => $this->canUseRoute($user, 'products.index') ? route('products.index', ['stock_status' => 'low']) : null,
                    'variant' => 'warning',
                ], [
                    'title' => 'کالاهای صفر موجودی',
                    'count' => $outOfStock,
                    'description' => 'نیازمند تأمین فوری',
                    'route' => $this->canUseRoute($user, 'products.index') ? route('products.index', ['stock_status' => 'out']) : null,
                    'variant' => 'danger',
                ]);
            }
        }

        if ($canViewFinance) {
            $financeQueue = PreinvoiceOrder::query()
                ->where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE)
                ->count();
            $financeSummary = [
                'financeQueue' => $financeQueue,
                'todayReceipts' => Currency::toRial((int) InvoicePayment::query()->whereDate('paid_at', $today)->sum('amount')),
                'todayCashPayments' => InvoicePayment::query()->whereDate('paid_at', $today)->where('method', 'cash')->count(),
                'todayChequePayments' => InvoicePayment::query()->whereDate('paid_at', $today)->where('method', 'cheque')->count(),
                'latestInvoices' => Invoice::query()
                    ->latest('id')
                    ->limit(5)
                    ->get(['uuid', 'customer_name', 'total', 'status', 'created_at']),
                'importantAccounts' => Customer::query()
                    ->withBalance()
                    ->get(['id', 'first_name', 'last_name', 'opening_balance'])
                    ->sortByDesc(fn (Customer $customer): int => abs((int) $customer->balance))
                    ->take(5)
                    ->values(),
            ];

            if ($canViewManagement) {
                $warnings->push([
                    'title' => 'چک‌های نزدیک سررسید',
                    'count' => Cheque::query()
                        ->where('status', 'pending')
                        ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                        ->count(),
                    'description' => 'تا ۷ روز آینده',
                    'route' => $this->canUseRoute($user, 'finance.cheques.registered') ? route('finance.cheques.registered') : null,
                    'variant' => 'info',
                ], [
                    'title' => 'سفارش‌های معطل مالی',
                    'count' => PreinvoiceOrder::query()
                        ->where('status', PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE)
                        ->where('created_at', '<=', now()->subDays(2))
                        ->count(),
                    'description' => 'بیش از ۲ روز در صف مانده‌اند',
                    'route' => $this->canUseRoute($user, 'preinvoice.draft.index') ? route('preinvoice.draft.index') : null,
                    'variant' => 'warning',
                ]);
            }
        }

        if ($canViewManagement || $canViewFinance || $canViewWarehouse) {
            $moduleShortcuts = collect([
                ['title' => 'کالاها', 'description' => 'مدیریت کالا و موجودی', 'route_name' => 'products.index'],
                ['title' => 'انبارداری', 'description' => 'حواله‌ها و انبارگردانی', 'route_name' => 'vouchers.index'],
                ['title' => 'بازرگانی و فروش', 'description' => 'پیش‌فاکتور و مشتریان', 'route_name' => 'preinvoice.create'],
                ['title' => 'مالی', 'description' => 'صف مالی و فاکتورها', 'route_name' => 'preinvoice.draft.index'],
                ['title' => 'پیکربندی', 'description' => 'تنظیمات پایه سیستم', 'route_name' => 'users.index'],
            ])->filter(fn (array $module): bool => $this->canUseRoute($user, $module['route_name']))
                ->map(fn (array $module): array => $module + ['route' => route($module['route_name'])])
                ->values();
        }

        return [
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
        ];
    }

    private function canUseRoute(User $user, string $routeName): bool
    {
        if (! Route::has($routeName)) {
            return false;
        }

        $permission = PermissionCatalog::routePermissions()[$routeName] ?? null;

        return $permission !== null && PermissionCatalog::userHasPermission($user, $permission);
    }

    private function userHasAnyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (PermissionCatalog::userHasPermission($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function canViewManagementReports(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $managerRoles = array_merge(
            PermissionCatalog::administratorRoles(),
            ['sales_manager'],
            PermissionCatalog::roleAliases()['sales_manager'] ?? []
        );

        return $user->hasAnyRole(array_unique($managerRoles));
    }

    private function userRoleLabel(User $user): string
    {
        $labels = PermissionCatalog::roleLabels();
        $aliases = PermissionCatalog::roleAliases();

        foreach ($user->getRoleNames() as $roleName) {
            if (isset($labels[$roleName])) {
                return $labels[$roleName];
            }

            foreach ($aliases as $standardRole => $roleAliases) {
                if (in_array($roleName, $roleAliases, true)) {
                    return $labels[$standardRole] ?? $roleName;
                }
            }
        }

        return $user->position ?: 'کاربر سامانه';
    }

    private function emptySellerStatusCounts(): array
    {
        return [
            'drafts' => 0,
            'pending_finance' => 0,
            'finance_reviewing' => 0,
            'returned_to_sales' => 0,
            'converted_to_invoice' => 0,
            'cancelled_by_finance' => 0,
        ];
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
        $metrics = collect($metrics)->map(fn (array $metric): array => $metric + [
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
            'range_label' => ($monthNames[$jalaliMonth] ?? 'ماه')." {$jalaliYear}",
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
