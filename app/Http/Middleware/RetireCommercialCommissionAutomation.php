<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retires the independent commercial commission automation workflow without
 * removing its routes, data, or reusable services.  Keeping the decision at
 * the route boundary guarantees mutation endpoints cannot reach controllers.
 */
class RetireCommercialCommissionAutomation
{
    private const MESSAGE = 'فرآیند مستقل پورسانت بازنشسته شده است. برای مدیریت اسناد پورسانت از گزارش‌های مالی استفاده کنید.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()
                ->route('finance.seller-sales.index')
                ->with('info', self::MESSAGE);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 410);
        }

        abort(410, self::MESSAGE);
    }
}
