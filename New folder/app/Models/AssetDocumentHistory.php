<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDocumentHistory extends Model
{
    protected $fillable = [
        'document_id',
        'action_type',
        'old_value',
        'new_value',
        'description',
        'done_by',
        'done_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'done_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(AssetDocument::class, 'document_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
