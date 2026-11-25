<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SystemNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'role', 'type', 'level', 'priority', 'title', 'message', 'link', 'data',
        'notifiable_type', 'notifiable_id', 'unique_key', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime', 'personal_read_at' => 'datetime', 'data' => 'array'];

    public function scopeForUser(Builder $query, User $user): Builder
    {
        $roles = $user->getRoleNames()->map(fn ($role) => strtolower(trim($role)))->unique()->all();

        return $query->where(function (Builder $query) use ($user, $roles) {
            $query->where('notifications.user_id', $user->id)
                ->orWhere(function (Builder $query) use ($roles) {
                    $query->whereNull('notifications.user_id')->where(function (Builder $query) use ($roles) {
                        $query->whereIn(DB::raw('LOWER(notifications.role)'), $roles)
                            ->orWhereExists(DB::table('notification_role_targets')
                                ->whereColumn('notification_id', 'notifications.id')->whereIn('role', $roles));
                    });
                });
        });
    }

    private static function receiptQuery(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('notification_reads')->whereColumn('notification_id', 'notifications.id')
            ->where('notification_reads.user_id', $user->id);
    }

    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query->whereNull('notifications.read_at')->whereNotExists(self::receiptQuery($user));
    }

    public function scopeReadFor(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->whereNotNull('notifications.read_at')->orWhereExists(self::receiptQuery($user));
        });
    }

    public function scopeWithReadState(Builder $query, User $user): Builder
    {
        return $query->select('notifications.*')->addSelect([
            'personal_read_at' => self::receiptQuery($user)->select('read_at'),
        ]);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('notifications.created_at')->orderByDesc('notifications.id');
    }

    public function readTimestamp(): ?\Illuminate\Support\Carbon
    {
        return $this->read_at ?? $this->personal_read_at;
    }

    public function markAsReadFor(User $user): void
    {
        if ($this->read_at !== null) {
            return;
        }
        if ($this->user_id !== null) {
            self::query()->forUser($user)->whereKey($this->id)->whereNull('read_at')->update(['read_at' => now()]);
            return;
        }
        DB::table('notification_reads')->insertOrIgnore([
            'notification_id' => $this->id, 'user_id' => $user->id, 'read_at' => now(),
        ]);
    }

    public static function markAllAsReadFor(User $user): void
    {
        // Freeze the upper ID boundary: notifications arriving during this operation remain unread.
        $maxId = self::query()->forUser($user)->max('id');
        if ($maxId === null) {
            return;
        }
        $unread = self::query()->forUser($user)->unreadFor($user)->where('notifications.id', '<=', $maxId);
        $timestamp = now();
        DB::transaction(function () use ($unread, $user, $timestamp) {
            (clone $unread)->whereNotNull('notifications.user_id')->update(['read_at' => $timestamp]);
            (clone $unread)->whereNull('notifications.user_id')->select('notifications.id')->chunkById(500, function ($notifications) use ($user, $timestamp) {
                DB::table('notification_reads')->insertOrIgnore($notifications->map(fn ($notification) => [
                    'notification_id' => $notification->id, 'user_id' => $user->id, 'read_at' => $timestamp,
                ])->all());
            }, 'notifications.id', 'id');
        });
    }

    public function categoryLabel(): string
    {
        $type = strtolower($this->type);
        return match (true) {
            str_contains($type, 'personnel') => 'پرسنل',
            str_contains($type, 'finance'), str_contains($type, 'payment'), $type === 'preinvoice_submitted' => 'مالی',
            str_contains($type, 'warehouse'), str_contains($type, 'inventory'), str_contains($type, 'ship'), str_contains($type, 'collection'), str_contains($type, 'stock') => 'انبار',
            str_contains($type, 'invoice'), str_contains($type, 'customer'), str_contains($type, 'sales') => 'فروش',
            default => 'عمومی',
        };
    }
}
