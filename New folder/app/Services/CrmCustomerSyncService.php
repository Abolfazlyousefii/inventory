<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CrmCustomerSyncService
{
    public function __construct(
        private readonly CrmClient $crmClient,
    ) {
    }

    public function sync(): array
    {
        $response = $this->crmClient->fetchCustomersResponse();

        if (!($response['ok'] ?? false)) {
            return $this->failedResult($response['error'] ?? 'Unknown CRM error');
        }

        $crmCustomers = $this->extractCustomers($response['payload'] ?? []);

        $result = [
            'pulled_created' => 0,
            'pulled_updated' => 0,
            'deleted' => 0,
            'pushed_created' => 0,
            'pushed_updated' => 0,
            'failed' => 0,
            'error' => null,
        ];

        $syncedCrmIds = [];

        foreach ($crmCustomers as $raw) {
            try {
                $normalized = $this->normalizeFromCrm($raw);

                if ($normalized['crm_customer_id'] === '') {
                    $result['failed']++;
                    continue;
                }

                $status = DB::transaction(fn (): string => $this->upsertFromCrm($normalized, $raw));
                $syncedCrmIds[] = $normalized['crm_customer_id'];
                $result[$status === 'created' ? 'pulled_created' : 'pulled_updated']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::warning('CRM customer pull failed', [
                    'error' => $e->getMessage(),
                    'customer' => $raw,
                ]);
            }
        }

        $result['deleted'] = $this->deleteMissingCrmCustomers($syncedCrmIds);

        foreach ($this->customersNeedingPush() as $customer) {
            try {
                $pushResult = $this->pushToCrm($customer);

                if ($pushResult === 'created') {
                    $result['pushed_created']++;
                } elseif ($pushResult === 'updated') {
                    $result['pushed_updated']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::warning('CRM customer push failed', [
                    'error' => $e->getMessage(),
                    'customer_id' => $customer->id,
                ]);
            }
        }

        return $result;
    }

    private function extractCustomers(array $payload): array
    {
        foreach ((array) config('crm.response.customers_path_candidates', []) as $path) {
            $items = Arr::get($payload, $path);

            if (is_array($items)) {
                return $this->listItems($items);
            }
        }

        return $this->listItems($payload);
    }

    private function listItems(array $items): array
    {
        if (isset($items['data']) && is_array($items['data'])) {
            return $this->listItems($items['data']);
        }

        return array_values(array_filter($items, fn ($item): bool => is_array($item)));
    }

    private function normalizeFromCrm(array $raw): array
    {
        $fullName = $this->stringValue($raw, 'name');
        [$firstName, $lastName] = $this->splitName($fullName);

        return [
            'crm_customer_id' => $this->stringValue($raw, 'id'),
            'first_name' => $this->stringValue($raw, 'first_name') ?: $firstName,
            'last_name' => $this->stringValue($raw, 'last_name') ?: $lastName,
            'mobile' => $this->normalizeMobile($this->stringValue($raw, 'mobile')),
            'address' => $this->stringValue($raw, 'address') ?: null,
            'postal_code' => $this->stringValue($raw, 'postal_code') ?: null,
            'extra_description' => $this->stringValue($raw, 'extra_description') ?: $this->stringValue($raw, 'DISC') ?: null,
            'province_id' => $this->integerValue($raw, 'province_id'),
            'city_id' => $this->integerValue($raw, 'city_id'),
            'crm_updated_at' => $this->dateValue($raw, 'updated_at'),
        ];
    }

    private function upsertFromCrm(array $data, array $raw): string
    {
        $customer = Customer::query()
            ->where('crm_customer_id', $data['crm_customer_id'])
            ->when($data['mobile'] !== '', fn ($query) => $query->orWhere('mobile', $data['mobile']))
            ->lockForUpdate()
            ->first();

        $isNew = ! $customer;
        $customer ??= new Customer();

        $customer->fill([
            'crm_customer_id' => $data['crm_customer_id'],
            'sync_source' => 'crm',
            'first_name' => $data['first_name'] ?: ($customer->first_name ?: 'بدون نام'),
            'last_name' => $data['last_name'] ?: $customer->last_name,
            'mobile' => $data['mobile'] ?: $customer->mobile,
            'address' => $data['address'] ?: $customer->address,
            'postal_code' => $data['postal_code'] ?: $customer->postal_code,
            'extra_description' => $data['extra_description'] ?: $customer->extra_description,
            'province_id' => $data['province_id'] ?: $customer->province_id,
            'city_id' => $data['city_id'] ?: $customer->city_id,
            'synced_at' => now(),
            'crm_updated_at' => $data['crm_updated_at'],
            'last_crm_payload' => $raw,
        ]);

        if (! $customer->mobile) {
            $customer->mobile = 'crm-' . substr(sha1($data['crm_customer_id']), 0, 16);
        }

        $customer->save();

        return $isNew ? 'created' : 'updated';
    }

    private function deleteMissingCrmCustomers(array $syncedCrmIds): int
    {
        if (config('crm.sync_missing_customers_strategy') !== 'delete' || $syncedCrmIds === []) {
            return 0;
        }

        return Customer::query()
            ->where('sync_source', 'crm')
            ->whereNotNull('crm_customer_id')
            ->whereNotIn('crm_customer_id', $syncedCrmIds)
            ->delete();
    }

    private function customersNeedingPush()
    {
        return Customer::query()
            ->where(function ($query): void {
                $query->whereNull('crm_customer_id')
                    ->orWhereColumn('updated_at', '>', 'synced_at')
                    ->orWhereNull('synced_at');
            })
            ->orderBy('id')
            ->limit((int) config('crm.customer_push_batch_size', 100))
            ->get();
    }

    private function pushToCrm(Customer $customer): string
    {
        $payload = $this->payloadForCrm($customer);
        $wasKnownByCrm = ! empty($customer->crm_customer_id);

        $response = $customer->crm_customer_id
            ? $this->crmClient->updateCustomer((string) $customer->crm_customer_id, $payload)
            : $this->crmClient->createCustomer($payload);

        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException($response['error'] ?? 'CRM customer push failed.');
        }

        $crmPayload = $response['payload'] ?? [];
        $crmCustomer = $this->firstCustomerPayload($crmPayload);
        $crmCustomerId = $this->stringValue($crmCustomer, 'id') ?: (string) $customer->crm_customer_id;

        if ($crmCustomerId === '') {
            throw new \RuntimeException('CRM customer response does not contain customer id.');
        }

        $customer->timestamps = false;
        $customer->forceFill([
            'crm_customer_id' => $crmCustomerId,
            'sync_source' => $customer->sync_source ?: 'inventory',
            'synced_at' => now(),
            'crm_updated_at' => $this->dateValue($crmCustomer, 'updated_at'),
            'last_crm_payload' => is_array($crmPayload) ? $crmPayload : null,
        ])->save();
        $customer->timestamps = true;

        return $wasKnownByCrm ? 'updated' : 'created';
    }

    private function payloadForCrm(Customer $customer): array
    {
        return array_filter([
            'external_inventory_id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'name' => $customer->display_name ?: $customer->first_name ?: $customer->mobile,
            'mobile' => $customer->mobile,
            'phone' => $customer->mobile,
            'address' => $customer->address,
            'postal_code' => $customer->postal_code,
            'extra_description' => $customer->extra_description,
            'province_id' => $customer->province_id,
            'city_id' => $customer->city_id,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function firstCustomerPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        foreach (['customer', 'data.customer', 'data', 'customers.0'] as $path) {
            $item = Arr::get($payload, $path);

            if (is_array($item)) {
                return $item;
            }
        }

        return $payload;
    }

    private function stringValue(array $data, string $key): string
    {
        $fieldMap = (array) config("crm.response.customer_field_map.{$key}", [$key]);

        foreach ($fieldMap as $candidate) {
            $value = Arr::get($data, $candidate);

            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function integerValue(array $data, string $key): ?int
    {
        $value = $this->stringValue($data, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function dateValue(array $data, string $key): ?Carbon
    {
        $value = $this->stringValue($data, $key);

        try {
            return $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeMobile(string $mobile): string
    {
        return Str::of($mobile)->replace([' ', '-', '(', ')'], '')->toString();
    }

    private function splitName(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return [$parts[0] ?? $name, $parts[1] ?? null];
    }

    private function failedResult(string $error): array
    {
        return [
            'pulled_created' => 0,
            'pulled_updated' => 0,
            'deleted' => 0,
            'pushed_created' => 0,
            'pushed_updated' => 0,
            'failed' => 0,
            'error' => $error,
        ];
    }
}
