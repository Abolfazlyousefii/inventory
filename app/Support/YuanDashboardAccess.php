<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** The Hall-style, time-limited second gate for already-authenticated users. */
final class YuanDashboardAccess
{
    public const COOKIE_NAME = 'yuan_dashboard_access';

    private const COOKIE_PATH = '/yuan';

    private const SIGNING_CONTEXT = 'yuan-dashboard-v1';

    public function validPassword(string $attempt): bool
    {
        $secret = (string) config('yuan_dashboard.access_key', '');

        // Unconfigured/too-short keys must not unlock production by accident.
        return strlen($secret) >= 8 && hash_equals($secret, $attempt);
    }

    public function isUnlocked(Request $request): bool
    {
        $user = $request->user();
        $secret = (string) config('yuan_dashboard.access_key', '');
        $value = $request->cookie(self::COOKIE_NAME);

        if (! $user || strlen($secret) < 8 || ! is_string($value)) {
            return false;
        }

        $parts = explode('.', $value, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || strlen($parts[0]) > 12
            || ! preg_match('/\A[a-f0-9]{64}\z/', $parts[1])) {
            return false;
        }

        $expiresAt = (int) $parts[0];
        if ($expiresAt <= now()->timestamp) {
            return false;
        }

        return hash_equals($this->sign((string) $user->getAuthIdentifier(), $parts[0], $secret), $parts[1]);
    }

    public function issueCookie(Request $request): Cookie
    {
        $user = $request->user();
        if (! $user || ! $this->hasKey()) {
            throw new \LogicException('Yuan dashboard access key is not configured.');
        }

        $minutes = max(1, (int) config('yuan_dashboard.cookie_hours', 12)) * 60;
        $expiresAt = (string) now()->addMinutes($minutes)->timestamp;
        $secret = (string) config('yuan_dashboard.access_key');

        return cookie(
            self::COOKIE_NAME,
            $expiresAt.'.'.$this->sign((string) $user->getAuthIdentifier(), $expiresAt, $secret),
            $minutes,
            self::COOKIE_PATH,
            null,
            $request->isSecure() || app()->environment('production'),
            true,
            false,
            'strict'
        );
    }

    /** This project globally exempts web requests from Laravel CSRF checking. */
    public function hasValidCsrf(Request $request): bool
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        return is_string($token) && $token !== ''
            && hash_equals((string) $request->session()->token(), $token);
    }

    public function hasKey(): bool
    {
        return strlen((string) config('yuan_dashboard.access_key', '')) >= 8;
    }

    private function sign(string $userId, string $expiresAt, string $secret): string
    {
        return hash_hmac('sha256', self::SIGNING_CONTEXT.'|'.$userId.'|'.$expiresAt, $secret);
    }
}
