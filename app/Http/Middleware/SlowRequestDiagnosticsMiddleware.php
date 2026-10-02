<?php

namespace App\Http\Middleware;

use App\Support\SlowRequestDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SlowRequestDiagnosticsMiddleware
{
    public function __construct(
        private readonly SlowRequestDiagnostics $diagnostics,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('slowdiag.enabled')) {
            return $next($request);
        }

        $this->diagnostics->begin($request);

        register_shutdown_function(function (): void {
            $this->diagnostics->shutdown();
        });

        $response = null;
        $exception = null;

        try {
            $response = $next($request);

            return $response;
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $this->diagnostics->finish($response, $exception);
        }
    }
}
