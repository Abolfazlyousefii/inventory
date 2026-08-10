<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessPermission extends Model
{
    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'key',
        'group',
        'guard_name',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions', 'permission_id', 'user_id')
            ->withTimestamps();
    }
}
