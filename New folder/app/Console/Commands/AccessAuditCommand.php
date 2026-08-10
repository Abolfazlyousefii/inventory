<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class AccessAuditCommand extends Command
{
    protected $signature = 'access:audit {--json} {--output=}';
    protected $description = 'گزارش فقط‌خواندنی دسترسی صفحه‌ای، نقش‌ها و Routeهای بدون نگاشت';

    public function handle(): int
    {
        $namedRoutes = collect(Route::getRoutes())->filter(fn ($route) => $route->getName());
        $authRoutes = $namedRoutes->filter(fn ($route) => in_array('auth', $route->gatherMiddleware(), true));
        $authenticatedAllowlist = ['access.unassigned','session.csrf-token','locations.provinces.index','locations.provinces.cities','profile.edit','profile.update','profile.destroy','verification.notice','verification.verify','verification.send','password.confirm','password.update','logout'];
        $tableCount = fn (string $table): int => Schema::hasTable($table) ? DB::table($table)->count() : 0;
        $report = [
            'counts' => [
                'roles' => $tableCount('roles'), 'legacy_permissions' => Schema::hasTable('permissions') ? DB::table('permissions')->where('key','not like','page.%')->count() : 0,
                'page_permissions' => Schema::hasTable('permissions') ? DB::table('permissions')->where('key','like','page.%')->count() : 0, 'catalog_pages' => count(PageAccessCatalog::pages()),
                'role_permissions' => $tableCount('role_has_permissions'), 'direct_permissions' => $tableCount('user_permissions'),
                'roles_without_permissions' => Schema::hasTable('roles') && Schema::hasTable('role_has_permissions') ? DB::table('roles')->leftJoin('role_has_permissions','roles.id','=','role_has_permissions.role_id')->whereNull('role_has_permissions.role_id')->count() : 0,
                'users_without_roles' => Schema::hasTable('users') && Schema::hasTable('model_has_roles') ? User::query()->doesntHave('roles')->count() : 0, 'users_with_multiple_roles' => Schema::hasTable('users') && Schema::hasTable('model_has_roles') ? User::query()->has('roles','>',1)->count() : 0,
            ],
            'catalog' => PageAccessCatalog::pages(),
            'named_authenticated_routes_without_page_mapping' => $authRoutes->map(fn ($route) => $route->getName())->filter(fn ($name) => PageAccessCatalog::permissionForRoute($name) === null && !in_array($name,$authenticatedAllowlist,true))->values()->all(),
            'intentionally_unprotected_authenticated_routes' => $authenticatedAllowlist,
            'direct_permission_users_count' => Schema::hasTable('user_permissions') ? DB::table('user_permissions')->distinct('user_id')->count('user_id') : 0,
            'role_permission_connections' => Schema::hasTable('role_has_permissions') ? DB::table('role_has_permissions')
                ->join('roles','roles.id','=','role_has_permissions.role_id')
                ->leftJoin('permissions','permissions.id','=','role_has_permissions.permission_id')
                ->orderBy('roles.id')->orderBy('permissions.id')
                ->get(['roles.id as role_id','roles.name as role_name','roles.guard_name as role_guard_name','role_has_permissions.permission_id','permissions.name as permission_name','permissions.key as permission_key','permissions.guard_name as permission_guard_name'])
                ->map(fn ($row) => (array) $row + ['exists'=>$row->permission_name !== null || $row->permission_key !== null, 'is_legacy'=>$row->permission_key !== null && !str_starts_with($row->permission_key,'page.'), 'is_page_permission'=>$row->permission_key !== null && str_starts_with($row->permission_key,'page.'), 'guard_matches'=>$row->role_guard_name === $row->permission_guard_name])->all() : [],
            'database_available' => Schema::hasTable('roles') && Schema::hasTable('permissions'),
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($output = $this->option('output')) { File::ensureDirectoryExists(dirname($output)); File::put($output, $json); }
        if ($this->option('json')) $this->line($json); else foreach ($report['counts'] as $key=>$value) $this->components->twoColumnDetail($key, (string)$value);
        return self::SUCCESS;
    }
}
