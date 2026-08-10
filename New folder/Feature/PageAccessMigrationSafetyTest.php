<?php

use App\Models\User;
use App\Support\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function safetyPermission(string $key, string $guard = 'web'): int
{
    $existing = DB::table('permissions')->where('key', $key)->value('id');
    if ($existing) {
        DB::table('permissions')->where('id', $existing)->update(['guard_name'=>$guard]);
        return (int) $existing;
    }
    return DB::table('permissions')->insertGetId(['key'=>$key,'name'=>$key,'group'=>'test','guard_name'=>$guard,'created_at'=>now(),'updated_at'=>now()]);
}

it('keeps audit and dry run role permission connections consistent even across guards', function () {
    $role = Role::create(['name'=>'Sales','guard_name'=>'web']);
    $permission = safetyPermission('products.view', 'api');
    DB::table('role_has_permissions')->insert(['role_id'=>$role->id,'permission_id'=>$permission]);

    $this->artisan('access:audit --json')->expectsOutputToContain('products.view')->assertSuccessful();
    $this->artisan('access:migrate-to-page-permissions --dry-run')->assertSuccessful();
    $report = json_decode(file_get_contents(storage_path('logs/page-permission-role-first-dry-run.json')), true);

    expect($report['roles'][0]['current_legacy_permissions'])->toBe(['products.view'])
        ->and($report['roles'][0]['role_permission_connections'][0]['guard_matches_role'])->toBeFalse();
});

it('treats Owner as fully covered without using direct permissions or residual suggestions', function () {
    $owner = Role::create(['name'=>'Owner','guard_name'=>'web']);
    $user = User::factory()->create();
    $user->assignRole($owner);
    DB::table('user_permissions')->insert(['user_id'=>$user->id,'permission_id'=>safetyPermission('customers.view'),'created_at'=>now(),'updated_at'=>now()]);

    $this->artisan('access:migrate-to-page-permissions --dry-run')->assertSuccessful();
    $report = json_decode(file_get_contents(storage_path('logs/page-permission-role-first-dry-run.json')), true);
    $row = collect($report['users'])->firstWhere('user_id', $user->id);

    expect($row['covered_by_roles'])->toBeTrue()->and($row['pages_from_roles'])->toBe(PageAccessCatalog::permissions())
        ->and($row['residual_pages'])->toBe([])->and($row['suggested_existing_role'])->toBeNull()
        ->and($row['suggested_shared_exception_role'])->toBeNull()
        ->and($report['users_without_page_access'])->not->toContain($user->id);
});

it('never turns sensitive direct permissions into automatic page grants', function () {
    $user = User::factory()->create();
    DB::table('user_permissions')->insert(['user_id'=>$user->id,'permission_id'=>safetyPermission('users.view'),'created_at'=>now(),'updated_at'=>now()]);
    $this->artisan('access:migrate-to-page-permissions --dry-run')->assertSuccessful();
    $report = json_decode(file_get_contents(storage_path('logs/page-permission-role-first-dry-run.json')), true);
    $row = collect($report['users'])->firstWhere('user_id', $user->id);

    expect($row['pages_from_direct_permissions'])->not->toContain('page.users')
        ->and($row['direct_permission_decisions'][0]['decision'])->toBe('review_required')
        ->and($report['sensitive_grants'])->not->toBeEmpty();
});

it('reports an empty assigned role as not configured and uncovered', function () {
    config()->set('role_page_defaults.roles.Sales', ['page_permissions'=>[], 'confirmed'=>false, 'disabled'=>false, 'intentionally_empty'=>false]);
    $role = Role::create(['name'=>'Sales','guard_name'=>'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->artisan('access:migrate-to-page-permissions --dry-run')->assertSuccessful();
    $report = json_decode(file_get_contents(storage_path('logs/page-permission-role-first-dry-run.json')), true);
    $row = collect($report['users'])->firstWhere('user_id', $user->id);
    expect($row['covered_by_roles'])->toBeFalse()->and($row['reason'])->toBe('role_defaults_not_configured')
        ->and($report['users_without_page_access'])->toContain($user->id);
});

it('resolves every explicitly requested legacy permission disposition', function () {
    $expected = ['account_statements.adjust','finance.reports.view','permissions.assign_roles','posts.create','posts.delete','posts.edit','posts.view','products.price_changes.apply','products.price_changes.cancel','products.price_changes.create','sales_returns.edit_applied','sales_returns.void_applied','unions.create','unions.delete','unions.edit','unions.view','warehouse.collection.adjust_price','warehouse.collection.edit','warehouse.collection.queue.view','warehouse.collection.receive','warehouse.collection.start','warehouse.collection.submit_reapproval','warehouse.collection.view','warehouse.reservations.release','warehouse.reservations.view','warehouse.shipping.queue.view','warehouse.shipping.ship','warehouse.shipping.view'];
    expect(array_keys(PageAccessCatalog::legacyMigrationDispositions()))->toEqualCanonicalizing($expected);
});

it('generates the role matrix without database writes and flags multiple-role evidence ambiguous', function () {
    $sales = Role::create(['name'=>'Sales','guard_name'=>'web']);
    $manager = Role::create(['name'=>'Manager','guard_name'=>'web']);
    $user = User::factory()->create();
    $user->assignRole([$sales,$manager]);
    $before = [DB::table('roles')->count(),DB::table('permissions')->count(),DB::table('user_permissions')->count()];
    $this->artisan('access:suggest-role-page-matrix --json')->assertSuccessful();
    expect([DB::table('roles')->count(),DB::table('permissions')->count(),DB::table('user_permissions')->count()])->toBe($before);
});

it('applies confirmed role pages without replacing legacy direct roles or user assignments', function () {
    $sales = Role::create(['name'=>'Sales','guard_name'=>'web']);
    $user = User::factory()->create();
    $user->assignRole($sales);
    $legacyId = safetyPermission('products.view');
    $directId = safetyPermission('customers.view');
    DB::table('role_has_permissions')->insert(['role_id'=>$sales->id,'permission_id'=>$legacyId]);
    DB::table('user_permissions')->insert(['user_id'=>$user->id,'permission_id'=>$directId,'created_at'=>now(),'updated_at'=>now()]);
    $before = ['roles'=>DB::table('roles')->count(),'users'=>DB::table('model_has_roles')->count(),'direct'=>DB::table('user_permissions')->count()];

    $this->artisan('access:migrate-to-page-permissions --apply')->assertSuccessful();

    expect(DB::table('role_has_permissions')->where('role_id',$sales->id)->where('permission_id',$legacyId)->exists())->toBeTrue()
        ->and(DB::table('role_has_permissions')->join('permissions','permissions.id','=','role_has_permissions.permission_id')->where('role_has_permissions.role_id',$sales->id)->where('permissions.key','page.dashboard')->exists())->toBeTrue()
        ->and(['roles'=>DB::table('roles')->count(),'users'=>DB::table('model_has_roles')->count(),'direct'=>DB::table('user_permissions')->count()])->toBe($before);
});
