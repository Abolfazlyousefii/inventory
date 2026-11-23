<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CustomerSaleApproval extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $hidden = ['code_hash', 'recipient_hash', 'context_token'];
    protected $casts = [
        'requester_id' => 'integer', 'owner_id' => 'integer', 'customer_id' => 'integer', 'assignment_version' => 'integer',
        'attempts' => 'integer', 'preinvoice_order_id' => 'integer', 'code_expires_at' => 'datetime',
        'verified_at' => 'datetime', 'approval_expires_at' => 'datetime', 'bound_at' => 'datetime', 'submitted_at' => 'datetime',
    ];
}
