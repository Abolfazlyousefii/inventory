<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MigrateToPagePermissionsCommand extends Command
{
    protected $signature = 'access:migrate-to-page-permissions
        {--dry-run : Produce a read-only migration report}
        {--apply : Apply phase 1 to existing roles only}
        {--generate-shared-exception-roles : Create reviewed shared residual roles (requires --apply)}
        {--allow-single-user-exception-role : Permit one-user exception groups}
        {--allow-reviewed-undergrant : Acknowledge reviewed undergrant risks}';

    protected $description = 'Role-first migration of legacy access to page permissions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            $this->error('Exactly one of --dry-run or --apply is required.');
            return self::INVALID;
        }
        if ($this->option('generate-shared-exception-roles') && ! $apply) {
            $this->error('--generate-shared-exception-roles is only valid with --apply.');
            return self::INVALID;
        }
        if (! $this->databaseReady()) {
            $this->error('Access-control tables are not available; no changes were made.');
            return self::FAILURE;
        }

        $report = $this->buildReport($apply ? 'apply' : 'dry-run');
        if ($apply && $this->missingRequiredRoleDefaults() !== []) {
            $this->error('ماتریس دسترسی صفحه‌ای Roleها هنوز تأیید نشده است؛ اجرای Migration متوقف شد.');
            $this->writeReport($report);
            return self::FAILURE;
        }
        if ($apply) {
            $this->applyRolePhase($report);
            $report['database_changed'] = true;
        }
        $this->writeReport($report);
        return self::SUCCESS;
    }

    private function buildReport(string $mode): array
    {
        $pages = collect(PageAccessCatalog::pages())->sortBy('order')->values();
        $roles = Role::query()->orderBy('name')->orderBy('id')->get();
        $roleReports = [];
        $roleTargets = [];
        $ambiguous = [];
        $sensitive = [];
        $overgrants = [];
        $undergrants = [];
        $mappedLegacy = [];

        foreach ($roles as $role) {
            $connections = DB::table('role_has_permissions')
                ->leftJoin('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('role_has_permissions.role_id', $role->id)
                ->get(['role_has_permissions.permission_id','permissions.name','permissions.key','permissions.guard_name']);
            $keys = $connections->pluck('key')->filter()->unique()->sort()->values()->all();
            $legacy = collect($keys)->reject(fn ($key) => str_starts_with($key, 'page.'))->values()->all();
            $currentPages = collect($keys)->filter(fn ($key) => str_starts_with($key, 'page.'))->sort()->values()->all();
            $decisions = $this->decisions($legacy, $pages);
            $default = config('role_page_defaults.roles.'.$role->name);
            $isSuperAdmin = in_array($role->name, PermissionCatalog::superAdminRoles(), true);
            $confirmedDefault = is_array($default) && ($default['confirmed'] ?? false);
            $target = $isSuperAdmin ? PageAccessCatalog::permissions() : ($confirmedDefault ? collect($default['page_permissions'] ?? [])->intersect(PageAccessCatalog::permissions())->sort()->values()->all() : $currentPages);
            $roleTargets[$role->id] = $target;

            foreach ($decisions as $decision) {
                foreach ($decision['sources'] as $source) $mappedLegacy[$source] = true;
                $entry = ['scope' => 'role', 'role_id' => $role->id, 'role_name' => $role->name] + $decision;
                if ($decision['decision'] === 'ambiguous') $ambiguous[] = $entry;
                if ($decision['risk'] === 'high') $sensitive[] = $entry;
            }
            $priorPages = collect($currentPages);
            $lost = $isSuperAdmin ? [] : $priorPages->diff($target)->sort()->values()->all();
            $added = $isSuperAdmin ? [] : collect($target)->diff($priorPages)->sort()->values()->all();
            foreach ($lost as $page) $undergrants[] = ['scope'=>'role','role_id'=>$role->id,'role_name'=>$role->name,'page_permission'=>$page];
            foreach ($added as $page) $overgrants[] = ['scope'=>'role','role_id'=>$role->id,'role_name'=>$role->name,'page_permission'=>$page];

            $roleReports[] = [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'super_admin' => $isSuperAdmin,
                'role_guard_name' => $role->guard_name,
                'role_permission_connections' => $connections->map(fn ($p) => ['permission_id'=>$p->permission_id, 'permission_name'=>$p->name, 'permission_key'=>$p->key, 'permission_guard_name'=>$p->guard_name, 'exists'=>$p->name !== null || $p->key !== null, 'is_legacy'=>$p->key !== null && !str_starts_with($p->key,'page.'), 'is_page_permission'=>$p->key !== null && str_starts_with($p->key,'page.'), 'guard_matches_role'=>$p->guard_name === $role->guard_name])->all(),
                'role_default_status' => $isSuperAdmin ? 'super_admin' : (($default['intentionally_empty'] ?? false) ? 'intentionally_empty' : ($confirmedDefault ? 'confirmed' : 'not_configured')),
                'current_legacy_permissions' => $legacy,
                'current_page_permissions' => $currentPages,
                'target_page_permissions' => $target,
                'ambiguous_legacy_permissions' => collect($decisions)->where('decision', 'ambiguous')->pluck('sources')->flatten()->unique()->sort()->values()->all(),
                'overgrant_risks' => $added,
                'undergrant_risks' => $lost,
                'decisions' => $decisions,
            ];
        }

        $existingSignatures = [];
        foreach ($roleReports as $role) $existingSignatures[$this->signature($role['target_page_permissions'])] = $role['role_name'];
        $users = User::query()->with(['roles.permissions', 'permissions'])->orderBy('id')->get();
        $userReports = [];
        $residualGroups = [];
        $withoutRoles = [];
        $withoutAccess = [];
        $redirectFallbacks = [];

        foreach ($users as $user) {
            $assigned = $user->roles->sortBy('name')->pluck('name')->values()->all();
            $isSuperAdmin = $user->isSuperAdmin();
            $fromRoles = $isSuperAdmin ? collect(PageAccessCatalog::permissions()) : $user->roles->flatMap(fn ($role) => $roleTargets[$role->id] ?? [])->unique()->sort()->values();
            $directLegacy = $user->permissions->pluck('key')->filter(fn ($key) => ! str_starts_with($key, 'page.'))->unique()->sort()->values()->all();
            $directCurrentPages = $user->permissions->pluck('key')->filter(fn ($key) => str_starts_with($key, 'page.'));
            $directDecisions = $this->decisions($directLegacy, $pages);
            foreach ($directDecisions as $decision) {
                foreach ($decision['sources'] as $source) $mappedLegacy[$source] = true;
                $entry = ['scope'=>'user', 'user_id'=>$user->id] + $decision;
                if ($decision['decision'] === 'ambiguous') $ambiguous[] = $entry;
                if ($decision['risk'] === 'high') $sensitive[] = $entry;
            }
            $fromDirect = collect($directDecisions)->where('decision', 'grant')->pluck('page_permission')->merge($directCurrentPages)->unique()->sort()->values();
            $residual = $isSuperAdmin ? [] : $fromDirect->diff($fromRoles)->sort()->values()->all();
            $signature = $this->signature($residual);
            if ($residual !== []) $residualGroups[$signature] = array_merge($residualGroups[$signature] ?? ['permissions'=>$residual,'user_ids'=>[]], ['permissions'=>$residual, 'user_ids'=>array_merge($residualGroups[$signature]['user_ids'] ?? [], [$user->id])]);
            $effective = $fromRoles->merge($fromDirect)->unique()->sort()->values();
            $suggestedExisting = $residual === [] ? null : ($existingSignatures[$signature] ?? null);

            $userReports[] = [
                'user_id'=>$user->id, 'assigned_roles'=>$assigned,
                'pages_from_roles'=>$fromRoles->all(), 'pages_from_direct_permissions'=>$fromDirect->all(),
                'residual_pages'=>$residual, 'covered_by_roles'=>$isSuperAdmin || ($fromRoles->isNotEmpty() && $residual === []),
                'reason'=>!$isSuperAdmin && $fromRoles->isEmpty() && $assigned !== [] ? 'role_defaults_not_configured' : null,
                'suggested_existing_role'=>$isSuperAdmin ? null : $suggestedExisting, 'suggested_shared_exception_role'=>null,
                'direct_permission_decisions'=>$directDecisions,
            ];
            if ($assigned === []) $withoutRoles[] = $user->id;
            if (! $isSuperAdmin && $effective->isEmpty()) $withoutAccess[] = $user->id;
            elseif (! $effective->contains('page.dashboard')) $redirectFallbacks[] = ['user_id'=>$user->id, 'page_permission'=>$effective->first()];
        }

        $suggestions = [];
        $counterByCategory = [];
        foreach (collect($residualGroups)->sortKeys() as $signature => $group) {
            sort($group['user_ids']);
            if (isset($existingSignatures[$signature]) || $group['permissions'] === []) continue;
            $category = $this->exceptionCategory($group['permissions']);
            $counterByCategory[$category] = ($counterByCategory[$category] ?? 0) + 1;
            $suggestions[] = ['signature'=>$signature, 'suggested_name'=>sprintf('دسترسی سفارشی %s %02d', $category, $counterByCategory[$category]), 'user_ids'=>$group['user_ids'], 'permissions'=>$group['permissions'], 'eligible_for_generation'=>count($group['user_ids']) > 1 || (bool) $this->option('allow-single-user-exception-role')];
        }
        $suggestionNames = collect($suggestions)->keyBy('signature');
        foreach ($userReports as &$userReport) {
            $item = $suggestionNames->get($this->signature($userReport['residual_pages']));
            if ($item && $userReport['suggested_existing_role'] === null && $userReport['residual_pages'] !== []) $userReport['suggested_shared_exception_role'] = $item['suggested_name'];
        }
        unset($userReport);

        $allLegacy = DB::table('permissions')->where('key', 'not like', 'page.%')->pluck('key')->filter()->unique()->sort()->values();
        return [
            'mode'=>$mode, 'roles'=>$roleReports, 'users'=>$userReports,
            'super_admin_bypass'=>collect($roleReports)->where('super_admin',true)->map(fn ($r) => ['role_name'=>$r['role_name'],'page_count'=>count($r['target_page_permissions'])])->values()->all(),
            'shared_exception_role_suggestions'=>$suggestions,
            'ambiguous_mappings'=>collect($ambiguous)->sortBy(fn ($x) => ($x['role_name'] ?? '').'|'.$x['page_permission'])->values()->all(),
            'sensitive_grants'=>collect($sensitive)->sortBy(fn ($x) => ($x['role_name'] ?? '').'|'.$x['page_permission'])->values()->all(),
            'overgrants'=>$overgrants, 'undergrants'=>$undergrants,
            'legacy_permission_dispositions'=>PageAccessCatalog::legacyMigrationDispositions(),
            'unmapped_legacy_permissions'=>$allLegacy->reject(fn ($key) => isset($mappedLegacy[$key]) || isset(PageAccessCatalog::legacyMigrationDispositions()[$key]))->values()->all(),
            'roles_without_page_permissions'=>collect($roleReports)->filter(fn ($r) => $r['target_page_permissions'] === [])->pluck('role_name')->values()->all(),
            'roles_without_legacy_permissions'=>collect($roleReports)->filter(fn ($r) => $r['current_legacy_permissions'] === [])->pluck('role_name')->values()->all(),
            'users_without_roles'=>$withoutRoles, 'users_without_page_access'=>$withoutAccess,
            'login_redirect_fallbacks'=>$redirectFallbacks,
            'legacy_deleted'=>false, 'database_changed'=>false,
        ];
    }

    private function decisions(array $legacy, $pages): array
    {
        $result = [];
        foreach ($pages as $page) {
            $sources = collect($legacy)->intersect($page['legacy_permissions'])->sort()->values()->all();
            if ($sources === []) continue;
            $explicit = $page['migration_grant_permissions'] ?? [];
            $matched = array_values(array_intersect($sources, $explicit));
            $sensitive = (bool) ($page['sensitive'] ?? false);
            $grant = $matched !== [] && ! $sensitive;
            $result[] = [
                'page_permission'=>$page['permission'], 'sources'=>$sources,
                'decision'=>$sensitive ? 'review_required' : ($grant ? 'grant' : 'ambiguous'), 'risk'=>$sensitive ? 'high' : ($grant ? 'low' : 'medium'),
                'reason'=>$grant ? 'Explicit catalog entry permission matched.' : ($sensitive ? 'Sensitive page requires an explicit management/view mapping.' : 'Only action-level permissions matched; manual review is required.'),
            ];
        }
        return collect($result)->sortBy('page_permission')->values()->all();
    }

    private function applyRolePhase(array $report): void
    {
        DB::transaction(function () use ($report) {
            foreach (PageAccessCatalog::pages() as $page) {
                DB::table('permissions')->updateOrInsert(['key'=>$page['permission']], ['name'=>$page['label'], 'group'=>$page['group'], 'guard_name'=>'web', 'updated_at'=>now(), 'created_at'=>now()]);
            }
            foreach ($report['roles'] as $change) {
                if ($change['super_admin']) continue;
                $ids = DB::table('permissions')->whereIn('key', $change['target_page_permissions'])->pluck('id');
                foreach ($ids as $id) DB::table('role_has_permissions')->updateOrInsert(['role_id'=>$change['role_id'], 'permission_id'=>$id]);
            }
            if ($this->option('generate-shared-exception-roles')) $this->createSharedRoles($report['shared_exception_role_suggestions']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    private function createSharedRoles(array $suggestions): void
    {
        foreach ($suggestions as $suggestion) {
            if (! $suggestion['eligible_for_generation']) continue;
            $role = Role::findOrCreate($suggestion['suggested_name'], 'web');
            $ids = DB::table('permissions')->whereIn('key', $suggestion['permissions'])->pluck('id');
            foreach ($ids as $id) DB::table('role_has_permissions')->updateOrInsert(['role_id'=>$role->id, 'permission_id'=>$id]);
            foreach ($suggestion['user_ids'] as $userId) DB::table('model_has_roles')->updateOrInsert(['role_id'=>$role->id, 'model_type'=>(new User)->getMorphClass(), 'model_id'=>$userId]);
        }
    }

    private function writeReport(array $report): void
    {
        $jsonPath = storage_path('logs/page-permission-role-first-dry-run.json');
        $txtPath = storage_path('logs/page-permission-role-first-dry-run.txt');
        File::ensureDirectoryExists(dirname($jsonPath));
        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
        $lines = ['mode: '.$report['mode'], 'roles: '.count($report['roles']), 'users: '.count($report['users']), 'residual groups: '.count($report['shared_exception_role_suggestions']), 'overgrants: '.count($report['overgrants']), 'undergrants: '.count($report['undergrants']), 'unmapped legacy permissions: '.count($report['unmapped_legacy_permissions']), 'database changed: '.($report['database_changed'] ? 'yes' : 'no'), 'legacy deleted: no'];
        File::put($txtPath, implode(PHP_EOL, $lines).PHP_EOL);
        $this->info('JSON report: '.$jsonPath);
        $this->info('Text report: '.$txtPath);
    }

    private function signature(array $permissions): string
    {
        sort($permissions);
        return hash('sha256', implode("\n", $permissions));
    }

    private function exceptionCategory(array $permissions): string
    {
        $counts = ['فروش'=>0, 'انبار'=>0, 'مالی'=>0];
        foreach ($permissions as $permission) {
            if (str_contains($permission, '.sales.') || in_array($permission, ['page.customers'], true)) $counts['فروش']++;
            elseif (str_contains($permission, 'warehouse') || str_contains($permission, 'inventory')) $counts['انبار']++;
            elseif (str_contains($permission, 'finance') || str_contains($permission, 'payments')) $counts['مالی']++;
        }
        arsort($counts);
        return reset($counts) > 0 ? (string) array_key_first($counts) : 'ترکیبی';
    }

    private function databaseReady(): bool
    {
        return collect(['users','roles','permissions','role_has_permissions','model_has_roles','user_permissions'])->every(fn ($table) => Schema::hasTable($table));
    }

    private function missingRequiredRoleDefaults(): array
    {
        return collect(config('role_page_defaults.required_before_apply', []))->filter(function ($name) {
            $default = config('role_page_defaults.roles.'.$name, []);
            return ! ($default['disabled'] ?? false) && (! ($default['confirmed'] ?? false) || ($default['page_permissions'] ?? []) === []);
        })->values()->all();
    }
}
