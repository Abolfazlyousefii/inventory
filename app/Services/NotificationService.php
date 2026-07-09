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
        $created = null;
        foreach ((array) $roles as $role) {
            $role = trim((string) $role);
            if ($role === '') {
                continue;
            }
            $rolePayload = $payload;
            if (! empty($payload['unique_key']) && count((array) $roles) > 1) {
                $rolePayload['unique_key'] = $payload['unique_key'] . ':' . $role;
            }
            $created = $this->store(['user_id' => null, 'role' => $role], $rolePayload);
        }
        if (! $created) {
            Log::warning('Notification role target is empty.', ['roles' => $roles, 'payload' => $payload]);
        }
        return $created;
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

    private function store(array $target, array $payload): SystemNotification
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

        if (! empty($record['unique_key'])) {
            return SystemNotification::updateOrCreate(['unique_key' => $record['unique_key']], $record);
        }

        return SystemNotification::create($record);
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
