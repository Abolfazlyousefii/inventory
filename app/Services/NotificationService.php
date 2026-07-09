<?php

namespace App\Services;

use App\Models\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function notifyRole(string $role, string $type, string $title, ?string $message, ?string $link, array $meta = []): SystemNotification
    {
        return $this->store([
            'user_id' => null,
            'role' => $role,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ], $meta);
    }

    public function notifyUser(int $userId, string $type, string $title, ?string $message, ?string $link, array $meta = []): SystemNotification
    {
        return $this->store([
            'user_id' => $userId,
            'role' => null,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ], $meta);
    }

    public function notifyRoleAfterCommit(string $role, string $type, string $title, ?string $message, ?string $link, array $meta = []): void
    {
        $this->afterCommit(function () use ($role, $type, $title, $message, $link, $meta): void {
            $this->notifyRole($role, $type, $title, $message, $link, $meta);
        }, 'role', $type, $meta);
    }

    public function notifyUserAfterCommit(int $userId, string $type, string $title, ?string $message, ?string $link, array $meta = []): void
    {
        $this->afterCommit(function () use ($userId, $type, $title, $message, $link, $meta): void {
            $this->notifyUser($userId, $type, $title, $message, $link, $meta);
        }, 'user', $type, $meta + ['user_id' => $userId]);
    }

    private function afterCommit(callable $callback, string $target, string $type, array $meta): void
    {
        DB::afterCommit(function () use ($callback, $target, $type, $meta): void {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::warning('Notification dispatch failed after commit.', [
                    'target' => $target,
                    'type' => $type,
                    'notifiable_type' => $meta['notifiable_type'] ?? null,
                    'notifiable_id' => $meta['notifiable_id'] ?? null,
                    'unique_key' => $meta['unique_key'] ?? null,
                    'user_id' => $meta['user_id'] ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    private function store(array $base, array $meta): SystemNotification
    {
        $payload = array_merge($base, [
            'level' => $meta['level'] ?? 'info',
            'notifiable_type' => $meta['notifiable_type'] ?? null,
            'notifiable_id' => $meta['notifiable_id'] ?? null,
            'unique_key' => $meta['unique_key'] ?? null,
        ]);

        if (!empty($payload['unique_key'])) {
            return SystemNotification::updateOrCreate(
                ['unique_key' => $payload['unique_key']],
                $payload
            );
        }

        return SystemNotification::create($payload);
    }
}
