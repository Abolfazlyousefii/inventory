<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDocumentItemCode extends Model
{
    protected $fillable = [
        'document_item_id',
        'asset_code',
    ];

    public function item()
    {
        return $this->belongsTo(AssetDocumentItem::class, 'document_item_id');
    }
}
