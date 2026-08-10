<?php

namespace App\Http\Middleware;

use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRoleOrRoutePermission
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny($request);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $routePermission = $routeName ? (PermissionCatalog::routePermissions()[$routeName] ?? null) : null;

        if ($routePermission !== null && PermissionCatalog::userHasPermission($user, $routePermission)) {
            return $next($request);
        }

        if ($user->hasAnyRole(explode('|', $roles))) {
            return $next($request);
        }

        return $this->deny($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->user() === null) {
            return redirect()->guest(route('login'));
        }

        abort(403, 'شما دسترسی لازم برای مشاهده این بخش را ندارید.');
    }
}
