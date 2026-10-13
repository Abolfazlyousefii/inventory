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
        $proof = (string) $request->cookie('hall_display_access', '');

        // Missing configuration is fail-closed: never display other customers' orders publicly.
        if ($secret === '' || ! hash_equals(hash('sha256', $secret), $proof)) {
            if ($request->expectsJson() || $request->is('hall/data')) {
                return response()->json(['message' => 'Display not authorized'], 403);
            }

            return redirect()->route('hall.unlock');
        }

        return $next($request);
    }
}
