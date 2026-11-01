<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureHallDisplayAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('hall_display.access_key', '');
        $proof = (string) $request->cookie('hall_management_access', '');

        // Missing configuration is fail-closed: never display other customers' orders publicly.
        if (strlen($secret) < 4 || ! hash_equals(hash_hmac('sha256', 'hall-management-v1', $secret), $proof)) {
            if ($request->expectsJson() || $request->is('hall/data')) {
                return response()->json(['message' => 'Display not authorized'], 403);
            }

            return redirect()->route('hall.unlock');
        }

        return $next($request);
    }
}
