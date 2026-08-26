<?php

namespace App\Http\Middleware;

use App\Http\Middleware\EnsurePageAccess;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoutePermissionMiddleware
{
    /**
     * These keys are action-level permissions and must be checked directly.
     * They must not be satisfied by their migrated page permission (page.assets),
     * otherwise a user with only page.assets could run create/edit/cancel/etc.
     *
     * @var array<int, string>
     */
    private const STRICT_DIRECT_PERMISSION_KEYS = [
        'assets.documents.create',
        'assets.documents.edit',
        'assets.documents.confirm',
        'assets.documents.cancel',
        'assets.documents.print',
        'assets.codes.search',
    ];

    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $customerPermissions = [
            'customers.index' => 'customers.view',
            'customers.show' => 'customers.view',
            'customers.create' => 'customers.create',
            'customers.store' => 'customers.create',
            'customers.edit' => 'customers.update',
            'customers.update' => 'customers.update',
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
            $permission = $routeName ? (PermissionCatalog::routePermissions()[$routeName] ?? null) : null;
        }

        if ($permission === null) {
            return $next($request);
        }

        if (in_array($permission, self::STRICT_DIRECT_PERMISSION_KEYS, true)) {
            return $this->handleStrictDirectPermission($request, $next, $permission, $routeName);
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

    private function handleStrictDirectPermission(Request $request, Closure $next, string $permission, ?string $routeName): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        // The action permission is not enough by itself; the user must also have
        // access to the parent asset page/workflow.
        if (! PageAccessCatalog::userCanRoute($user, $routeName)) {
            abort(403, 'شما دسترسی لازم برای مشاهده این بخش را ندارید.');
        }

        if ($this->userHasDirectPermissionKey($user, $permission)) {
            return $next($request);
        }

        abort(403, 'شما دسترسی لازم برای مشاهده این بخش را ندارید.');
    }

    private function userHasDirectPermissionKey($user, string $permission): bool
    {
        if (! method_exists($user, 'getAllPermissions')) {
            return false;
        }

        return $user->getAllPermissions()->contains(function ($granted) use ($permission): bool {
            return ($granted->key ?? null) === $permission
                || ($granted->name ?? null) === $permission;
        });
    }
}
