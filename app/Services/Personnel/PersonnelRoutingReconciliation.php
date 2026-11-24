<?php

namespace App\Services\Personnel;

use App\Models\PersonnelEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonnelRoutingReconciliation
{
    public const VERSION='organization-2026-11-24';

    public function __construct(private PersonnelWorkflow $workflow,private OrganizationHierarchy $hierarchy) {}

    /** Explicit rollout of the requested HR responsibility; never grant unrelated permissions. */
    public function provisionReviewers(User $actor): array
    {
        $changes=[];
        foreach(User::where('is_active',true)->where('can_access_erp',true)->where('show_in_organization_chart',true)->get()as$author){
            try{$chain=$this->hierarchy->workflowChain($author);}catch(\DomainException){continue;}
            foreach($chain as$manager){
                foreach(['personnel.leaves.review','personnel.reports.review']as$key){
                    if($manager->hasPermission($key))continue;
                    $permission=\App\Models\AccessPermission::where('key',$key)->firstOrFail();
                    $attached=$manager->permissions()->syncWithoutDetaching([$permission->id]);
                    if(empty($attached['attached']))continue;
                    $changes[]=['user_id'=>$manager->id,'permission'=>$key];
                    \App\Support\ActivityLogger::logForActor($actor->id,'personnel.routing_review_permission',$manager,'دسترسی بررسی مسئول همین شاخه طبق ساختار تأییدشده',['permission'=>$key,'scope'=>'assigned_personnel_steps']);
                }
            }
        }
        return $changes;
    }

    /** Explicit migration only: never rewrite old decisions during an ordinary page request. */
    public function reconcile(PersonnelEntry $entry,User $actor): bool
    {
        return DB::transaction(function()use($entry,$actor){
            $entry=PersonnelEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $author=$entry->user;
            if(!$author||!PersonnelAccess::active($author)||!$author->show_in_organization_chart)return false;
            if(($entry->payload['organization_routing_version']??null)===self::VERSION)return false;
            if($entry->status==='draft'||($entry->kind==='leave'&&$entry->status!=='pending'))return false;
            // Fail before replacing anything when the personnel chart is incomplete.
            $this->hierarchy->workflowChain($author);
            $previous=$entry->allSteps()->whereNull('superseded_at')->get();
            $entry->allSteps()->whereNull('superseded_at')->update(['superseded_at'=>now()]);
            $this->workflow->snapshot($entry,$author);
            if($entry->kind==='leave'){
                $waiting=false;
                foreach($entry->steps()->get()as$step){
                    $approved=$previous->first(fn($old)=>(int)$old->approver_id===(int)$step->approver_id&&$old->status==='approved');
                    if($approved){$step->update(['status'=>'approved','decided_at'=>$approved->decided_at]);continue;}
                    $step->update(['status'=>$waiting?'waiting':'pending']);$waiting=true;
                }
                if(!$waiting)throw ValidationException::withMessages(['organization'=>'همه مسئولان مسیر تازه قبلاً تأیید کرده‌اند؛ تعیین تکلیف نهایی نیاز به بررسی دستی دارد.']);
            }
            $payload=$entry->payload??[];
            if($entry->kind==='leave'){
                $payload['original_crm_approval_route']??=$payload['crm_approval_route']??null;
                $payload['crm_approval_route']='organization';
            }
            $payload['organization_routing_version']=self::VERSION;
            $entry->payload=$payload;$entry->version++;$entry->save();
            $this->workflow->event($entry,$actor,'routing_updated',null,['previous_step_ids'=>$previous->pluck('id')->all(),'policy'=>self::VERSION]);
            return true;
        },3);
    }
}
