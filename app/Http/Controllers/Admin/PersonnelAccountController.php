<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Personnel\{PersonnelAccounts, OrganizationHierarchy};
use App\Support\{PageAccessCatalog, PhoneNormalizer};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PersonnelAccountController extends Controller
{
    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()?->is_active && $request->user()?->can_access_erp && PageAccessCatalog::userCan($request->user(),'page.roles'),403);
    }
    public function store(Request $request, PersonnelAccounts $accounts)
    {
        $this->authorizeManager($request);
        $request->validate(['phone'=>'required|string|max:30']);
        $request->merge(['phone'=>PhoneNormalizer::normalize($request->input('phone'))]);
        $data=$request->validate([
            'name'=>'required|string|max:255', 'phone'=>['required','regex:/^09[0-9]{9}$/','unique:users,phone'],
            'username'=>'nullable|string|max:100|unique:users,username', 'email'=>'nullable|email|max:255|unique:users,email',
            'password'=>'required|string|min:8|max:128|confirmed',
            'is_active'=>'required|boolean','show_in_organization_chart'=>'required|boolean',
            'manager_id'=>'nullable|integer|exists:users,id',
            'organization_level'=>['nullable',Rule::in(array_keys(OrganizationHierarchy::LEVELS))],
            'department'=>'nullable|string|max:100','position'=>'nullable|string|max:100','personnel_code'=>'nullable|string|max:100|unique:users,personnel_code',
        ]);
        // No roles or direct permissions are accepted here; use the existing guarded editor.
        $user=$accounts->create($data,$request->user());
        return response()->json(['ok'=>true,'id'=>$user->id,'message'=>'حساب ساخته شد؛ نقش و دسترسی کاری را تعیین کنید.','editor_url'=>route('admin.permissions.index',['panel'=>1,'user_id'=>$user->id])],201);
    }
    public function status(Request $request, User $user, PersonnelAccounts $accounts)
    {
        $this->authorizeManager($request);
        $data=$request->validate(['is_active'=>'required|boolean','account_version'=>'required|string|size:64']);
        $accounts->status($user,$request->user(),(bool)$data['is_active'],$data['account_version']);
        return response()->json(['ok'=>true,'message'=>'وضعیت حساب ذخیره شد.']);
    }
}
