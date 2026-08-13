<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmAuditLogger;
use App\Services\CrmClient;
use App\Services\CrmUserService;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CrmSsoController extends Controller
{
    private const STATE_KEY = 'crm_oauth_state';

    private const VERIFIER_KEY = 'crm_oauth_code_verifier';

    public function __construct(
        private readonly CrmClient $client,
        private readonly CrmUserService $users,
        private readonly CrmAuditLogger $audit,
        private readonly FirstAllowedPageResolver $resolver,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        if (! config('crm.sso.enabled')) {
            return redirect()->route('login')->withErrors(['crm' => 'ورود از طریق CRM هنوز فعال نشده است.']);
        }

        if (! $this->configurationIsValid()) {
            return redirect()->route('login')->withErrors(['crm' => 'تنظیمات ورود CRM کامل نیست.']);
        }

        $state = Str::random(64);
        $request->session()->put(self::STATE_KEY, $state);

        $query = [
            'client_id' => config('crm.sso.client_id'),
            'redirect_uri' => config('crm.sso.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('crm.sso.scope'),
            'state' => $state,
        ];

        if (config('crm.sso.pkce_enabled', true)) {
            $verifier = Str::random(96);
            $request->session()->put(self::VERIFIER_KEY, $verifier);
            $query['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $query['code_challenge_method'] = 'S256';
        }

        return redirect()->away(rtrim((string) config('crm.sso.authorize_url'), '?').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! config('crm.sso.enabled')) {
            return redirect()->route('login')->withErrors(['crm' => 'ورود از طریق CRM غیرفعال است.']);
        }

        $requestId = (string) Str::uuid();
        $expectedState = (string) $request->session()->pull(self::STATE_KEY, '');
        $receivedState = (string) $request->query('state', '');
        $verifier = $request->session()->pull(self::VERIFIER_KEY);

        if ($expectedState === '' || $receivedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return $this->failed($requestId, 'invalid_state', 'نشست ورود منقضی شده یا State نامعتبر است.');
        }

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return $this->failed($requestId, 'missing_code', 'کد ورود از CRM دریافت نشد.');
        }

        try {
            $tokens = $this->client->exchangeAuthorizationCode($code, is_string($verifier) ? $verifier : null);
            $payload = $this->client->fetchCurrentUser($tokens['access_token']);
            $result = $this->users->syncOnePayload($payload);
            $user = $result['user'];

            if (! $user->is_active || ! $user->can_access_erp) {
                return $this->failed(
                    $requestId,
                    'erp_access_denied',
                    'حساب کاربری شما اجازه دسترسی به ERP را ندارد.',
                    $user->crm_user_id,
                    $user->id
                );
            }

            if ($user->roles->isEmpty()) {
                return $this->failed($requestId, 'role_not_mapped', 'برای ورود به ERP نقش معتبری تعریف نشده است.', $user->crm_user_id, $user->id);
            }

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $this->audit->record('crm_sso_login_succeeded', $user, [
                'request_id' => $requestId,
                'crm_user_id' => $user->crm_user_id,
                'erp_user_id' => $user->id,
                'status' => 'succeeded',
            ]);

            return redirect()->to($this->resolver->destination($user, $request));
        } catch (\Throwable $e) {
            Log::warning('CRM SSO callback failed', [
                'request_id' => $requestId,
                'route' => 'auth.crm.callback',
                'error_category' => $e::class,
            ]);

            return $this->failed($requestId, 'callback_failed', 'ورود از CRM کامل نشد. لطفاً دوباره تلاش کنید.');
        }
    }

    private function failed(string $requestId, string $category, string $message, ?string $crmUserId = null, ?int $erpUserId = null): RedirectResponse
    {
        $this->audit->record('crm_sso_login_failed', properties: [
            'request_id' => $requestId,
            'crm_user_id' => $crmUserId,
            'erp_user_id' => $erpUserId,
            'status' => 'failed',
            'error_category' => $category,
        ]);

        return redirect()->route('login')->withErrors(['crm' => $message]);
    }

    private function configurationIsValid(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect_uri', 'authorize_url', 'token_url', 'user_url'] as $key) {
            if (trim((string) config('crm.sso.'.$key)) === '') {
                return false;
            }
        }

        $redirect = parse_url((string) config('crm.sso.redirect_uri'));

        return ($redirect['scheme'] ?? null) === 'https'
            && ($redirect['host'] ?? null) === 'inv.ariyajanebi.ir';
    }
}
