<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionReturnSyncOutbox extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sales_return_document_id' => 'integer',
        'actor_id' => 'integer',
        'attempts' => 'integer',
        'available_at' => 'datetime',
    ];
}
