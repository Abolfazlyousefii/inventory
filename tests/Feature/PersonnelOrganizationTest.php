<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Personnel\OrganizationManagement;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        PermissionCatalog::syncToDatabase();
        $user=User::factory()->create();
        $user->assignRole(Role::findOrCreate('Admin','web'));
        return $user;
    }

    private function payload(User $user, ?User $manager): array
    {
        return ['manager_id'=>$manager?->id,'department'=>'بازرگانی','position'=>'کارشناس فروش','personnel_code'=>'P-12','branch'=>'مرکزی','organization_version'=>OrganizationManagement::version($user)];
    }

    public function test_organization_changes_are_authorized_audited_and_do_not_change_roles_or_login(): void
    {
        $actor=$this->admin();$target=User::factory()->create();$manager=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);
        $password=$target->password;
        $this->actingAs(User::factory()->create())->put('/admin/permissions/'.$target->id.'/organization',$this->payload($target,$manager))->assertForbidden();
        $this->actingAs($actor)->put('/admin/permissions/'.$target->id.'/organization',$this->payload($target,$manager))->assertSessionHasNoErrors()->assertRedirect();
        $target->refresh();
        $this->assertSame($manager->id,$target->manager_id);
        $this->assertTrue($target->organization_managed_locally);
        $this->assertSame($password,$target->password);
        $this->assertFalse($target->hasRole('Admin'));
        $this->assertDatabaseHas('activity_logs',['action'=>'personnel.organization_updated','subject_id'=>$target->id]);
    }

    public function test_self_management_cycles_and_stale_updates_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $a=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);$b=User::factory()->create(['manager_id'=>$a->id,'is_active'=>true,'can_access_erp'=>true]);
        $url='/admin/permissions/'.$a->id.'/organization';
        $this->put($url,$this->payload($a,$a))->assertSessionHasErrors('manager_id');
        $this->put($url,$this->payload($a,$b))->assertSessionHasErrors('manager_id');
        $data=$this->payload($a,null);
        $this->put($url,$data)->assertSessionHasNoErrors();
        $this->put($url,$data)->assertSessionHasErrors('organization_version');
    }

    public function test_directory_and_unified_management_render_without_changing_existing_permissions(): void
    {
        $admin=$this->admin();$target=User::factory()->create(['name'=>'نیروی آزمایشی']);
        $this->actingAs($admin)->get('/users?q=نیروی')->assertOk()->assertSee('نیروی آزمایشی')->assertSee('پرسنل و همکاری');
        $this->get('/admin/permissions?user_id='.$target->id)->assertOk()->assertSee('مدیر مستقیم')->assertSee('نقش‌های');
    }

    public function test_inactive_manager_and_invalid_organization_fields_cannot_be_saved(): void
    {
        $this->actingAs($this->admin());
        $target=User::factory()->create();$manager=User::factory()->create(['is_active'=>false]);
        $url='/admin/permissions/'.$target->id.'/organization';
        $this->put($url,$this->payload($target,$manager))->assertSessionHasErrors('manager_id');
        $data=$this->payload($target,null);$data['department']=str_repeat('x',101);
        $this->put($url,$data)->assertSessionHasErrors('department');
        $this->assertNull($target->fresh()->manager_id);
        $this->assertFalse($target->fresh()->organization_managed_locally);
    }

    public function test_directory_manager_filter_excludes_other_teams(): void
    {
        $this->actingAs($this->admin());
        $manager=User::factory()->create();
        User::factory()->create(['name'=>'نیروی همین مدیر','manager_id'=>$manager->id]);
        User::factory()->create(['name'=>'نیروی مدیر دیگر']);
        $this->get('/users?manager='.$manager->id)->assertOk()->assertSee('نیروی همین مدیر')->assertDontSee('نیروی مدیر دیگر');
    }
}
