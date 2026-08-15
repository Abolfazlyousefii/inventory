<?php

use App\Http\Middleware\EnsurePageAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('fails closed for an unknown explicit page key', function () {
    $request = Request::create('/private', 'GET');

    expect(fn () => app(EnsurePageAccess::class)->handle($request, fn () => response('ok'), 'unknown.page'))
        ->toThrow(HttpException::class, 'کلید دسترسی صفحه معتبر نیست.');
});

it('registers the central middleware and keeps route compatibility behind it', function () {
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
    $middleware = file_get_contents(app_path('Http/Middleware/RoutePermissionMiddleware.php'));

    expect($bootstrap)->toContain("'page.access' => EnsurePageAccess::class")
        ->and($middleware)->toContain("app(EnsurePageAccess::class)->handle(\$request, \$next)");
});

it('shows page permissions and the explicit commission action allowlist in role ui but only roles in user ui', function () {
    $roleController = file_get_contents(app_path('Http/Controllers/Admin/RoleController.php'));
    $roleView = file_get_contents(resource_path('views/admin/roles/form.blade.php'));
    $userView = file_get_contents(resource_path('views/admin/permissions/index.blade.php'));

    expect($roleController)->toContain("where('key', 'like', 'page.%')")
        ->and($roleController)->toContain("'commissions.manage_rates', 'commissions.manage_campaigns', 'commissions.manage_periods'")
        ->and($roleView)->toContain('هر گزینه دسترسی کامل به همان صفحه')
        ->and($roleView)->toContain('عملیات حساس پورسانت')
        ->and($userView)->toContain('دسترسی صفحات فقط از نقش‌های کاربر محاسبه می‌شود.')
        ->and($userView)->not->toContain('name="direct_permissions[]"');
});

it('provides safe migration audit sync and cleanup commands', function () {
    foreach (['access:audit', 'access:sync-page-catalog', 'access:migrate-to-page-permissions', 'access:cleanup-legacy'] as $command) {
        expect(Artisan::all())->toHaveKey($command);
    }
});

it('maps every authenticated named route or documents it in the allowlist', function () {
    $allowlist = \App\Support\PageAccessCatalog::authenticatedRouteAllowlist();
    $unmapped = collect(Route::getRoutes())
        ->filter(fn ($route) => $route->getName() && in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())
        ->filter(fn (string $name) => \App\Support\PageAccessCatalog::permissionsForRoute($name) === [] && ! in_array($name, $allowlist, true))
        ->values()
        ->all();

    expect($unmapped)->toBe([]);
});

it('keeps legacy role and permission middleware off mapped page routes', function () {
    $violations = collect(Route::getRoutes())
        ->filter(fn ($route) => $route->getName() && \App\Support\PageAccessCatalog::permissionsForRoute($route->getName()) !== [])
        ->flatMap(fn ($route) => collect($route->gatherMiddleware())
            ->filter(fn (string $middleware) => str_starts_with($middleware, 'role:') || str_starts_with($middleware, 'permission:'))
            ->map(fn (string $middleware) => $route->getName().':'.$middleware))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
