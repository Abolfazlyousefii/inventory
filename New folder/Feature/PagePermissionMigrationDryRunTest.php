<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function migrationPermission(string $key): int
{
    $existing = DB::table('permissions')->where('key', $key)->value('id');
    if ($existing) return (int) $existing;

    return DB::table('permissions')->insertGetId([
        'key'=>$key, 'name'=>$key, 'group'=>'test', 'guard_name'=>'web',
        'created_at'=>now(), 'updated_at'=>now(),
    ]);
}

it('reports existing roles first and never invents per-user roles in dry run', function () {
    $view = migrationPermission('products.view');
    $direct = migrationPermission('customers.view');
    $role = Role::findOrCreate('Seller', 'web');
    DB::table('role_has_permissions')->insert(['role_id'=>$role->id, 'permission_id'=>$view]);
    $users = User::factory()->count(2)->create();
    foreach ($users as $user) {
        $user->assignRole($role);
        DB::table('user_permissions')->insert(['user_id'=>$user->id, 'permission_id'=>$direct, 'created_at'=>now(), 'updated_at'=>now()]);
    }
    $before = [DB::table('roles')->count(), DB::table('role_has_permissions')->count(), DB::table('user_permissions')->count()];

    $this->artisan('access:migrate-to-page-permissions --dry-run')->assertSuccessful();
    $report = json_decode(file_get_contents(storage_path('logs/page-permission-role-first-dry-run.json')), true);

    expect($report['roles'])->toHaveCount(1)
        ->and($report['roles'][0]['role_name'])->toBe('Seller')
        ->and($report['roles'][0]['current_legacy_permissions'])->toContain('products.view')
        ->and($report['roles'][0]['target_page_permissions'])->toBe([])
        ->and($report['roles'][0]['role_default_status'])->toBe('not_configured')
        ->and($report['shared_exception_role_suggestions'])->toHaveCount(1)
        ->and($report['shared_exception_role_suggestions'][0]['user_ids'])->toHaveCount(2)
        ->and(json_encode($report))->not->toContain('migrated-user-')
        ->and($report['database_changed'])->toBeFalse()
        ->and($report['legacy_deleted'])->toBeFalse()
        ->and([DB::table('roles')->count(), DB::table('role_has_permissions')->count(), DB::table('user_permissions')->count()])->toBe($before);
});

it('rejects exception-role generation unless apply is explicit', function () {
    $this->artisan('access:migrate-to-page-permissions --dry-run --generate-shared-exception-roles')
        ->assertExitCode(2);
});
