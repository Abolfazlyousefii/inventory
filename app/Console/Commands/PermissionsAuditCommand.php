<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

class PermissionsAuditCommand extends Command
{
    protected $signature = 'permissions:audit {--json} {--user=} {--role=} {--module=} {--repair-safe}';
    protected $description = 'حسابرسی بدون تغییر نقش‌ها و دسترسی‌ها';

    public function handle(): int
    {
        $registry = collect(PermissionCatalog::registry());
        if ($module = $this->option('module')) $registry = $registry->where('module', $module);
        $routes = collect(Route::getRoutes())->filter(fn ($r) => $r->getName());
        $mapped = PermissionCatalog::routePermissions();
        $hasCatalogMapping = fn (?string $name): bool => $name !== null
            && (isset($mapped[$name]) || PageAccessCatalog::permissionsForRoute($name) !== []);
        $pageProtectedRoutes = $routes->filter(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => $middleware === 'route.permission' || str_starts_with($middleware, 'route.permission:')));
        $db = DB::table('permissions')->get();
        $dbKeys = $db->pluck('key')->filter();
        $duplicates = DB::table('permissions')->select('key', DB::raw('COUNT(*) as total'))->whereNotNull('key')->groupBy('key')->having('total','>',1)->get();
        $roleOnly = $routes->filter(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'role:')) && ! $hasCatalogMapping($r->getName()))->pluck('uri')->values();
        $deprecatedAssigned = DB::table('user_permissions')->join('permissions','permissions.id','=','user_permissions.permission_id')->whereIn('permissions.key',$registry->where('deprecated',true)->keys())->count();
        $report = [
            'counts' => ['roles'=>DB::table('roles')->count(),'permissions'=>$db->count(),'registry'=>$registry->count()],
            'duplicate_permissions' => $duplicates,
            'registry_missing_in_database' => $registry->keys()->diff($dbKeys)->values(),
            'database_outside_registry' => $dbKeys->diff(PermissionCatalog::registry() ? array_keys(PermissionCatalog::registry()) : [])->values(),
            'permissions_without_routes' => $registry->filter(fn($p)=>$p['routes']===[])->keys()->values(),
            'named_routes_without_catalog_mapping' => $pageProtectedRoutes
                ->map(fn ($route) => $route->getName())
                ->reject(fn ($name) => in_array($name, PageAccessCatalog::authenticatedRouteAllowlist(), true))
                ->filter(fn ($name) => ! $hasCatalogMapping($name))
                ->values(),
            'role_only_routes' => $roleOnly,
            'deprecated_active_assignments' => $deprecatedAssigned,
            'role_aliases' => PermissionCatalog::roleAliases(),
            'broken_dependencies' => $registry->flatMap(fn($p)=>array_diff($p['depends_on'],array_keys(PermissionCatalog::registry())))->unique()->values(),
        ];
        if ($this->option('user')) {
            $report['user'] = User::find($this->option('user'))?->only(['id','name']);
        }
        if ($this->option('repair-safe')) {
            PermissionCatalog::syncToDatabase();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $report['repair_safe'] = 'فقط دسترسی‌های گمشده Registry همگام شدند؛ هیچ انتسابی حذف نشد.';
        }
        if ($this->option('json')) $this->line(json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        else foreach ($report as $title=>$value) { $this->components->twoColumnDetail($title, is_countable($value)?count($value):(string)$value); }
        return self::SUCCESS;
    }
}
