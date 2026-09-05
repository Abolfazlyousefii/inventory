<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CRM\User as CrmUser;
use App\Services\Crm\CrmAuditLogger;
use App\Services\Crm\TokenService;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhoneTokenLoginController extends Controller {
    public function __invoke( Request $request, TokenService $tokenService, CrmAuditLogger $audit, FirstAllowedPageResolver $resolver ) {
        $validator = Validator::make($request->all(), [
            'phone' => [ 'required', 'string', 'max:20' ],
            'token' => [ 'required', 'string', 'size:32' ],
        ]);

        if ( $validator->fails() ) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        $phone = $validated['phone'];
        $token = $validated['token'];

        /*
        |--------------------------------------------------------------------------
        | Verify CRM Token
        |--------------------------------------------------------------------------
        */

        abort_unless($tokenService->verify($phone, $token), 403, 'توکن ورود نامعتبر یا منقضی شده است.');

        /*
        |--------------------------------------------------------------------------
        | Find Inventory User
        |--------------------------------------------------------------------------
        */

        $user = User::where('phone', $phone)
            ->first();

        if ( $user ) {
            if ( !$user->is_active || !$user->can_access_erp ) {
                throw ValidationException::withMessages([
                    'phone' => 'حساب کاربری شما غیرفعال است.',
                ]);
            }
        }
        else {
            /*
            |--------------------------------------------------------------------------
            | User does not exist in Inventory
            | Check CRM
            |--------------------------------------------------------------------------
            */

            $crmUser = CrmUser::where('phone', $phone)
                ->first();

            if ( !$crmUser ) {
                throw ValidationException::withMessages([
                    'phone' => 'شما هنوز در سیستم CRM ثبت نشده‌اید.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Create Inventory User From CRM
            |--------------------------------------------------------------------------
            */

            $user = User::create([
                'crm_user_id' => $crmUser->id,
                'external_crm_id' => $crmUser->id,
                'name' => $crmUser->name,
                'email' => $crmUser->email,
                'phone' => $crmUser->phone,
                'password' => Hash::make(Str::random()),
                'is_active' => true,
                'can_access_erp' => true,
                'is_crm_managed' => true,
                'sync_source' => 'crm',
                'synced_at' => now(),
            ]);

            $audit->record('crm_user_created', $user, [
                'crm_user_id' => $crmUser->id,
                'status'      => 'created',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Replace Current Session
        |--------------------------------------------------------------------------
        */

        Auth::logout();

        $request->session()
            ->invalidate();

        $request->session()
            ->regenerateToken();

        Auth::login($user, false);

        $request->session()
            ->regenerate();

        if ( !$user->crm_user_id ) {
            $audit->record('local_emergency_login', $user, [
                'erp_user_id' => $user->id,
                'status'      => 'succeeded',
            ]);
        }

        return redirect()->to($resolver->destination($user, $request));
    }
}