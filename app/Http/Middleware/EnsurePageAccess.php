<?php

namespace App\Http\Middleware;

use App\Support\PageAccessCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, ?string $pageKey = null): Response
    {
        $page = $pageKey ? PageAccessCatalog::page($pageKey) : null;
        if ($pageKey !== null && $page === null) {
            return $this->denied($request, 'کلید دسترسی صفحه معتبر نیست.');
        }

        $user = $request->user();
        if (! $user) return redirect()->guest(route('login'));

        if ($user->isSuperAdmin()) return $next($request);

        $routeName = $request->route()?->getName();
        if ($pageKey === null && in_array($routeName, PageAccessCatalog::authenticatedRouteAllowlist(), true)) {
            return $next($request);
        }

        $permissions = $page ? [$page['permission']] : PageAccessCatalog::permissionsForRoute($routeName);
        if ($permissions === []) {
            return $this->denied($request, 'برای این مسیر، صفحه دسترسی معتبری تعریف نشده است.');
        }

        if (collect($permissions)->contains(fn (string $permission) => PageAccessCatalog::userCan($user, $permission))) {
            return $next($request);
        }

        return $this->denied($request, 'شما به این صفحه یا فرآیند دسترسی ندارید.');
    }

    private function denied(Request $request, string $message): Response
    {
        if ($request->expectsJson()) return response()->json(['message' => $message], 403);
        abort(403, $message);
    }
}
