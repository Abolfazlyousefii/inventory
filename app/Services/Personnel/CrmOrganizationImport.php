<?php

namespace App\Services\Personnel;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrmOrganizationImport
{
    public const FIELDS=['manager_id','manager_crm_user_id','department','position','organization_level','organization_managed_locally'];

    public function plan(array $payload): array
    {
        if(($payload['schema_version']??null)!==1 || ($payload['source']??null)!=='ariya-crm' || ($payload['resolver']??null)!=='OrganizationHierarchyService.directManager' || !is_array($payload['users']??null))throw ValidationException::withMessages(['source'=>'قالب استخراج ساختار CRM معتبر نیست.']);
        $users=User::orderBy('id')->get()->keyBy('id');$mapping=[];$errors=[];$changes=[];$normalized=[];$seen=[];
        foreach($payload['errors']??[] as $error)if(($error['error']??'')!=='چرخه در ساختار مدیران سازمان شناسایی شد.')$errors[]='unresolved_source_error:'.($error['crm_id']??'unknown');
        foreach($users as $user){
            $id=filled($user->crm_user_id)?(string)$user->crm_user_id:(filled($user->external_crm_id)?(string)$user->external_crm_id:null);
            if($id===null)continue;
            if(isset($mapping[$id]))$errors[]='duplicate_local_crm_id:'.$id;
            $mapping[$id]=$user->id;
        }
        foreach($payload['users'] as $row){
            $id=(string)($row['crm_id']??'');
            if($id==='' || isset($seen[$id])){$errors[]='invalid_or_duplicate_source_id:'.$id;continue;}$seen[$id]=true;
            if(!isset($mapping[$id])){$errors[]='missing_local_user:'.$id;continue;}
            $user=$users[$mapping[$id]];$manager=filled($row['manager_crm_id']??null)?(string)$row['manager_crm_id']:null;
            if($manager===$id){$normalized[]=$id;$manager=null;}
            if($manager!==null && !isset($mapping[$manager])){$errors[]='missing_local_manager:'.$manager;continue;}
            $level=$row['position_key']??null;
            if($level!==null && !array_key_exists($level,OrganizationHierarchy::LEVELS)){$errors[]='unknown_position:'.$id;continue;}
            $before=$this->snapshot($user);
            $after=['manager_id'=>$manager!==null?$mapping[$manager]:null,'manager_crm_user_id'=>$manager,'department'=>filled($row['department']??null)?trim($row['department']):$user->department,'position'=>$level!==null?OrganizationHierarchy::LEVELS[$level]:$user->position,'organization_level'=>$level??$user->organization_level,'organization_managed_locally'=>true];
            if($before===$after)continue;
            if($user->organization_managed_locally){$errors[]='local_organization_conflict:'.$id;continue;}
            $changes[$user->id]=['crm_id'=>$id,'before'=>$before,'after'=>$after];
        }
        $parents=$users->map(fn($u)=>$u->manager_id)->all();
        foreach($changes as $id=>$change)$parents[$id]=$change['after']['manager_id'];
        foreach($parents as $id=>$parent){
            $path=[$id=>true];
            while($parent!==null){
                if(isset($path[$parent])){$errors[]='cycle_at_local_user:'.$parent;break;}
                if(!array_key_exists($parent,$parents)){$errors[]='missing_parent_at_local_user:'.$parent;break;}
                $path[$parent]=true;$parent=$parents[$parent];
            }
        }
        $errors=array_values(array_unique($errors));
        $hash=hash('sha256',json_encode([$payload,$users->map(fn($u)=>$this->snapshot($u))->all(),$changes,$errors],JSON_UNESCAPED_UNICODE));
        return ['hash'=>$hash,'source_count'=>count($seen),'matched_count'=>count(array_intersect_key($mapping,$seen)),'changes'=>$changes,'errors'=>$errors,'normalized_roots'=>$normalized];
    }

    public function apply(array $payload,string $expectedHash): int
    {
        return DB::transaction(function()use($payload,$expectedHash){
            User::orderBy('id')->lockForUpdate()->get();$plan=$this->plan($payload);
            if(!hash_equals($plan['hash'],$expectedHash) || $plan['errors']!==[])throw ValidationException::withMessages(['plan'=>'ساختار تغییر کرده یا تطبیق دارای تعارض است؛ بررسی اولیه را دوباره اجرا کنید.']);
            foreach($plan['changes'] as $id=>$change){
                $user=User::findOrFail($id);$user->forceFill($change['after'])->save();
                ActivityLogger::logForActor(null,'personnel.crm_organization_imported',$user,'انتقال ساختار سازمانی CRM به INV، بدون تغییر نقش یا حساب',['crm_id'=>$change['crm_id'],'before'=>$change['before'],'after'=>$change['after'],'plan_hash'=>$expectedHash]);
            }
            return count($plan['changes']);
        },3);
    }

    /** Create identity placeholders only, never activate login or assign authorization. */
    public function prepareMissingProfiles(array $payload,string $expectedHash): int
    {
        return DB::transaction(function()use($payload,$expectedHash){
            User::orderBy('id')->lockForUpdate()->get();$plan=$this->plan($payload);
            if(!hash_equals($plan['hash'],$expectedHash) || collect($plan['errors'])->contains(fn($e)=>!str_starts_with($e,'missing_local_user:')))throw ValidationException::withMessages(['plan'=>'تطبیق جدید یا خطای دیگری وجود دارد؛ ایجاد پرونده متوقف شد.']);
            $count=0;
            foreach($plan['errors'] as $error){
                $id=substr($error,strlen('missing_local_user:'));
                $row=collect($payload['users'])->first(fn($r)=>(string)$r['crm_id']===$id);
                if(!$row || !ctype_digit($id) || strlen((string)($row['name']??''))>255 || !filled($row['name']??null))throw ValidationException::withMessages(['source'=>'شناسه یا نام پرونده معتبر نیست.']);
                $email='crm-personnel-'.$id.'@local.invalid';
                if(User::where('email',$email)->exists())throw ValidationException::withMessages(['source'=>'آدرس داخلی پرونده تکراری است؛ تطبیق دستی لازم است.']);
                $user=User::create(['crm_user_id'=>$id,'external_crm_id'=>(int)$id,'name'=>$row['name'],'email'=>$email,'password'=>\Illuminate\Support\Str::random(80),'is_active'=>false,'can_access_erp'=>false,'is_seller'=>false,'is_crm_managed'=>false,'sync_source'=>'crm-organization-import']);
                ActivityLogger::logForActor(null,'personnel.crm_profile_prepared',$user,'ایجاد پرونده پرسنلی بدون نقش و بدون مجوز ورود',['crm_id'=>$id]);$count++;
            }
            return $count;
        },3);
    }

    private function snapshot(User $user): array
    {
        return ['manager_id'=>$user->manager_id===null?null:(int)$user->manager_id,'manager_crm_user_id'=>$user->manager_crm_user_id===null?null:(string)$user->manager_crm_user_id,'department'=>$user->department,'position'=>$user->position,'organization_level'=>$user->organization_level,'organization_managed_locally'=>(bool)$user->organization_managed_locally];
    }
}
