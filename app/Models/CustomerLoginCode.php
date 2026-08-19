<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoginCode extends Model
{
    protected $fillable = ['customer_id', 'phone', 'code_hash', 'expires_at', 'consumed_at', 'attempts', 'request_ip'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
