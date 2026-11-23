<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Personnel\CrmOrganizationImport;
use App\Services\Personnel\OrganizationHierarchy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmOrganizationImportTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $rows): array
    {
        return ['schema_version'=>1,'source'=>'ariya-crm','resolver'=>'OrganizationHierarchyService.directManager','users'=>$rows];
    }

    public function test_dry_run_maps_crm_ids_and_normalizes_only_self_roots_without_mutating_users(): void
    {
        $boss=User::factory()->create(['crm_user_id'=>'1']);$staff=User::factory()->create(['crm_user_id'=>'2']);
        $payload=$this->payload([['crm_id'=>'1','manager_crm_id'=>'1','department'=>'مدیریت','position_key'=>'executive_manager'],['crm_id'=>'2','manager_crm_id'=>'1','department'=>'فروش','position_key'=>'staff']]);
        $plan=app(CrmOrganizationImport::class)->plan($payload);
        $this->assertSame([],$plan['errors']);
        $this->assertSame(1,count($plan['normalized_roots']));
        $this->assertSame($boss->id,$plan['changes'][$staff->id]['after']['manager_id']);
        $this->assertNull($staff->fresh()->manager_id);
    }

    public function test_apply_is_atomic_idempotent_and_does_not_change_auth_or_roles(): void
    {
        $boss=User::factory()->create(['crm_user_id'=>'1']);$staff=User::factory()->create(['crm_user_id'=>'2']);
        $before=$staff->fresh()->only(['password','is_active','can_access_erp','is_seller']);
        $payload=$this->payload([['crm_id'=>'1','manager_crm_id'=>null,'position_key'=>'executive_manager'],['crm_id'=>'2','manager_crm_id'=>'1','position_key'=>'staff']]);
        $service=app(CrmOrganizationImport::class);$plan=$service->plan($payload);
        $service->apply($payload,$plan['hash']);
        $this->assertSame($boss->id,$staff->fresh()->manager_id);
        $this->assertSame($before,$staff->fresh()->only(array_keys($before)));
        $this->assertSame([],$service->plan($payload)['changes']);
        $this->assertSame([$boss->id],app(OrganizationHierarchy::class)->managerChain($staff->fresh())->pluck('id')->all());
    }

    public function test_cycles_missing_mappings_local_edits_and_stale_plan_block_apply(): void
    {
        $a=User::factory()->create(['crm_user_id'=>'1']);$b=User::factory()->create(['crm_user_id'=>'2']);
        $service=app(CrmOrganizationImport::class);
        $cycle=$this->payload([['crm_id'=>'1','manager_crm_id'=>'2'],['crm_id'=>'2','manager_crm_id'=>'1']]);
        $this->assertNotEmpty($service->plan($cycle)['errors']);
        $missing=$this->payload([['crm_id'=>'1','manager_crm_id'=>'999']]);
        $this->assertNotEmpty($service->plan($missing)['errors']);
        $payload=$this->payload([['crm_id'=>'1','manager_crm_id'=>null],['crm_id'=>'2','manager_crm_id'=>'1']]);
        $plan=$service->plan($payload);$b->update(['organization_managed_locally'=>true,'position'=>'ویرایش محلی']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->apply($payload,$plan['hash']);
    }

    public function test_review_scope_uses_the_same_chain_and_never_treats_others_as_subordinates(): void
    {
        $boss=User::factory()->create(['is_active'=>true,'can_access_erp'=>true]);$lead=User::factory()->create(['manager_id'=>$boss->id,'is_active'=>true,'can_access_erp'=>true]);$staff=User::factory()->create(['manager_id'=>$lead->id]);$other=User::factory()->create();
        $hierarchy=app(OrganizationHierarchy::class);
        $this->assertSame([$lead->id,$boss->id],$hierarchy->managerChain($staff)->pluck('id')->all());
        $this->assertTrue($hierarchy->isInTeam($boss,$staff));
        $this->assertFalse($hierarchy->isInTeam($lead,$other));
        $this->assertFalse($hierarchy->isInTeam($boss,$boss));
    }

    public function test_missing_profiles_are_disabled_and_have_no_roles_even_if_their_source_title_is_manager(): void
    {
        $payload=$this->payload([['crm_id'=>'61','name'=>'مدیر منتقل‌شده','manager_crm_id'=>null,'position_key'=>'executive_manager']]);
        $service=app(CrmOrganizationImport::class);$plan=$service->plan($payload);
        $this->assertSame(1,$service->prepareMissingProfiles($payload,$plan['hash']));
        $user=User::where('crm_user_id','61')->firstOrFail();
        $this->assertFalse($user->is_active);$this->assertFalse($user->can_access_erp);$this->assertSame(0,$user->roles()->count());
        $newPlan=$service->plan($payload);$service->apply($payload,$newPlan['hash']);
        $this->assertSame('مدیر اجرایی',$user->fresh()->position);
        $this->assertFalse($user->fresh()->can_access_erp);
    }
}
