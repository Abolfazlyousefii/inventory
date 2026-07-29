<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ConvertRialCurrencyInputs;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Http\Middleware\CheckRoleOrRoutePermission;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->appendToGroup('web', ConvertRialCurrencyInputs::class);
        $middleware->appendToGroup('web', EnsureActiveUser::class);

        $middleware->alias([
            'role' => CheckRoleOrRoutePermission::class,
            'permission' => CheckPermission::class,
            'route.permission' => RoutePermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (TokenMismatchException $exception) {
            $request = request();
            $origin = parse_url((string) $request->headers->get('origin'), PHP_URL_HOST);
            $referer = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
            $cookieName = (string) config('session.cookie');

            Log::warning('CSRF token mismatch', [
                'timestamp' => now()->toIso8601String(),
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'host' => $request->getHost(),
                'scheme' => $request->getScheme(),
                'origin_host' => $origin ?: null,
                'referer_host' => $referer ?: null,
                'forwarded_host' => $request->headers->get('x-forwarded-host'),
                'forwarded_proto' => $request->headers->get('x-forwarded-proto'),
                'session_cookie_name' => $cookieName,
                'has_session_cookie' => $cookieName !== '' && $request->cookies->has($cookieName),
                'session_driver' => config('session.driver'),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'expects_json' => $request->expectsJson(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 180),
                'ip' => $request->ip(),
            ]);
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'نشست کاربری شما تغییر کرده یا منقضی شده است.',
                ], 419);
            }

            return null;
        });
    })->create();
