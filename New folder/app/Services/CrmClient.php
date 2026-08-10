<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Http\Client\ConnectionException;

class CrmClient
{
    public function exchangeAuthorizationCode(string $code, ?string $codeVerifier): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => (string) config('crm.sso.client_id'),
            'client_secret' => (string) config('crm.sso.client_secret'),
            'redirect_uri' => (string) config('crm.sso.redirect_uri'),
            'code' => $code,
        ];

        if ($codeVerifier) {
            $payload['code_verifier'] = $codeVerifier;
        }

        try {
            $response = $this->baseRequest()
                ->asForm()
                ->post($this->absoluteUrl((string) config('crm.sso.token_url')), $payload)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('CRM token exchange failed.', previous: $e);
        }

        if (! is_array($response) || ! is_string($response['access_token'] ?? null)) {
            throw new RuntimeException('CRM token response is invalid.');
        }

        return $response;
    }

    public function fetchCurrentUser(string $accessToken): array
    {
        try {
            $response = $this->baseRequest()
                ->withToken($accessToken)
                ->get($this->absoluteUrl((string) config('crm.sso.user_url')))
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('CRM user request failed.', previous: $e);
        }

        if (! is_array($response)) {
            throw new RuntimeException('CRM user response is invalid.');
        }

        return $response;
    }

    public function fetchUserChanges(?string $cursor, int $limit): array
    {
        return $this->integrationGet((string) config('crm.sync.changes_url'), array_filter([
            'cursor' => $cursor,
            'limit' => $limit,
        ], fn ($value) => $value !== null));
    }

    public function fetchUsersPage(int $page, ?string $cursor, int $limit, ?string $crmUserId = null): array
    {
        $endpoint = (string) (config('crm.sync.users_url') ?: config('crm.users_endpoint'));

        return $this->integrationGet($endpoint, array_filter([
            'page' => $page,
            'cursor' => $cursor,
            'limit' => $limit,
            'crm_user_id' => $crmUserId,
        ], fn ($value) => $value !== null));
    }

    public function fetchIntegrationUsers(?string $cursor, int $limit, ?string $updatedSince, bool $includeInactive = true): array
    {
        $endpoint = (string) config('crm.users_endpoint');
        $token = (string) config('crm.sync.integration_token');
        if (! config('crm.sync_enabled')) throw new RuntimeException('crm_sync_disabled');
        if ($endpoint === '' || $token === '') throw new RuntimeException('sync_token_not_configured');

        $query = ['cursor' => $cursor ?? 0, 'limit' => $limit, 'include_inactive' => $includeInactive ? 'true' : 'false'];
        if ($updatedSince !== null) $query['updated_since'] = $updatedSince;
        $delays = app()->environment('testing') ? [0, 0, 0] : [1, 3, 7];

        foreach ($delays as $attempt => $delay) {
            try {
                $response = $this->baseRequest()->withToken($token)->get($this->absoluteUrl($endpoint), $query);
                $status = $response->status();
                $retryable = in_array($status, [429, 500, 502, 503, 504], true);
                if ($retryable && $attempt < count($delays) - 1) {
                    $wait = $status === 429 ? max(0, (int) $response->header('Retry-After')) : $delay;
                    if ($wait > 0) sleep($wait);
                    continue;
                }
                if ($status === 401) throw new RuntimeException('crm_unauthorized');
                if ($status === 403) throw new RuntimeException('crm_forbidden');
                if ($status === 422) throw new RuntimeException('crm_validation_failed');
                if ($retryable) throw new RuntimeException($status === 429 ? 'crm_rate_limited' : 'crm_unavailable');
                if (! $response->successful()) throw new RuntimeException('crm_invalid_response');
                if (! str_contains(mb_strtolower((string) $response->header('Content-Type')), 'application/json')) throw new RuntimeException('crm_invalid_response');
                $payload = $response->json();
                if (! is_array($payload)) throw new RuntimeException('crm_invalid_response');
                return $payload;
            } catch (ConnectionException $e) {
                if ($attempt === count($delays) - 1) throw new RuntimeException('crm_connection_failed', previous: $e);
                if ($delay > 0) sleep($delay);
            }
        }
        throw new RuntimeException('crm_connection_failed');
    }

    public function fetchUsersResponse(): array
    {
        return $this->getJson((string) config('crm.users_endpoint'), 'CRM_BASE_URL تنظیم نشده است.');
    }

    public function fetchCustomersResponse(): array
    {
        return $this->getJson((string) config('crm.customers_endpoint'), 'CRM_BASE_URL تنظیم نشده است.');
    }

    public function createCustomer(array $payload): array
    {
        return $this->postJson((string) config('crm.customers_endpoint'), $payload);
    }

    public function updateCustomer(string $crmCustomerId, array $payload): array
    {
        $endpoint = $this->customerMemberEndpoint($crmCustomerId);

        try {
            $response = $this->request()
                ->put($this->absoluteUrl($endpoint), $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'payload' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با CRM: '.$e->getMessage(), 'payload' => null];
        }

        return ['ok' => true, 'error' => null, 'payload' => $response];
    }

    public function deleteCustomer(string $crmCustomerId): array
    {
        $endpoint = $this->customerMemberEndpoint($crmCustomerId);

        try {
            $response = $this->request()
                ->delete($this->absoluteUrl($endpoint))
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'payload' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با CRM: '.$e->getMessage(), 'payload' => null];
        }

        return ['ok' => true, 'error' => null, 'payload' => $response];
    }

    private function getJson(string $endpoint, string $missingBaseUrlMessage): array
    {
        if (! config('crm.sync_enabled')) {
            return ['ok' => false, 'error' => 'همگام‌سازی CRM غیرفعال است.', 'payload' => null];
        }

        if (rtrim((string) config('crm.base_url'), '/') === '') {
            return ['ok' => false, 'error' => $missingBaseUrlMessage, 'payload' => null];
        }

        try {
            $response = $this->request()
                ->get($this->absoluteUrl($endpoint))
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'payload' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با CRM: '.$e->getMessage(), 'payload' => null];
        }

        return ['ok' => true, 'error' => null, 'payload' => $response];
    }

    private function postJson(string $endpoint, array $payload): array
    {
        if (! config('crm.sync_enabled')) {
            return ['ok' => false, 'error' => 'همگام‌سازی CRM غیرفعال است.', 'payload' => null];
        }

        try {
            $response = $this->request()
                ->post($this->absoluteUrl($endpoint), $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'payload' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'خطا در ارتباط با CRM: '.$e->getMessage(), 'payload' => null];
        }

        return ['ok' => true, 'error' => null, 'payload' => $response];
    }

    private function absoluteUrl(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'https://') || str_starts_with($endpoint, 'http://')) {
            return $endpoint;
        }

        return rtrim((string) config('crm.base_url'), '/').'/'.ltrim($endpoint, '/');
    }

    private function customerMemberEndpoint(string $crmCustomerId): string
    {
        $template = (string) config('crm.customer_endpoint_template', '');

        if ($template !== '') {
            return str_replace(['{id}', ':id'], rawurlencode($crmCustomerId), $template);
        }

        return rtrim((string) config('crm.customers_endpoint'), '/').'/'.rawurlencode($crmCustomerId);
    }

    private function request(): PendingRequest
    {
        $token = (string) config('crm.api_token');

        return $this->baseRequest()
            ->when($token !== '', fn (PendingRequest $request) => $request->withToken($token))
            ->withHeaders($token !== '' ? ['X-CRM-Token' => $token] : []);
    }

    private function integrationGet(string $endpoint, array $query): array
    {
        $token = (string) config('crm.sync.integration_token');
        if ($endpoint === '' || $token === '') {
            throw new RuntimeException('CRM integration configuration is incomplete.');
        }

        try {
            $response = $this->baseRequest()
                ->withToken($token)
                ->get($this->absoluteUrl($endpoint), $query)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            throw new RuntimeException('CRM integration request failed.', previous: $e);
        }

        if (! is_array($response)) {
            throw new RuntimeException('CRM integration response is invalid.');
        }

        return $response;
    }

    private function baseRequest(): PendingRequest
    {
        return Http::connectTimeout((int) config('crm.connect_timeout', 10))
            ->timeout((int) config('crm.timeout', 30))
            ->withOptions([
                'verify' => (bool) config('crm.verify_ssl', true),
            ])
            ->acceptJson();
    }
}
