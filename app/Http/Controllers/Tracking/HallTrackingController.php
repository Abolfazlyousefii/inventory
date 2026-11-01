<?php

namespace App\Http\Controllers\Tracking;

use App\Http\Controllers\Controller;
use App\Services\Tracking\HallManagementDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class HallTrackingController extends Controller
{
    public function __construct()
    {
        // The private TV display must not render/store an interactive debug toolbar.
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
    }

    public function unlockForm(): Response
    {
        return response()->view('tracking.hall-unlock')
            ->withoutCookie('hall_management_access', '/hall')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function unlock(Request $request)
    {
        $request->validate(['access_key' => ['required', 'string', 'max:256']]);
        $secret = (string) config('hall_display.access_key', '');
        if (strlen($secret) < 4 || ! hash_equals($secret, (string) $request->input('access_key'))) {
            return back()->withErrors(['access_key' => 'کلید دسترسی مدیریت معتبر نیست.']);
        }

        // Invalidate previous customer-facing display sessions before exposing finances.
        return redirect()->route('hall.show')->cookie(
            'hall_management_access', hash_hmac('sha256', 'hall-management-v1', $secret),
            max(1, (int) config('hall_display.cookie_hours', 12)) * 60,
            '/hall', null, $request->isSecure() || app()->environment('production'),
            true, false, 'strict'
        )->header('Cache-Control', 'private, no-store');
    }

    public function show(): Response
    {
        return response()->view('tracking.hall-management', [
            'dataUrl' => route('hall.data', absolute: false),
            'refreshSeconds' => max(5, (int) config('hall_display.refresh_seconds', 15)),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function data(HallManagementDashboard $dashboard): JsonResponse
    {
        return response()->json($dashboard->snapshot())
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
