<?php

namespace App\Http\Middleware;

use App\Http\Middleware\EnsurePageAccess;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoutePermissionMiddleware
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $customerPermissions = [
            'customers.index' => 'customers.view', 'customers.show' => 'customers.view',
            'customers.create' => 'customers.create', 'customers.store' => 'customers.create',
            'customers.edit' => 'customers.update', 'customers.update' => 'customers.update',
            'customers.destroy' => 'customers.delete',
        ];
        $routeName = $request->route()?->getName();
        if ($routeName === 'dashboard') {
            return app(CheckPermission::class)->handle($request, $next, 'dashboard.view');
        }
        if (isset($customerPermissions[$routeName])) {
            return app(CheckPermission::class)->handle($request, $next, $customerPermissions[$routeName]);
        }

        if ($permissions === []) {
            return app(EnsurePageAccess::class)->handle($request, $next);
        }
        $permission = $permissions[0] ?? null;

        if ($permission === null) {
            $routeName = $request->route()?->getName();
            $permission = $routeName ? (PermissionCatalog::routePermissions()[$routeName] ?? null) : null;
        }

        if ($permission === null) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && PermissionCatalog::userHasPermission($user, $permission)) {
            return $next($request);
        }

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        abort(403, 'شما دسترسی لازم برای مشاهده این بخش را ندارید.');
    }

}
