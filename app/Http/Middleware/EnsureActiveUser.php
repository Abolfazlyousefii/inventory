<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ($request->user()->is_active === false || $request->user()->can_access_erp === false)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'phone' => 'حساب کاربری شما غیرفعال شده است.',
            ]);
        }

        return $next($request);
    }
}
