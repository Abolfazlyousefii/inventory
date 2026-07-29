<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSyncState extends Model
{
    protected $fillable = [
        'integration',
        'stream',
        'last_started_at',
        'last_succeeded_at',
        'last_failed_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
