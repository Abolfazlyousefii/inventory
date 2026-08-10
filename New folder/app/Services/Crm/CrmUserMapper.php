<?php

namespace App\Services\Crm;

use App\Data\CrmUserData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CrmUserMapper
{
    public function map(array $payload): CrmUserData
    {
        $row = $this->unwrapUser($payload);
        Validator::make($row, [
            'id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'can_access_erp' => ['required', 'boolean'],
            'is_seller' => ['required', 'boolean'],
            'created_at' => ['required', 'date'],
            'updated_at' => ['required', 'date'],
        ])->validate();

        return new CrmUserData(
            crmUserId: (string) $row['id'], name: trim($row['name']),
            phone: $this->nullableString($row['phone'] ?? null), email: $this->nullableString($row['email'] ?? null),
            isActive: $row['is_active'], canAccessErp: $row['can_access_erp'], isSeller: $row['is_seller'],
            username: null, personnelCode: null, department: null, position: null, branch: null,
            managerCrmUserId: isset($row['manager_id']) ? (string) $row['manager_id'] : null,
            roles: $this->roles($row['roles']), createdAt: $this->date($row['created_at']),
            updatedAt: $this->date($row['updated_at']), avatar: null,
        );
    }

    public function extractUsers(array $payload): array
    {
        if (! array_key_exists('data', $payload) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
            throw ValidationException::withMessages(['data' => 'CRM response data must be an array.']);
        }
        if (($payload['meta']['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['meta.schema_version' => 'Unsupported CRM schema version.']);
        }
        return array_values(array_filter($payload['data'], 'is_array'));
    }

    private function unwrapUser(array $payload): array
    {
        foreach ((array) config('crm.response.user_path_candidates', ['data', 'user']) as $path) {
            $candidate = Arr::get($payload, $path);
            if (is_array($candidate) && ! array_is_list($candidate)) {
                return $candidate;
            }
        }

        return $payload;
    }

    private function firstPresent(array $row, array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if (Arr::has($row, $candidate)) {
                return Arr::get($row, $candidate);
            }
        }

        return null;
    }

    private function roles(mixed $roles): array
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }

        if (! is_array($roles)) {
            return [];
        }

        return collect($roles)->map(function (mixed $role): ?string {
            if (is_array($role)) {
                $role = $role['name'] ?? $role['slug'] ?? null;
            }

            return $this->nullableString($role);
        })->filter()->unique()->values()->all();
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(mb_strtolower(trim((string) $value)), ['0', 'false', 'inactive', 'disabled', 'deleted'], true);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['updated_at' => 'تاریخ کاربر CRM نامعتبر است.']);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
