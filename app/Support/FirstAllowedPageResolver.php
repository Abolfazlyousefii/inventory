<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class FirstAllowedPageResolver
{
    public function destination(User $user, ?Request $request = null): string
    {
        if ($user->isSuperAdmin() || PageAccessCatalog::userCan($user, 'page.dashboard')) {
            return route('dashboard');
        }

        if ($request && ($intended = $request->session()->get('url.intended')) && $this->canVisitUrl($user, $intended)) {
            return $intended;
        }

        foreach (collect(PageAccessCatalog::pages())->sortBy('order') as $page) {
            if (! $this->canUsePage($user, $page)) continue;
            $url = $this->firstSafeRouteUrl($page['routes']);
            if ($url !== null) return $url;
        }

        return route('access.unassigned');
    }

    private function canUsePage(User $user, array $page): bool
    {
        if (PageAccessCatalog::userCan($user, $page['permission'])) return true;

        // Transitional compatibility only; migration decisions remain strict.
        return collect($page['legacy_permissions'])->contains(
            fn (string $permission) => PermissionCatalog::userHasPermission($user, $permission)
        );
    }

    private function firstSafeRouteUrl(array $names): ?string
    {
        foreach ($names as $name) {
            if (! Route::has($name)) continue;
            $route = Route::getRoutes()->getByName($name);
            if (! $route || ! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) continue;
            return route($name);
        }
        return null;
    }

    private function canVisitUrl(User $user, string $url): bool
    {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $route = collect(Route::getRoutes())->first(fn ($candidate) =>
            in_array('GET', $candidate->methods(), true) && $candidate->matches(Request::create($path, 'GET'))
        );
        $permission = $route ? PageAccessCatalog::permissionForRoute($route->getName()) : null;
        if (! $permission) return false;
        $page = collect(PageAccessCatalog::pages())->firstWhere('permission', $permission);
        return $page ? $this->canUsePage($user, $page) : false;
    }
}
