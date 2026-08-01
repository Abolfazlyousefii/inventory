<?php

namespace App\Services\Sync;

use App\Models\Customer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SiteCustomersSyncService {
    public function __construct() {
        $baseUrl = rtrim((string) Config::get('services.site.base_url'), '/');

        if ( $baseUrl === '' ) {
            throw new RuntimeException('آدرس services.site.base_url تنظیم نشده است.');
        }

        $this->apiUrl = $baseUrl . $this->url;
    }

    protected string $url = '/api/v2/sync/customers';
    protected string $apiUrl;

    /** @return array{received: int, created: int, updated: int, invalid: int, errors: array<int, array<string, mixed>>} */
    public function syncAll(): array {
        $summary = [
            'received' => 0,
            'created'  => 0,
            'updated'  => 0,
            'invalid'  => 0,
            'errors'   => [],
        ];

        try {
            $customers = $this->fetchCustomers();

            foreach ( $customers as $payload ) {
                $summary['received'] ++;

                if ( !is_array($payload) ) {
                    $summary['invalid'] ++;
                    continue;
                }

                try {
                    $result = $this->storeCustomer($payload);
                    $summary[$result] ++;
                } catch ( Throwable $exception ) {
                    $summary['invalid'] ++;
                    $summary['errors'][] = [
                        'customer_id' => $payload['id'] ?? null,
                        'message'     => $exception->getMessage(),
                    ];
                }
            }
        } catch ( Throwable $exception ) {
            report($exception);
            $summary['errors'][] = [ 'message' => $exception->getMessage() ];
        }

        return $summary;
    }

    /** @return array<int, mixed> */
    private function fetchCustomers(): array {
        try {
            $response = $this->httpClient()
                ->get($this->apiUrl);
        } catch ( ConnectionException $exception ) {
            throw new RuntimeException('ارتباط با API مشتریان سایت برقرار نشد: ' . $exception->getMessage(), previous : $exception);
        }

        if ( !$response->successful() ) {
            throw new RuntimeException(sprintf('API مشتریان سایت پاسخ نامعتبر داد. HTTP %d - %s', $response->status(), mb_substr($response->body(), 0, 500)));
        }

        $json      = $response->json();
        $customers = data_get($json, 'result.data');

        if ( !is_array($json) || ( $json['success'] ?? false ) !== true || !is_array($customers) ) {
            throw new RuntimeException('ساختار پاسخ API مشتریان سایت معتبر نیست.');
        }

        return array_values($customers);
    }

    private function storeCustomer( array $payload ): string {
        $sourceId = trim((string) ( $payload['id'] ?? '' ));
        $mobile   = $this->normalizeMobile((string) ( $payload['username'] ?? '' ));

        if ( $sourceId === '' || $mobile === null ) {
            throw new RuntimeException('شناسه یا شماره موبایل مشتری معتبر نیست.');
        }

        return DB::transaction(function () use ( $payload, $sourceId, $mobile ): string {
            $bySourceId = Customer::where('crm_customer_id', $sourceId)
                ->lockForUpdate()
                ->first();
            $byMobile = Customer::where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

            if ( $bySourceId && $byMobile && !$bySourceId->is($byMobile) ) {
                throw new RuntimeException('شناسه سایت و شماره موبایل به دو مشتری متفاوت متصل هستند.');
            }

            $customer = $bySourceId ?? $byMobile ?? new Customer();
            $created  = !$customer->exists;
            $address  = $this->firstAddress($payload['addresses'] ?? []);

            $customer->fill([
                'crm_customer_id'  => $sourceId,
                'sync_source'      => 'site_registration',
                'first_name'       => $this->text($payload['first_name'] ?? null) ?? 'بدون نام',
                'last_name'        => $this->text($payload['last_name'] ?? null),
                'mobile'           => $mobile,
                'address'          => $address ?? $customer->address,
                'reservation_tier' => $customer->reservation_tier ? : 'new_or_low_purchase',
                'synced_at'        => now(),
                'crm_updated_at'   => $payload['updated_at'] ?? null,
                'last_crm_payload' => $payload,
            ])
                ->save();

            return $created ? 'created' : 'updated';
        }, 3);
    }

    private function httpClient(): PendingRequest {
        $request = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(3, 250, throw : false);
        $token   = trim((string) Config::get('services.site.token', ''));

        return $token !== '' ? $request->withToken($token) : $request;
    }

    private function firstAddress( mixed $addresses ): ?string {
        if ( !is_array($addresses) || !isset($addresses[0]) || !is_array($addresses[0]) ) {
            return null;
        }

        foreach ( [ 'address', 'full_address', 'address_line' ] as $key ) {
            if ( ( $value = $this->text($addresses[0][$key] ?? null) ) !== null ) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeMobile( string $value ): ?string {
        $value = strtr($value, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
        $value = preg_replace('/\D+/', '', $value) ?? '';

        if ( str_starts_with($value, '0098') ) {
            $value = '0' . substr($value, 4);
        }
        elseif ( str_starts_with($value, '98') ) {
            $value = '0' . substr($value, 2);
        }
        elseif ( strlen($value) === 10 && str_starts_with($value, '9') ) {
            $value = '0' . $value;
        }

        return preg_match('/^09\d{9}$/', $value) === 1 ? $value : null;
    }

    private function text( mixed $value ): ?string {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return $value !== '' ? $value : null;
    }
}
