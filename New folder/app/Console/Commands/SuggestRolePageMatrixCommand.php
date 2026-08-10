<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SuggestRolePageMatrixCommand extends Command
{
    protected $signature = 'access:suggest-role-page-matrix {--json} {--output=} {--minimum-percent=70}';
    protected $description = 'Generate a read-only, evidence-based role/page matrix suggestion';

    public function handle(): int
    {
        if (! collect(['roles','permissions','role_has_permissions','model_has_roles','user_permissions'])->every(fn ($table) => Schema::hasTable($table))) {
            $this->error('Access-control tables are not available; no changes were made.');
            return self::FAILURE;
        }
        $minimum = max(1, min(100, (int) $this->option('minimum-percent')));
        $before = $this->fingerprint();
        $configured = array_keys(config('role_page_defaults.roles', []));
        $roles = DB::table('roles')->whereIn('name', $configured)->orderBy('name')->get();
        $rows = [];
        foreach ($roles as $role) {
            $userIds = $this->userIds($role->id);
            if (in_array($role->name, PermissionCatalog::superAdminRoles(), true)) {
                $pages = PageAccessCatalog::permissions();
                $rows[] = $this->row($role->name, true, $pages, 'high', $userIds->count(), $pages, $pages, [], [], false);
                continue;
            }
            $single = DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', (new User)->getMorphClass())->groupBy('model_id')->havingRaw('COUNT(*) = 1')->pluck('model_id');
            $sets = $single->map(fn ($id) => $this->directPages((int) $id));
            $counts = $sets->flatten()->countBy();
            $all = $sets->isEmpty() ? collect() : $counts->filter(fn ($n) => $n === $sets->count())->keys()->sort()->values();
            $majority = $sets->isEmpty() ? collect() : $counts->filter(fn ($n) => $n * 100 / $sets->count() >= $minimum)->keys()->sort()->values();
            $ambiguous = $userIds->diff($single)->values()->all();
            $confidence = $single->count() >= 5 && $ambiguous === [] ? 'high' : ($single->count() >= 2 ? 'medium' : 'low');
            $rows[] = $this->row($role->name, false, $majority->all(), $confidence, $userIds->count(), $all->all(), $majority->all(), $counts->keys()->diff($majority)->sort()->values()->all(), $ambiguous, true);
        }
        foreach (collect($configured)->diff($roles->pluck('name')) as $name) $rows[] = $this->row($name, false, [], 'low', 0, [], [], [], [], true);
        $report = ['minimum_percent'=>$minimum, 'roles'=>collect($rows)->sortBy('role_name')->values()->all(), 'database_changed'=>$before !== $this->fingerprint()];
        $json = json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        if ($path = $this->option('output')) { File::ensureDirectoryExists(dirname($path)); File::put($path, $json); }
        if ($this->option('json')) $this->line($json); else $this->table(['Role','Users','Confidence','Suggested pages'], collect($report['roles'])->map(fn ($r) => [$r['role_name'],$r['evidence']['user_count'],$r['confidence'],count($r['suggested_page_permissions'])]));
        return self::SUCCESS;
    }

    private function row(string $name, bool $super, array $pages, string $confidence, int $users, array $all, array $majority, array $outliers, array $ambiguous, bool $confirmation): array
    {
        return ['role_name'=>$name, 'super_admin'=>$super, 'suggested_page_permissions'=>$pages, 'confidence'=>$confidence, 'evidence'=>['user_count'=>$users, 'common_to_all_users'=>$all, 'common_to_majority_users'=>$majority, 'outlier_pages'=>$outliers, 'ambiguous_multiple_role_user_ids'=>$ambiguous], 'requires_manual_confirmation'=>$confirmation];
    }
    private function userIds(int $roleId) { return DB::table('model_has_roles')->where('role_id',$roleId)->where('model_type',(new User)->getMorphClass())->pluck('model_id')->unique(); }
    private function directPages(int $userId): array
    {
        return DB::table('user_permissions')->join('permissions','permissions.id','=','user_permissions.permission_id')
            ->where('user_permissions.user_id',$userId)->pluck('permissions.key')->filter()->map(function ($key) {
                if (str_starts_with($key, 'page.')) {
                    $page = collect(PageAccessCatalog::pages())->firstWhere('permission', $key);
                    return ($page['sensitive'] ?? true) ? null : $key;
                }
                $decision = PageAccessCatalog::migrationDecision($key);
                return ($decision['decision'] ?? null) === 'grant' ? $decision['page_permission'] : null;
            })->filter()->unique()->sort()->values()->all();
    }
    private function fingerprint(): string { return hash('sha256', collect(['roles','permissions','role_has_permissions','model_has_roles','user_permissions'])->map(fn ($t) => $t.':'.DB::table($t)->count())->implode('|')); }
}
