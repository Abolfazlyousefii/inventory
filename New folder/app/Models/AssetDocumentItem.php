<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDocumentItem extends Model
{
    protected $fillable = [
        'document_id',
        'item_name',
        'quantity',
        'description',
    ];

    public function document()
    {
        return $this->belongsTo(AssetDocument::class, 'document_id');
    }

    public function codes()
    {
        return $this->hasMany(AssetDocumentItemCode::class, 'document_item_id');
    }
}
