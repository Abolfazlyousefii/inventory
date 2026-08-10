<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    protected $fillable = ['province_id', 'name', 'slug', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
