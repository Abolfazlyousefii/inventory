<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryWebhookSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'endpoint_url',
        'secret',
        'timeout_seconds',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'timeout_seconds' => 'integer',
    ];
}
