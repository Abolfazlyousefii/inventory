<?php
namespace Tests\Feature;

use App\Models\PersonnelEntry;
use App\Models\User;
use App\Services\Personnel\PersonnelAccess;
use App\Services\Personnel\PersonnelRoutingReconciliation;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelRoutingReconciliationTest extends TestCase
{
    use RefreshDatabase;
    private function team():array
    {
        PermissionCatalog::syncToDatabase();$role=Role::findOrCreate('Admin','web');
        $executive=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'organization_level'=>'executive_manager']);
        $internal=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'organization_level'=>'internal_manager','manager_id'=>$executive->id]);
        $supervisor=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'organization_level'=>'supervisor','manager_id'=>$internal->id]);
        $staff=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'organization_level'=>'staff','manager_id'=>$supervisor->id]);
        $outside=User::factory()->create(['is_active'=>true,'can_access_erp'=>true,'show_in_organization_chart'=>false]);
        foreach([$executive,$internal,$supervisor,$staff,$outside]as$u)$u->assignRole($role);
        return [$staff,$supervisor,$internal,$executive,$outside];
    }
    public function test_report_receivers_are_rebuilt_without_losing_history_or_granting_visitor_access():void
    {
        [$staff,$supervisor,$internal,$executive,$outside]=$this->team();
        $entry=PersonnelEntry::create(['submission_key'=>(string) \Illuminate\Support\Str::uuid(),'user_id'=>$staff->id,'kind'=>'report','status'=>'read','payload'=>[],'version'=>3]);
        $old=$entry->allSteps()->create(['sequence'=>1,'stage'=>'manager','approver_id'=>$outside->id,'approver_name'=>$outside->name,'status'=>'watching']);
        $service=app(PersonnelRoutingReconciliation::class);
        $this->assertTrue($service->reconcile($entry,$internal));
        $this->assertSame([$supervisor->id,$internal->id,$executive->id],$entry->steps()->pluck('approver_id')->all());
        $this->assertSame('watching',$old->fresh()->status);$this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame('read',$entry->fresh()->status);
        $this->assertFalse(app(PersonnelAccess::class)->view($outside,$entry->fresh()));
        $this->assertTrue(app(PersonnelAccess::class)->view($supervisor,$entry->fresh()));
        $this->assertFalse($service->reconcile($entry,$internal));$this->assertSame(4,$entry->allSteps()->count());
        if($path=getenv('PERSONNEL_ROUTING_PREVIEW')){
            $supervisor->update(['name'=>'سرپرست بخش']);$internal->update(['name'=>'خانم مسلمی']);$executive->update(['name'=>'مهندس دست‌آموز']);
            foreach($entry->steps()->get()as$step){$step->approver_name=User::find($step->approver_id)->name;$step->save();}
            $entry->title='گزارش فعالیت روزانه';$entry->load(['user','steps','events.actor','attachments']);
            $detail=view('personnel.workflows.detail',['entry'=>$entry,'kind'=>'report','owner'=>true,'canDecide'=>false,'canFeedback'=>false])->render();
            file_put_contents($path,'<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="http://127.0.0.1:8000/css/personnel-workflows.css"><style>@font-face{font-family:v;src:url(http://127.0.0.1:8000/fonts/vazirmatn/Vazirmatn-Regular.woff2)}body{font-family:v;background:#eef3f9;margin:0;padding:24px;color:#244458}.preview{max-width:800px;margin:auto;background:white;border-radius:18px;padding:12px}.hr{--hr-border:#dce6ef;--hr-muted:#8094a7;--hr-surface:#fff}</style><div class="preview hr hr-kind-report">'.$detail.'</div></html>');
        }
    }
    public function test_supervisor_reports_skip_unit_stage_and_inactive_authors_are_untouched():void
    {
        [$staff,$supervisor,$internal,$executive]=$this->team();
        $entry=PersonnelEntry::create(['submission_key'=>(string) \Illuminate\Support\Str::uuid(),'user_id'=>$supervisor->id,'kind'=>'report','status'=>'submitted','payload'=>[],'version'=>1]);
        app(PersonnelRoutingReconciliation::class)->reconcile($entry,$internal);
        $this->assertSame([$internal->id,$executive->id],$entry->steps()->pluck('approver_id')->all());
        $staff->update(['is_active'=>false]);$entry->user_id=$staff->id;$entry->payload=[];$entry->save();
        $before=$entry->allSteps()->get()->toJson();
        $this->assertFalse(app(PersonnelRoutingReconciliation::class)->reconcile($entry,$internal));
        $this->assertSame($before,$entry->allSteps()->get()->toJson());
    }
    public function test_pending_leave_preserves_decisions_and_becomes_sequential_without_auto_approval():void
    {
        [$staff,$supervisor,$internal,$executive,$outside]=$this->team();
        $entry=PersonnelEntry::create(['submission_key'=>(string) \Illuminate\Support\Str::uuid(),'user_id'=>$staff->id,'kind'=>'leave','status'=>'pending','payload'=>['crm_approval_route'=>'legacy'],'version'=>1]);
        $old=$entry->steps()->create(['sequence'=>1,'stage'=>'supervisor','approver_id'=>$supervisor->id,'approver_name'=>$supervisor->name,'status'=>'approved','decided_at'=>now()]);
        app(PersonnelRoutingReconciliation::class)->reconcile($entry,$internal);
        $this->assertSame(['approved','pending','waiting'],$entry->steps()->pluck('status')->all());
        $this->assertSame('approved',$old->fresh()->status);$this->assertSame('pending',$entry->fresh()->status);
        $this->assertTrue(app(PersonnelAccess::class)->decide($internal,$entry->fresh()));
        $this->assertFalse(app(PersonnelAccess::class)->decide($executive,$entry->fresh()));
    }
    public function test_incomplete_chart_fails_without_replacing_original_steps():void
    {
        [$staff,$supervisor,$internal]=$this->team();$staff->update(['manager_id'=>null]);
        $entry=PersonnelEntry::create(['submission_key'=>(string) \Illuminate\Support\Str::uuid(),'user_id'=>$staff->id,'kind'=>'report','status'=>'submitted','payload'=>[],'version'=>1]);
        $old=$entry->steps()->create(['sequence'=>1,'stage'=>'manager','approver_id'=>$supervisor->id,'approver_name'=>$supervisor->name,'status'=>'watching']);
        try{app(PersonnelRoutingReconciliation::class)->reconcile($entry,$internal);$this->fail('Must reject incomplete chart');}catch(\DomainException){}
        $this->assertNull($old->fresh()->superseded_at);$this->assertSame(1,$entry->steps()->count());
    }

    public function test_provisioning_grants_only_review_permissions_to_canonical_managers():void
    {
        [$staff,$supervisor,$internal,$executive,$outside]=$this->team();
        $supervisor->syncRoles([]);$internal->syncRoles([]);$executive->syncRoles([]);$outside->syncRoles([]);
        $changes=app(PersonnelRoutingReconciliation::class)->provisionReviewers($staff);
        $this->assertCount(6,$changes);
        $this->assertTrue($supervisor->fresh()->hasPermission('personnel.reports.review'));
        $this->assertFalse($supervisor->fresh()->hasPermission('users.update'));
        $this->assertSame(0,$outside->permissions()->count());
    }

    public function test_technical_observer_cannot_read_report_even_with_admin_role_and_no_data_is_deleted():void
    {
        [$staff,,,,$outside]=$this->team();
        $entry=PersonnelEntry::create(['submission_key'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$staff->id,'kind'=>'report','status'=>'submitted','payload'=>[],'version'=>1]);
        $step=$entry->allSteps()->create(['sequence'=>1,'stage'=>'migration_observer','approver_id'=>$outside->id,'approver_name'=>$outside->name,'status'=>'watching']);
        $this->assertFalse(app(PersonnelAccess::class)->view($outside,$entry));
        $this->assertSame(0,$entry->steps()->count());$this->assertSame('watching',$step->fresh()->status);
    }

    public function test_reconciliation_never_auto_completes_a_pending_leave():void
    {
        [$staff,$supervisor,$internal,$executive]=$this->team();
        $entry=PersonnelEntry::create(['submission_key'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>$staff->id,'kind'=>'leave','status'=>'pending','payload'=>[],'version'=>1]);
        foreach([$supervisor,$internal,$executive]as$i=>$u)$entry->steps()->create(['sequence'=>$i+1,'stage'=>$u->organization_level,'approver_id'=>$u->id,'approver_name'=>$u->name,'status'=>'approved','decided_at'=>now()]);
        $before=$entry->allSteps()->get()->toJson();
        try{app(PersonnelRoutingReconciliation::class)->reconcile($entry,$internal);$this->fail('Manual outcome required');}catch(\Illuminate\Validation\ValidationException){}
        $this->assertSame($before,$entry->allSteps()->get()->toJson());$this->assertSame('pending',$entry->fresh()->status);$this->assertSame(1,$entry->fresh()->version);
    }
}
