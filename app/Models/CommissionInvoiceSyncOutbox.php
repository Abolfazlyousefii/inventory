<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionInvoiceSyncOutbox extends Model
{
    protected $guarded = [];

    protected $casts = [
        'invoice_id' => 'integer',
        'old_date' => 'datetime',
        'new_date' => 'datetime',
        'attempts' => 'integer',
        'available_at' => 'datetime',
    ];
}
