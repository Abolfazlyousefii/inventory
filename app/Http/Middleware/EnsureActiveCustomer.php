<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveCustomer
{
    public function handle(Request $request, Closure $next)
    {
        $customer = auth('customer')->user();
        if ($customer === null || ! $customer->is_active) {
            auth('customer')->logout();
            return redirect()->route('portal.login');
        }
        return $next($request);
    }
}
