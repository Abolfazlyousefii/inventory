<?php

namespace App\Services\Personnel;

use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

class OrganizationHierarchy
{
    public const LEVELS=['ceo'=>'مدیرعامل','executive_manager'=>'مدیر اجرایی','internal_manager'=>'مدیر داخلی','department_manager'=>'مدیر واحد','supervisor'=>'سرپرست','staff'=>'پرسنل'];

    /** Canonical reporting graph shared by the tree and future leave/report workflows. */
    public function managerChain(User $person): Collection
    {
        $people=User::all()->keyBy('id');$current=$people->get($person->id);$chain=collect();$seen=[$person->id=>true];
        while($current?->manager_id){
            $id=(int)$current->manager_id;
            if(isset($seen[$id]))throw new DomainException('چرخه در ساختار مدیران شناسایی شد.');
            $seen[$id]=true;$current=$people->get($id);
            if(!$current)throw new DomainException('مدیر ثبت‌شده در سامانه موجود نیست.');
            $chain->push($current);
        }
        return $chain;
    }

    public function isInTeam(User $manager,User $person): bool
    {
        if($manager->id===$person->id || !$manager->is_active || !$manager->can_access_erp)return false;
        try{return $this->managerChain($person)->contains('id',$manager->id);}catch(DomainException){return false;}
    }

    /** One unit manager, then internal and executive; supervisors skip their unit stage. */
    public function workflowChain(User $person): Collection
    {
        $route=collect();
        $unitAdded=in_array($person->organization_level,['supervisor','department_manager','internal_manager'],true);
        $internalAdded=$person->organization_level==='internal_manager';
        foreach($this->managerChain($person) as $manager){
            if(!$manager->show_in_organization_chart)continue;
            $level=$manager->organization_level;
            if($level==='ceo')continue;
            if($level==='internal_manager'){
                if($internalAdded)continue;
                $internalAdded=true;
            }elseif($level!=='executive_manager'){
                if($unitAdded)continue;
                $unitAdded=true;
            }
            if(!$manager->is_active||!$manager->can_access_erp)throw new DomainException('مدیر مسئول این شاخه غیرفعال است؛ ساختار پرسنل باید اصلاح شود.');
            $route->push($manager);
            if($level==='executive_manager')break;
        }
        if(!$internalAdded||!$route->contains('organization_level','executive_manager'))throw new DomainException('مسیر این فرد به مدیر داخلی و مدیر اجرایی متصل نیست؛ مدیر مستقیم را در مدیریت پرسنل اصلاح کنید.');
        return $route;
    }

    public function canReview(User $manager,User $person,string $permission): bool
    {
        return $manager->hasPermission($permission) && $this->isInTeam($manager,$person);
    }
}
