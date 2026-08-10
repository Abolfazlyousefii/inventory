<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use App\Support\PermissionCatalog;
use Illuminate\Routing\Router;
use App\Models\Category;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceNote;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Observers\ActivityObserver;
use App\Observers\ProductInventoryObserver;
use App\Observers\StockMovementObserver;
use App\Observers\WarehouseStockObserver;
use App\Observers\ProductVariantSyncObserver;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Models\WarehouseStock;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('route.permission', RoutePermissionMiddleware::class);

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
        InvoicePayment::observe(ActivityObserver::class);
        InvoiceNote::observe(ActivityObserver::class);
        Cheque::observe(ActivityObserver::class);
        StockMovement::observe(ActivityObserver::class);

        Product::observe(ProductInventoryObserver::class);
        ProductVariant::observe(ProductVariantSyncObserver::class);
        WarehouseStock::observe(WarehouseStockObserver::class);
        StockMovement::observe(StockMovementObserver::class);
        Paginator::useBootstrapFive(); // یا useBootstrapFour()

        Blade::if('canPermission', function (string $permission): bool {
            return auth()->check() && PermissionCatalog::userHasPermission(auth()->user(), $permission);
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
