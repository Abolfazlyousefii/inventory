<?php

namespace App\Http\Middleware;

use App\Services\SiteOrderStockHoldService;
use App\Services\Sync\SitePaidOrdersSyncService;
use App\Services\Sync\SiteStockChangeOutboxService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opt-in, bounded, request-driven reconciliation. No cron or queue worker.
 * Running it before the warehouse/invoice query makes the reconciled records
 * available to the same existing UI. Failures are logged; pages still work.
 */
final class RequestDrivenSiteReconciliation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->enabled($request)) {
            $this->safeRun('expired holds', fn () => app(SiteOrderStockHoldService::class)->expireUnpaid(10));
            if ($this->invoicePage($request)) {
                $this->safeRun('paid orders', fn () => app(SitePaidOrdersSyncService::class)->syncAll());
            }
        }
        return $next($request);
    }

    /** Catch mutations performed during this request without delaying the response. */
    public function terminate(Request $request, Response $response): void
    {
        if (!$this->enabled($request)) return;
        $this->safeRun('stock sync after response', fn () => app(SiteStockChangeOutboxService::class)->drain(5));
    }

    private function enabled(Request $request): bool
    {
        return (bool) config('services.request_driven_site_stock', false)
            && $request->user() !== null
            && $request->is('invoices', 'invoices/*', 'warehouse', 'warehouse/*',
                'vouchers/*', 'products', 'products/*', 'preinvoice', 'preinvoice/*',
                'sales-havaleh', 'sales-havaleh/*');
    }

    private function invoicePage(Request $request): bool
    {
        return $request->is('invoices', 'invoices/data', 'vouchers/sales',
            'vouchers/sales/queue', 'vouchers/sales/queue/data');
    }

    private function safeRun(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::error('Request-driven Site reconciliation failed.', [
                'operation' => $operation,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
