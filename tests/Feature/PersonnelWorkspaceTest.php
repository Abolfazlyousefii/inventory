<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        PermissionCatalog::syncToDatabase();
        $user=User::factory()->create();$user->assignRole(Role::findOrCreate('Admin','web'));
        return $user;
    }

    public function test_inactive_people_are_hidden_by_default_without_hiding_their_active_team(): void
    {
        $admin=$this->admin();
        $manager=User::factory()->create(['name'=>'مدیر غیرفعال نمونه','is_active'=>false]);
        $staff=User::factory()->create(['name'=>'نیروی فعال نمونه','manager_id'=>$manager->id,'is_active'=>true,'can_access_erp'=>true]);
        User::factory()->create(['name'=>'ورود بسته نمونه','is_active'=>true,'can_access_erp'=>false]);
        $headers=['X-Requested-With'=>'XMLHttpRequest'];
        $this->actingAs($admin)->get('/users',$headers)->assertOk()->assertSee('نیروی فعال نمونه')->assertDontSee('مدیر غیرفعال نمونه')->assertDontSee('ورود بسته نمونه');
        $this->get('/users?show_inactive=1',$headers)->assertOk()->assertSee('مدیر غیرفعال نمونه')->assertSee('ورود بسته نمونه')->assertSee('نیروی فعال نمونه');
        $this->get('/users?q=نیروی',$headers)->assertOk()->assertSee('نیروی فعال نمونه')->assertDontSee('مدیر غیرفعال نمونه');
        $this->assertSame($manager->id,$staff->fresh()->manager_id);
    }

    public function test_ajax_tree_retains_ancestors_and_every_member_of_a_legacy_cycle(): void
    {
        $admin=$this->admin();$manager=User::factory()->create(['name'=>'مدیر آزمایشی']);
        $staff=User::factory()->create(['name'=>'نیروی مورد جستجو','manager_id'=>$manager->id]);
        $a=User::factory()->create(['name'=>'چرخه اول']);$b=User::factory()->create(['name'=>'چرخه دوم','manager_id'=>$a->id]);$a->update(['manager_id'=>$b->id]);
        $this->actingAs($admin)->get('/users?q=نیروی',['X-Requested-With'=>'XMLHttpRequest'])->assertOk()->assertSee('مدیر آزمایشی')->assertSee('نیروی مورد جستجو')->assertDontSee('چرخه اول');
        $response=$this->get('/users',['X-Requested-With'=>'XMLHttpRequest'])->assertOk()->assertSee('چرخه اول')->assertSee('چرخه دوم');
        $this->assertSame(User::where('is_active',true)->where('can_access_erp',true)->count(),substr_count($response->getContent(),'data-staff-node='));
        $all=$this->get('/users?show_inactive=1',['X-Requested-With'=>'XMLHttpRequest'])->assertOk();
        $this->assertSame(User::count(),substr_count($all->getContent(),'data-staff-node='));
    }

    public function test_editor_is_a_scoped_partial_and_cannot_be_loaded_without_permission(): void
    {
        $admin=$this->admin();$target=User::factory()->create();
        $url='/admin/permissions?panel=1&user_id='.$target->id;
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('data-staff-editor',false)->assertSee('مدیر مستقیم')->assertSee('دسترسی‌ها')->assertDontSee('<html',false);
    }

    public function test_json_access_updates_preserve_unedited_permissions_and_reject_stale_snapshot(): void
    {
        $admin=$this->admin();$target=User::factory()->create();$role=Role::findOrCreate('Sales','web');
        $snapshot=\App\Services\Personnel\PersonnelAccessSnapshot::version($target);
        $data=['user_id'=>$target->id,'roles_changed'=>1,'roles_submitted'=>1,'roles'=>[$role->name],'direct_permissions_changed'=>0,'access_version'=>$snapshot];
        $url='/admin/permissions/'.$target->id;
        $this->actingAs($admin)->putJson($url,$data)->assertOk()->assertJsonPath('ok',true);
        $data['roles']=[];
        $this->putJson($url,$data)->assertUnprocessable()->assertJsonValidationErrors('access_version');
        $this->assertTrue($target->fresh()->hasRole($role));
    }

    public function test_people_viewers_cannot_open_editor_and_permission_managers_can_use_the_single_entry(): void
    {
        PermissionCatalog::syncToDatabase();
        $viewer=User::factory()->create();$editor=User::factory()->create();$target=User::factory()->create();
        $viewer->permissions()->attach(\App\Models\AccessPermission::where('key','page.users')->value('id'));
        $editor->permissions()->attach(\App\Models\AccessPermission::where('key','page.roles')->value('id'));
        $this->actingAs($viewer)->get('/users')->assertOk()->assertDontSee('data-edit-staff=',false);
        $this->get('/admin/permissions?panel=1&user_id='.$target->id)->assertForbidden();
        $this->actingAs($editor)->get('/users')->assertOk()->assertSee('data-edit-staff=',false);
    }
}
