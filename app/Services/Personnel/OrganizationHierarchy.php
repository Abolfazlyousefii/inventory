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

    public function canReview(User $manager,User $person,string $permission): bool
    {
        return $manager->hasPermission($permission) && $this->isInTeam($manager,$person);
    }
}
