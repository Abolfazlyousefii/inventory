<?php

namespace App\Providers;

use App\Contracts\OtpSender;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\Category;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceNote;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use App\Observers\ActivityObserver;
use App\Observers\CommissionSourceObserver;
use App\Observers\CustomerLedgerChangeAuditObserver;
use App\Observers\InvoiceFinancialChangeAuditObserver;
use App\Observers\InvoiceItemChangeAuditObserver;
use App\Observers\OrderStatusSmsObserver;
use App\Observers\ProductInventoryObserver;
use App\Observers\ProductVariantSyncObserver;
use App\Observers\StockMovementObserver;
use App\Observers\WarehouseStockObserver;
use App\Services\Sms\OtpSmsSender;
use App\Support\PermissionCatalog;
use App\Support\SlowRequestDiagnostics;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Services\InventoryCountFreezeService::class);
        $this->app->bind(OtpSender::class, OtpSmsSender::class);
        $this->app->scoped(SlowRequestDiagnostics::class, fn () => new SlowRequestDiagnostics());
    }

    public function boot(Router $router): void
    {
        $this->loadRoutesFrom(base_path('routes/inventory-count-freeze.php'));
        $router->aliasMiddleware('route.permission', RoutePermissionMiddleware::class);

        if (! $this->app->runningInConsole() && (bool) config('slowdiag.enabled')) {
            DB::listen(function (QueryExecuted $query): void {
                app(SlowRequestDiagnostics::class)->recordQuery($query);
            });
        }

        Relation::morphMap([
            'site_user' => \App\Models\Site\User::class,
        ]);

        Gate::before(function ($user, $ability) {
            return PermissionCatalog::userHasPermission($user, $ability) ? true : null;
        });

        Product::observe(ActivityObserver::class);
        ProductVariant::observe(ActivityObserver::class);
        Category::observe(ActivityObserver::class);
        Customer::observe(ActivityObserver::class);
        PreinvoiceOrder::observe(ActivityObserver::class);
        PreinvoiceOrderItem::observe(ActivityObserver::class);
        Invoice::observe(ActivityObserver::class);
        InvoiceItem::observe(ActivityObserver::class);


        Invoice::observe(OrderStatusSmsObserver::class);
        PreinvoiceOrder::observe(OrderStatusSmsObserver::class);
        Invoice::observe(InvoiceFinancialChangeAuditObserver::class);
        InvoiceItem::observe(InvoiceItemChangeAuditObserver::class);
        CustomerLedger::observe(CustomerLedgerChangeAuditObserver::class);


        Invoice::observe(CommissionSourceObserver::class);
        InvoiceItem::observe(CommissionSourceObserver::class);

        InvoicePayment::observe(ActivityObserver::class);
        InvoiceNote::observe(ActivityObserver::class);
        Cheque::observe(ActivityObserver::class);
        StockMovement::observe(ActivityObserver::class);

        Product::observe(ProductInventoryObserver::class);
        foreach([Product::class,ProductVariant::class,InvoiceItem::class,PreinvoiceOrder::class]as$model)$model::observe(\App\Observers\InventoryCountFreezeObserver::class);
        ProductVariant::observe(ProductVariantSyncObserver::class);
        WarehouseStock::observe(WarehouseStockObserver::class);
        StockMovement::observe(StockMovementObserver::class);

        Paginator::useBootstrapFive();


        Blade::if('canPermission', function (string $permission): bool {
            return auth()->check()
                   && PermissionCatalog::userHasPermission(auth()->user(), $permission);
        });

        Blade::if('canAnyPermission', function (array|string $permissions): bool {
            if (! auth()->check()) {
                return false;
            }

            foreach ((array) $permissions as $permission) {
                if (PermissionCatalog::userHasPermission(auth()->user(), $permission)) {
                    return true;
                }
            }

            return false;
        });
    }
}
