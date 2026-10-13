<?php

namespace App\Http\Controllers\Auth;

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
use RuntimeException;

class UserOtpLoginController extends Controller
{
    public function create(): View { return view('auth.otp'); }

    public function request(Request $request, OtpSender $sender): RedirectResponse
    {
        $phone = PhoneNormalizer::normalize((string) $request->input('phone'));
        $request->merge(['phone' => $phone]);
        $request->validate(['phone' => ['required', new IranianMobile]]);
        $key = 'user-otp-send:'.hash('sha256', $phone.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['phone' => 'لطفاً کمی بعد دوباره تلاش کنید.']);
        }
        RateLimiter::hit($key, 60);

        $user = User::query()->where('phone', $phone)->where('is_active', true)->where('can_access_erp', true)->first();
        if ($user) {
            // Do not invalidate any existing code unless delivery succeeds.
            $code = (string) random_int(100000, 999999);
            try {
                $sender->send($phone, $code);
            } catch ( RuntimeException $exception ) {
                // Do not show API errors (or a debug stack trace) in the login UI.
                report($exception);
                return redirect()
                    ->route('login.otp')
                    ->withErrors([ 'phone' => 'ارسال پیامک در حال حاضر امکان‌پذیر نیست. لطفاً کمی بعد دوباره تلاش کنید.' ])
                    ->withInput($request->only('phone'));
            }
            DB::transaction(function () use ($user, $phone, $code) {
                UserLoginCode::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                UserLoginCode::create([
                    'user_id' => $user->id, 'phone' => $phone,
                    'code_hash' => Hash::make($code),
                    'expires_at' => now()->addMinutes((int) config('jahesh.otp.expires_minutes', 5)),
                ]);
            });
        }
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
