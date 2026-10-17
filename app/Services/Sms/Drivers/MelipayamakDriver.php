<?php

namespace App\Services\Sms\Drivers;

use App\Contracts\SmsDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MelipayamakDriver implements SmsDriver {
    public function send( string $phone, string $message ): void {
        $from = (string) config('services.melipayamak.from');
        if ( $from === '' ) {
            throw new RuntimeException('Melipayamak sender number is not configured.');
        }

        if ( $this->hasApiToken() ) {
            $this->requestWithToken('simple', [ 'from' => $from, 'to' => $phone, 'text' => $message ]);
            return;
        }

        $this->request('SendSMS', [ 'to' => $phone, 'from' => $from, 'text' => $message ]);
    }

    public function sendPattern( string $phone, string $pattern, array $parameters = [] ): void {
        if ( $pattern === '' ) {
            throw new RuntimeException('Melipayamak pattern ID is not configured.');
        }

        if ( $this->hasApiToken() ) {
            $this->requestWithToken('shared', [
                'bodyId' => (int) $pattern,
                'to'     => $phone,
                'args'   => array_values($parameters),
            ]);
            return;
        }

        $this->request('BaseServiceNumber', [
            'to'     => $phone,
            'bodyId' => $pattern,
            'text'   => implode(';', $parameters),
        ]);
    }

    private function hasApiToken(): bool {
        return (string) config('services.melipayamak.token', '') !== '';
    }

    private function requestWithToken( string $endpoint, array $data ): void {
        $token     = (string) config('services.melipayamak.token');
        $startedAt = microtime(true);
        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post("https://console.melipayamak.com/api/send/{$endpoint}/" . rawurlencode($token), $data);
        } catch ( ConnectionException $exception ) {
            // The exception can contain the API token in the URL. Never log it.
            Log::warning('Melipayamak API transport failure.', [
                'curl_error' => $exception->getMessage(),
                'elapsed_ms' => (int) round(( microtime(true) - $startedAt ) * 1000),
            ]);
            throw new RuntimeException('Melipayamak API connection failed or timed out.');
        }

        // Avoid throwing an HTTP exception that may reveal the token in the URL.
        if ( !$response->successful() ) {
            throw new RuntimeException('Melipayamak API HTTP error (status: ' . $response->status() . ').');
        }

        $recId = data_get($response->json(), 'recId');
        if ( !is_numeric($recId) || (float) $recId <= 0 ) {
            throw new RuntimeException('Melipayamak API rejected the SMS request (no positive recId returned).');
        }
    }

    private function request( string $endpoint, array $data ): void {
        $username = (string) config('services.melipayamak.username');
        $password = (string) config('services.melipayamak.password');
        if ( $username === '' || $password === '' ) {
            throw new RuntimeException('Melipayamak SMS configuration is incomplete.');
        }

        $response = Http::asForm()
            ->timeout(30)
            ->post("https://rest.payamak-panel.com/api/SendSMS/{$endpoint}", array_merge([
                'username' => $username,
                'password' => $password,
            ], $data));
        $response->throw();

        $payload = $response->json();
        $result  = data_get($payload, 'Value', data_get($payload, 'value'));
        $status  = data_get($payload, 'RetStatus', data_get($payload, 'retStatus'));
        $reason  = data_get($payload, 'StrRetStatus', data_get($payload, 'strRetStatus'));

        if ( (int) $status !== 1 || !is_numeric($result) || (float) $result <= 0 ) {
            // Only provider response fields are reported. Never log the request,
            // which contains the Melipayamak password and possibly an OTP.
            $statusText = is_scalar($status) ? (string) $status : 'n/a';
            $valueText  = is_scalar($result) ? (string) $result : 'n/a';
            $reasonText = is_scalar($reason) ? (string) $reason : 'n/a';
            throw new RuntimeException(sprintf('Melipayamak rejected the SMS request (RetStatus: %s, Value: %s, StrRetStatus: %s).', substr($statusText, 0, 20), substr($valueText, 0, 50), substr(str_replace([
                "\r",
                "\n",
            ], ' ', $reasonText), 0, 100),));
        }
    }
}
