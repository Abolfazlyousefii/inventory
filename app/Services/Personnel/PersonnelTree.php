<?php

namespace App\Services\Personnel;

use Illuminate\Support\Collection;

class PersonnelTree
{
    /** Keep matching people and their ancestry; never rewrite imperfect imported hierarchies. */
    public function rows(Collection $users, Collection $matches, bool $includeInactive = true): array
    {
        $byId=$users->keyBy('id');$included=[];$matchIds=$matches->flip();
        foreach ($matches as $id) {
            $seen=[];
            while ($id && isset($byId[$id]) && !isset($seen[$id])) {
                $seen[$id]=true;$included[$id]=true;$id=$byId[$id]->manager_id;
            }
        }
        $children=[];$roots=[];
        foreach ($users as $user) {
            if (!isset($included[$user->id])) continue;
            if ($user->manager_id && isset($included[$user->manager_id])) $children[$user->manager_id][]=$user;
            else $roots[]=$user;
        }
        $rows=[];$visited=[];
        $walk=function ($user, int $depth, ?int $parent, bool $issue=false) use (&$walk,&$rows,&$visited,$children,$matchIds): void {
            if (isset($visited[$user->id])) return;
            $visited[$user->id]=true;
            $rows[]=['user'=>$user,'depth'=>$depth,'parent'=>$parent,'has_children'=>!empty($children[$user->id]),'context'=>!isset($matchIds[$user->id]),'issue'=>$issue];
            foreach ($children[$user->id] ?? [] as $child) $walk($child,$depth+1,$user->id,$issue);
        };
        foreach ($roots as $root) $walk($root,0,null);
        // A legacy cycle has no root. Surface all its members for correction instead of losing them.
        foreach ($users as $user) if (isset($included[$user->id]) && !isset($visited[$user->id])) $walk($user,0,null,true);
        if (!$includeInactive) {
            // Presentation only: bridge hidden ancestors without changing stored reporting lines.
            $original=collect($rows)->keyBy(fn ($row) => $row['user']->id);
            $visible=[];
            foreach ($rows as $row) {
                if (!$row['user']->is_active || !$row['user']->can_access_erp) continue;
                $parent=$row['parent'];$seen=[];
                while ($parent && !isset($visible[$parent]) && isset($original[$parent]) && !isset($seen[$parent])) {
                    $seen[$parent]=true;$parent=$original[$parent]['parent'];
                }
                $row['parent']=isset($visible[$parent]) ? $parent : null;
                $row['depth']=$row['parent'] ? $visible[$row['parent']]['depth']+1 : 0;
                $row['has_children']=false;
                if ($row['parent']) $visible[$row['parent']]['has_children']=true;
                $visible[$row['user']->id]=$row;
            }
            return array_values($visible);
        }
        return $rows;
    }
}
