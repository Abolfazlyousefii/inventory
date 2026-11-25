<?php

namespace App\Services;

use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function notifyRole(string|array $role, string|array $type, ?string $title = null, ?string $message = null, ?string $link = null, array $meta = []): ?SystemNotification
    {
        if (is_array($type)) {
            return $this->storeForRoles($role, $type);
        }

        return $this->storeForRoles($role, $this->legacyPayload($type, $title, $message, $link, $meta));
    }

    public function notifyUser(User|int $user, string|array $type, ?string $title = null, ?string $message = null, ?string $link = null, array $meta = []): ?SystemNotification
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        if ($userId <= 0) {
            Log::warning('Notification user target is empty.', ['payload' => is_array($type) ? $type : $meta]);
            return null;
        }

        $payload = is_array($type) ? $type : $this->legacyPayload($type, $title, $message, $link, $meta);
        return $this->store(['user_id' => $userId, 'role' => null], $payload);
    }

    public function notifyRoleAfterCommit(string|array $roles, string|array $type, ?string $title = null, ?string $message = null, ?string $link = null, array $meta = []): void
    {
        $payload = is_array($type) ? $type : $this->legacyPayload($type, $title, $message, $link, $meta);
        $this->afterCommit(fn () => $this->storeForRoles($roles, $payload), 'role', (string) ($payload['type'] ?? ''), $payload);
    }

    public function notifyUserAfterCommit(User|int $user, string|array $type, ?string $title = null, ?string $message = null, ?string $link = null, array $meta = []): void
    {
        $payload = is_array($type) ? $type : $this->legacyPayload($type, $title, $message, $link, $meta);
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $this->afterCommit(fn () => $this->notifyUser($userId, $payload), 'user', (string) ($payload['type'] ?? ''), $payload + ['user_id' => $userId]);
    }

    private function storeForRoles(string|array $roles, array $payload): ?SystemNotification
    {
        $roles = array_values(array_unique(array_filter(array_map(fn ($role) => strtolower(trim((string) $role)), (array) $roles))));
        if ($roles === []) {
            Log::warning('Notification role target is empty.', ['payload' => $payload]);
            return null;
        }

        return DB::transaction(function () use ($roles, $payload) {
            $legacy = null;
            $remaining = [];
            $key = $payload['unique_key'] ?? null;
            foreach ($roles as $role) {
                // Keep existing role rows (and their historical read timestamps) intact.
                $existing = filled($key) ? $this->legacyNotification(['user_id' => null, 'role' => $role], (string) $key) : null;
                if ($existing) {
                    $legacy = $existing;
                } else {
                    $remaining[] = $role;
                }
            }
            if ($remaining === []) {
                return $legacy;
            }

            // A role event is a single row even when a user belongs to multiple target roles.
            $notification = $this->store(['user_id' => null, 'role' => $remaining[0]], $payload, 'role-event');
            DB::table('notification_role_targets')->insertOrIgnore(array_map(fn ($role) => [
                'notification_id' => $notification->id, 'role' => $role,
            ], $remaining));

            return $notification;
        });
    }

    private function legacyPayload(string $type, ?string $title, ?string $message, ?string $link, array $meta): array
    {
        return $meta + ['type' => $type, 'title' => (string) $title, 'message' => $message, 'url' => $link];
    }

    private function afterCommit(callable $callback, string $target, string $type, array $payload): void
    {
        DB::afterCommit(function () use ($callback, $target, $type, $payload): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::warning('Notification dispatch failed after commit.', [
                    'target' => $target,
                    'type' => $type,
                    'notifiable_type' => $payload['notifiable_type'] ?? null,
                    'notifiable_id' => $payload['notifiable_id'] ?? null,
                    'unique_key' => $payload['unique_key'] ?? null,
                    'user_id' => $payload['user_id'] ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    private function store(array $target, array $payload, ?string $recipientScope = null): SystemNotification
    {
        $data = $payload['data'] ?? [];
        $link = $payload['url'] ?? $payload['link'] ?? null;
        $priority = $payload['priority'] ?? $this->priorityFromLevel($payload['level'] ?? null);

        $record = array_merge($target, [
            'type' => (string) ($payload['type'] ?? 'general'),
            'level' => $payload['level'] ?? $this->levelFromPriority($priority),
            'priority' => $priority,
            'title' => (string) ($payload['title'] ?? 'اعلان جدید'),
            'message' => $payload['message'] ?? null,
            'link' => $link,
            'data' => array_filter(array_merge(is_array($data) ? $data : [], ['action_url' => $link]), fn ($v) => $v !== null && $v !== ''),
            'notifiable_type' => $payload['notifiable_type'] ?? null,
            'notifiable_id' => $payload['notifiable_id'] ?? null,
            'unique_key' => $payload['unique_key'] ?? null,
        ]);

        if (filled($record['unique_key'])) {
            $eventKey = (string) $record['unique_key'];
            $recipient = $recipientScope ?? 'user:'.$target['user_id'];
            if ($recipientScope === null && ($legacy = $this->legacyNotification($target, $eventKey))) {
                return $legacy;
            }

            // The existing unique index makes retries and concurrent inserts idempotent.
            $record['unique_key'] = 'notification:v2:'.hash('sha256', json_encode([$recipient, $eventKey], JSON_THROW_ON_ERROR));
            return SystemNotification::firstOrCreate(['unique_key' => $record['unique_key']], $record);
        }

        $record['unique_key'] = null;
        return SystemNotification::create($record);
    }

    private function legacyNotification(array $target, string $eventKey): ?SystemNotification
    {
        $query = SystemNotification::query()->where('user_id', $target['user_id']);
        if ($target['role'] === null) {
            return $query->whereNull('role')->where('unique_key', $eventKey)->first();
        }

        return $query->whereRaw('LOWER(role) = ?', [$target['role']])
            ->where(function ($query) use ($target, $eventKey) {
                $query->where('unique_key', $eventKey)
                    ->orWhereRaw('LOWER(unique_key) = ?', [strtolower($eventKey.':'.$target['role'])]);
            })->first();
    }

    private function priorityFromLevel(?string $level): string
    {
        return match ($level) {
            'danger', 'error', 'warning' => 'urgent',
            'success', 'info' => 'important',
            default => 'normal',
        };
    }

    private function levelFromPriority(?string $priority): string
    {
        return match ($priority) {
            'urgent' => 'danger',
            'important' => 'info',
            default => 'info',
        };
    }
}
