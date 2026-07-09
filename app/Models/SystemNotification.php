<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SystemNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id','role','type','level','priority','title','message','link','data',
        'notifiable_type','notifiable_id','unique_key','read_at',
    ];

    protected $casts = ['read_at' => 'datetime', 'data' => 'array'];

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['admin', 'Admin'])) {
            return $query;
        }

        $roles = $user->getRoleNames()->map(fn ($r) => (string) $r)->all();

        return $query->where(function (Builder $q) use ($user, $roles) {
            $q->where('user_id', $user->id)
                ->orWhere(function (Builder $rQ) use ($roles) {
                    $rQ->whereNull('user_id')->whereIn('role', $roles);
                });
        });
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}
