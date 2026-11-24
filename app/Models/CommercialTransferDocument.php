<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialTransferDocument extends Model
{
    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'number' => 'integer', 'registered_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function items()
    {
        return $this->hasMany(CommercialTransferItem::class, 'document_id')->orderBy('target_id');
    }

    public function events()
    {
        return $this->hasMany(CommercialTransferEvent::class, 'document_id')->orderBy('id');
    }
}
