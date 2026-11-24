<?php

namespace App\Http\Controllers;

use App\Support\YuanDashboardAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class YuanDashboardUnlockController extends Controller
{
    public function __construct(private readonly YuanDashboardAccess $access) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->access->isUnlocked($request)) {
            return redirect()->route('products.yuan.index');
        }

        return response()->view('products.yuan.unlock', ['configured' => $this->access->hasKey()])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function unlock(Request $request): RedirectResponse
    {
        // The uploaded project's global CSRF exceptions contain '*'. Enforce a
        // token specifically for this password-posting form until that is fixed.
        abort_unless($this->access->hasValidCsrf($request), 419);

        $input = $request->validate(['access_key' => ['required', 'string', 'max:256']]);
        if (! $this->access->validPassword($input['access_key'])) {
            return back()->withErrors(['access_key' => 'رمز ورود صحیح نیست.']);
        }

        return redirect()->route('products.yuan.index')
            ->cookie($this->access->issueCookie($request))
            ->header('Cache-Control', 'private, no-store');
    }
}
