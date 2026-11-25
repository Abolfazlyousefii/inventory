<?php
namespace Tests\Feature;

use App\Models\{User, AccessPermission};
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelAccountManagementTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        PermissionCatalog::syncToDatabase();
        $user = User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $user->assignRole(Role::findOrCreate('Admin','web'));
        return $user;
    }
    private function payload(): array
    {
        return ['name'=>'New staff','phone'=>'۰۹۱۲۳۴۵۶۷۸۹','password'=>'Strong-pass-123','password_confirmation'=>'Strong-pass-123','is_active'=>1,'show_in_organization_chart'=>1,'organization_level'=>'staff'];
    }
    public function test_new_local_account_is_hashed_normalized_and_needs_explicit_role_assignment(): void
    {
        $this->actingAs($this->admin());
        $manager=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $data=$this->payload()+['manager_id'=>$manager->id,'roles'=>['Admin']];
        $response=$this->postJson('/admin/personnel/accounts',$data)->assertCreated()->assertJsonPath('ok',true);
        $user=User::findOrFail($response->json('id'));
        $this->assertSame('09123456789',$user->phone);
        $this->assertTrue(Hash::check($data['password'],$user->password));
        $this->assertFalse($user->is_crm_managed);
        $this->assertSame($manager->id,$user->manager_id);
        $this->assertTrue($user->organization_managed_locally);
        $this->assertCount(0,$user->roles);
        $response->assertDontSee($data['password']);
        $this->postJson('/admin/personnel/accounts',$data)->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
    public function test_account_creation_and_status_are_for_permission_managers_only(): void
    {
        PermissionCatalog::syncToDatabase();
        $viewer=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $viewer->permissions()->attach(AccessPermission::where('key','page.users')->value('id'));
        $target=User::factory()->create();
        $this->actingAs($viewer)->postJson('/admin/personnel/accounts',$this->payload())->assertForbidden();
        $this->putJson('/admin/personnel/accounts/'.$target->id.'/status',['is_active'=>0,'account_version'=>str_repeat('a',64)])->assertForbidden();
        $this->get('/users')->assertOk()->assertDontSee('data-create-staff',false);
    }
    public function test_status_preserves_chart_roles_and_history_and_rejects_stale_or_self_disable(): void
    {
        $admin=$this->admin();$target=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'show_in_organization_chart'=>false]);
        $this->actingAs($admin);
        $version=\App\Services\Personnel\PersonnelAccounts::version($target);
        $url='/admin/personnel/accounts/'.$target->id.'/status';
        $this->putJson($url,['is_active'=>0,'account_version'=>$version])->assertOk();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertFalse($target->fresh()->can_access_erp);
        $this->assertFalse($target->fresh()->show_in_organization_chart);
        $this->putJson($url,['is_active'=>1,'account_version'=>$version])->assertUnprocessable();
        $this->putJson($url,['is_active'=>1,'account_version'=>\App\Services\Personnel\PersonnelAccounts::version($target->fresh())])->assertOk();
        $this->assertTrue($target->fresh()->can_access_erp);
        $this->putJson('/admin/personnel/accounts/'.$admin->id.'/status',['is_active'=>0,'account_version'=>\App\Services\Personnel\PersonnelAccounts::version($admin)])->assertUnprocessable();
        $this->assertDatabaseHas('activity_logs',['action'=>'personnel.account_status_updated','subject_id'=>$target->id]);
    }
    public function test_last_active_administrator_cannot_be_disabled_by_another_permission_manager(): void
    {
        $admin=$this->admin();$editor=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $editor->permissions()->attach(AccessPermission::where('key','page.roles')->value('id'));
        $this->actingAs($editor)->putJson('/admin/personnel/accounts/'.$admin->id.'/status',['is_active'=>0,'account_version'=>\App\Services\Personnel\PersonnelAccounts::version($admin)])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
    }
    public function test_creation_validates_password_and_rejects_inactive_manager(): void
    {
        $this->actingAs($this->admin());$manager=User::factory()->create(['is_active'=>false]);
        $data=$this->payload();$data['password_confirmation']='different';
        $this->postJson('/admin/personnel/accounts',$data)->assertUnprocessable()->assertJsonValidationErrors('password');
        $data=$this->payload()+['manager_id'=>$manager->id];
        $this->postJson('/admin/personnel/accounts',$data)->assertUnprocessable()->assertJsonValidationErrors('manager_id');
        $this->assertDatabaseMissing('users',['phone'=>'09123456789']);
    }
    public function test_forms_and_write_routes_expose_account_controls_and_require_csrf(): void
    {
        $admin=$this->admin();$target=User::factory()->create();
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('data-create-staff',false)->assertSee('staffCreateForm',false);
        $this->get('/admin/permissions?panel=1&user_id='.$target->id)->assertOk()->assertSee('data-account-editor',false)->assertSee('وضعیت حساب');
        foreach (['admin.personnel.accounts.store','admin.personnel.accounts.status'] as $name) {
            $this->assertContains(\App\Http\Middleware\ProtectPersonnelCsrf::class, app('router')->getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    public function test_disabled_account_cannot_log_in_and_chart_is_independent(): void
    {
        $admin=$this->admin();$target=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'password'=>'Staff-pass-123']);
        $this->actingAs($admin)->putJson('/admin/personnel/accounts/'.$target->id.'/status',['is_active'=>0,'account_version'=>\App\Services\Personnel\PersonnelAccounts::version($target)])->assertOk();
        $this->assertTrue($target->fresh()->show_in_organization_chart);
        $this->app['auth']->forgetGuards();
        $this->post('/logout');
        $this->post('/login',['phone'=>$target->phone,'password'=>'Staff-pass-123'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_name_can_be_edited_without_changing_login_roles_or_manager_and_is_audited(): void
    {
        $actor=$this->admin();$manager=User::factory()->create();
        $target=User::factory()->create(['name'=>'Old name','manager_id'=>$manager->id]);
        $before=$target->refresh()->only(['phone','password','is_active','can_access_erp']);
        $version=\App\Services\Personnel\OrganizationManagement::version($target);
        $url='/admin/permissions/'.$target->id.'/organization';
        $this->actingAs($actor)->putJson($url,['name'=>'نام اصلاح شده','manager_id'=>$manager->id,'organization_version'=>$version])->assertOk();
        $this->assertSame('نام اصلاح شده',$target->fresh()->name);
        $this->assertSame($before,$target->fresh()->only(array_keys($before)));
        $this->assertSame($manager->id,$target->fresh()->manager_id);
        $log=\App\Models\ActivityLog::where('action','personnel.organization_updated')->latest('id')->firstOrFail();
        $this->assertSame('Old name',$log->properties['before']['name']);
        $this->putJson($url,['name'=>'Stale edit','manager_id'=>$manager->id,'organization_version'=>$version])->assertUnprocessable();
        $this->putJson($url,['name'=>'   ','organization_version'=>\App\Services\Personnel\OrganizationManagement::version($target->fresh())])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

}
