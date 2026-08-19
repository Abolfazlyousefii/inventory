<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Crm\CrmAuditLogger;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, CrmAuditLogger $audit, FirstAllowedPageResolver $resolver): RedirectResponse
    {
        $request->merge(['remember' => true]);
        $request->authenticate();

        $request->session()->regenerate();

        if (! $request->user()?->crm_user_id) {
            $audit->record('local_emergency_login', $request->user(), [
                'erp_user_id' => $request->user()?->id,
                'status' => 'succeeded',
            ]);
        }

        return redirect()->to($resolver->destination($request->user(), $request));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
