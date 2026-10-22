<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteStockHold extends Model
{
    protected $fillable = [
        'site_order_id', 'fingerprint', 'status', 'expires_at',
        'confirmed_at', 'released_at', 'consumed_at', 'invoice_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'released_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(SiteStockHoldLine::class, 'hold_id');
    }
}
