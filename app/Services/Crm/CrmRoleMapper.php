<?php

namespace App\Services\Crm;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

final class CrmRoleMapper
{
    public function sync(User $user, array $crmRoles): array
    {
        $mapping = (array) config('crm.roles.mapping', []);
        $managed = collect((array) config('crm.roles.managed', []))->map(fn ($role) => mb_strtolower((string) $role));
        $mapped = collect();
        $unknown = collect();

        foreach ($crmRoles as $crmRole) {
            $targets = $mapping[$crmRole] ?? $mapping[mb_strtolower((string) $crmRole)] ?? null;
            if ($targets === null) {
                $unknown->push((string) $crmRole);

                continue;
            }
            $mapped = $mapped->merge((array) $targets);
        }

        $existingRoleNames = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $mapped->unique()->all())
            ->pluck('name');

        $localRoles = $user->roles->pluck('name')
            ->reject(fn (string $role) => $managed->contains(mb_strtolower($role)));

        $user->syncRoles($localRoles->merge($existingRoleNames)->unique()->values()->all());

        if ($unknown->isNotEmpty()) {
            Log::warning('CRM role mapping missing', [
                'crm_user_id' => $user->crm_user_id,
                'role_names' => $unknown->take(10)->values()->all(),
            ]);
        }

        return [
            'mapped' => $existingRoleNames->values()->all(),
            'unknown' => $unknown->values()->all(),
        ];
    }
}
