<?php

use App\Http\Middleware\EnsurePageAccess;
use Illuminate\Http\Request;
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

it('shows only page permissions in role ui and only roles in user ui', function () {
    $roleController = file_get_contents(app_path('Http/Controllers/Admin/RoleController.php'));
    $roleView = file_get_contents(resource_path('views/admin/roles/form.blade.php'));
    $userView = file_get_contents(resource_path('views/admin/permissions/index.blade.php'));

    expect($roleController)->toContain("where('key', 'like', 'page.%')")
        ->and($roleView)->toContain('هر گزینه دسترسی کامل به همان صفحه')
        ->and($userView)->toContain('دسترسی صفحات فقط از نقش‌های کاربر محاسبه می‌شود.')
        ->and($userView)->not->toContain('name="direct_permissions[]"');
});

it('provides safe migration audit sync and cleanup commands', function () {
    foreach (['access:audit', 'access:sync-page-catalog', 'access:migrate-to-page-permissions', 'access:cleanup-legacy'] as $command) {
        expect(Artisan::all())->toHaveKey($command);
    }
});
