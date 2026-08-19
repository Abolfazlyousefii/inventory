<?php

namespace App\Http\Controllers\Portal;

use App\Actions\CustomerAuth\RequestCustomerOtpAction;
use App\Actions\CustomerAuth\VerifyCustomerOtpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PasswordCustomerLoginRequest;
use App\Http\Requests\Portal\RequestCustomerOtpRequest;
use App\Http\Requests\Portal\VerifyCustomerOtpRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerAuthController extends Controller
{
    public function create(): View
    {
        return view('portal.auth.login');
    }

    public function passwordLogin(PasswordCustomerLoginRequest $request): RedirectResponse
    {
        $phone = $request->validated('phone');
        $password = (string) $request->validated('password');

        $customer = Customer::query()
            ->active()
            ->whereHas('phones', fn ($query) => $query->where('phone', $phone))
            ->first();

        if ($customer === null || blank($customer->password) || ! Hash::check($password, $customer->password)) {
            throw ValidationException::withMessages([
                'password' => 'شماره موبایل یا رمز عبور صحیح نیست.',
            ]);
        }

        if (Hash::needsRehash($customer->password)) {
            $customer->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
            ])->save();
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard');
    }

    public function requestCode(RequestCustomerOtpRequest $request, RequestCustomerOtpAction $action): RedirectResponse
    {
        $phone = $request->validated('phone');
        $action->execute($phone, $request->ip());
        $request->session()->put('customer_login_phone', $phone);

        return redirect()->route('portal.verify')->with('status', 'اگر این شماره در سامانه ثبت شده باشد، کد ورود ارسال می‌شود.');
    }

    public function verification(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('customer_login_phone')) {
            return redirect()->route('portal.login');
        }

        return view('portal.auth.verify', [
            'cooldown' => (int) config('jahesh.otp.cooldown_seconds', 60),
        ]);
    }

    public function verify(VerifyCustomerOtpRequest $request, VerifyCustomerOtpAction $action): RedirectResponse
    {
        $phone = (string) $request->session()->get('customer_login_phone');
        $customer = $action->execute($phone, $request->validated('code'));

        if ($customer === null) {
            throw ValidationException::withMessages(['code' => 'کد ورود صحیح نیست یا اعتبار آن پایان یافته است.']);
        }

        Auth::guard('customer')->login($customer);
        $request->session()->forget('customer_login_phone');
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard');
    }

    public function resend(Request $request, RequestCustomerOtpAction $action): RedirectResponse
    {
        $phone = (string) $request->session()->get('customer_login_phone');
        if ($phone === '') {
            return redirect()->route('portal.login');
        }

        $action->execute($phone, $request->ip());

        return back()->with('status', 'در صورت امکان، کد ورود دوباره ارسال شد.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
