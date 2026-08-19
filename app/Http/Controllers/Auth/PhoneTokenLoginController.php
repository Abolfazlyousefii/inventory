<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\CrmAuditLogger;
use App\Services\Crm\TokenService;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
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
        $phone     = $validated['phone'];
        $token     = $validated['token'];

        abort_unless($tokenService->verify($phone, $token), 403, 'توکن ورود نامعتبر یا منقضی شده است.');

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->where('can_access_erp', true)
            ->first();

        if ( !$user ) {
            throw ValidationException::withMessages([
                'phone' => 'حساب کاربری شما غیرفعال است.',
            ]);
        }

        // The browser may already be authenticated in Inventory as a different
        // user (for example, Admin). Replace that session with the CRM user
        // represented by this fresh phone/token handoff.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Do not create a persistent remember-me cookie for CRM handoff logins.
        Auth::login($user, false);
        $request->session()->regenerate();

        if ( !$user->crm_user_id ) {
            $audit->record('local_emergency_login', $user, [
                'erp_user_id' => $user->id,
                'status'      => 'succeeded',
            ]);
        }

        return redirect()->to($resolver->destination($user, $request));
    }
}
