<?php
namespace App\Services\Personnel;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonnelAccounts
{
    public static function version(User $user): string
    {
        return hash('sha256', json_encode([$user->id, (bool)$user->is_active, (bool)$user->can_access_erp]));
    }

    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            if (User::where('phone', $data['phone'])->exists()) {
                throw ValidationException::withMessages(['phone'=>'این شماره همراه قبلاً ثبت شده است.']);
            }
            if (!empty($data['manager_id']) && !User::activeErpUsers()->where('show_in_organization_chart',true)->whereKey($data['manager_id'])->exists()) {
                throw ValidationException::withMessages(['manager_id'=>'مدیر مستقیم باید عضو فعال چارت سازمانی باشد.']);
            }
            $user = User::create(collect($data)->only(['name','phone','username','email','password','manager_id','organization_level','department','position','personnel_code','show_in_organization_chart'])->all()+[
                'is_active'=>(bool)$data['is_active'], 'can_access_erp'=>(bool)$data['is_active'],
                'is_crm_managed'=>false, 'sync_source'=>'local', 'organization_managed_locally'=>true,
            ]);
            ActivityLogger::logForActor($actor->id,'personnel.account_created',$user,'ساخت حساب داخلی در مدیریت پرسنل',['manager_id'=>$user->manager_id,'is_active'=>$user->is_active]);
            return $user;
        },3);
    }

    public function status(User $target, User $actor, bool $active, string $version): void
    {
        DB::transaction(function () use ($target, $actor, $active, $version) {
            $users=User::with('roles')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $user=$users->get($target->id);
            abort_unless($user,404);
            if (!hash_equals(self::version($user),$version)) throw ValidationException::withMessages(['account_version'=>'وضعیت حساب تغییر کرده است؛ پنجره را دوباره باز کنید.']);
            if (!$active && $actor->id===$user->id) throw ValidationException::withMessages(['is_active'=>'غیرفعال‌کردن حساب خودتان مجاز نیست.']);
            if (!$active && $user->isAdministrator() && !$users->contains(fn($other)=>$other->id!==$user->id && $other->is_active && $other->can_access_erp && $other->isAdministrator())) {
                throw ValidationException::withMessages(['is_active'=>'آخرین مدیر فعال سامانه را نمی‌توان غیرفعال کرد.']);
            }
            $before=$user->only(['is_active','can_access_erp']);
            $user->fill(['is_active'=>$active,'can_access_erp'=>$active]);
            if (!$user->isDirty()) return;
            if (!$active) $user->setRememberToken(\Illuminate\Support\Str::random(60));
            $user->save();
            ActivityLogger::logForActor($actor->id,'personnel.account_status_updated',$user,'تغییر وضعیت ورود حساب پرسنل',['before'=>$before,'after'=>$user->only(['is_active','can_access_erp'])]);
        },3);
    }
}
