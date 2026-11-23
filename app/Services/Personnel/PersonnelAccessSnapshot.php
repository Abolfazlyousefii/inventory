<?php

namespace App\Services\Personnel;

use App\Models\User;

class PersonnelAccessSnapshot
{
    public static function version(User $user): string
    {
        $roles=$user->roles()->orderBy('roles.id')->pluck('roles.id')->all();
        return hash('sha256',json_encode([
            $roles,
            $user->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all(),
            \Illuminate\Support\Facades\DB::table('role_has_permissions')->whereIn('role_id',$roles)->orderBy('role_id')->orderBy('permission_id')->get(['role_id','permission_id'])->all(),
        ]));
    }
}
