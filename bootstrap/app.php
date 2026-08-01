<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ConvertRialCurrencyInputs;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\RoutePermissionMiddleware;
use App\Http\Middleware\CheckRoleOrRoutePermission;
use App\Http\Middleware\EnsurePageAccess;
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
            'page.access' => EnsurePageAccess::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (TokenMismatchException $exception) {
            $request = request();
            $cookieName = (string) config('session.cookie');
            $sessionId = $request->hasSession() ? (string) $request->session()->getId() : '';
            $items = $request->input('items', []);

            Log::warning('CSRF token mismatch', [
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'authenticated' => $request->user() !== null,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'session_id_hash' => $sessionId !== '' ? hash('sha256', $sessionId) : null,
                'csrf_header_present' => $request->headers->has('x-csrf-token') || $request->headers->has('x-xsrf-token'),
                'csrf_body_present' => $request->request->has('_token'),
                'session_cookie_present' => $cookieName !== '' && $request->cookies->has($cookieName),
                'content_type' => $request->getContentTypeFormat(),
                'content_length' => (int) $request->headers->get('content-length', 0),
                'input_count' => count($request->all()),
                'variant_count' => is_array($items) ? count($items) : 0,
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
