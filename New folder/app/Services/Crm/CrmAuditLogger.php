<?php

namespace App\Services\Crm;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class CrmAuditLogger
{
    public function record(string $action, ?User $user = null, array $properties = []): void
    {
        $safe = array_intersect_key($properties, array_flip([
            'request_id', 'crm_user_id', 'erp_user_id', 'status', 'error_category',
            'created', 'roles_changed', 'manager_changed', 'active_changed', 'counts',
        ]));

        try {
            ActivityLog::query()->create([
                'user_id' => $user?->id,
                'action' => mb_substr($action, 0, 30),
                'subject_type' => $user ? User::class : null,
                'subject_id' => $user?->id,
                'description' => $action,
                'properties' => $safe,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::notice('CRM audit persistence failed', [
                'action' => $action,
                'error_category' => $e::class,
            ]);
        }
    }
}
