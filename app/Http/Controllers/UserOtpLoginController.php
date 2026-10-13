<?php

namespace App\Http\Controllers\Auth;

use App\Actions\UserAuth\IssueUserOtpAction;
use App\Contracts\OtpSender;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserLoginCode;
use App\Rules\IranianMobile;
use App\Support\FirstAllowedPageResolver;
use App\Support\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserOtpLoginController extends Controller
{
    public function create(): View { return view('auth.otp'); }

    public function request(Request $request, IssueUserOtpAction $issuer, OtpSender $sender): RedirectResponse
    {
        $phone = PhoneNormalizer::normalize((string) $request->input('phone'));
        $request->merge(['phone' => $phone]);
        $request->validate(['phone' => ['required', new IranianMobile]]);
        $key = 'user-otp-send:'.hash('sha256', $phone.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['phone' => 'لطفاً کمی بعد دوباره تلاش کنید.']);
        }
        RateLimiter::hit($key, 60);

        // The CLI test command uses the same issuer with a terminal-only sender.
        // An unknown/inactive phone still receives the same public response.
        $issuer->execute($phone, $sender);

        $request->session()->put('user_login_phone', $phone);
        return redirect()->route('login.otp')->with('status', 'اگر حساب فعالی با این شماره وجود داشته باشد، کد ورود ارسال می‌شود.');
    }

    public function verify(Request $request, FirstAllowedPageResolver $resolver): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $phone = (string) $request->session()->get('user_login_phone');
        if ($phone === '') { return redirect()->route('login.otp'); }
        $key = 'user-otp-verify:'.hash('sha256', $phone.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => 'تعداد تلاش‌ها بیش از حد مجاز است.']);
        }
        RateLimiter::hit($key, 300);
        $user = DB::transaction(function () use ($phone, $request) {
            $record = UserLoginCode::where('phone', $phone)->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();
            if (! $record || $record->expires_at->isPast() || $record->attempts >= 5) { return null; }
            if (! Hash::check((string) $request->input('code'), $record->code_hash)) {
                $record->increment('attempts');
                return null;
            }
            $user = User::whereKey($record->user_id)->where('is_active', true)->where('can_access_erp', true)->first();
            if (! $user) { return null; }
            $record->update(['consumed_at' => now()]);
            return $user;
        });
        if (! $user) { throw ValidationException::withMessages(['code' => 'کد ورود صحیح نیست یا اعتبار آن پایان یافته است.']); }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('user_login_phone');
        RateLimiter::clear($key);
        return redirect()->to($resolver->destination($user, $request));
    }
}
