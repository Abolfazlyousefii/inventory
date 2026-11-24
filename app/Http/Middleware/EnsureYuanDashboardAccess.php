<?php

namespace App\Http\Middleware;

use App\Support\YuanDashboardAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureYuanDashboardAccess
{
    public function __construct(private readonly YuanDashboardAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->isUnlocked($request)) {
            if ($request->expectsJson() || ! $request->isMethod('GET')) {
                return response()->json([
                    'message' => 'برای ادامه، ابتدا قفل پنل یوان را باز کنید.',
                    'code' => 'yuan_locked',
                    'unlock_url' => route('products.yuan.unlock'),
                ], 403)->header('Cache-Control', 'private, no-store');
            }

            return redirect()->route('products.yuan.unlock')
                ->header('Cache-Control', 'private, no-store');
        }

        if ($request->isMethod('POST')) {
            abort_unless($this->access->hasValidCsrf($request), 419);
        }

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
