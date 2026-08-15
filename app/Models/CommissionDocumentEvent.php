<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionDocumentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function document()
    {
        return $this->belongsTo(CommissionDocument::class, 'commission_document_id');
    }

    public function item()
    {
        return $this->belongsTo(CommissionDocumentItem::class, 'commission_document_item_id');
    }
}
