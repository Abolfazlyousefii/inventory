<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRateRevision extends Model
{
    protected $fillable = ['target_type', 'target_id', 'target_key', 'active_marker', 'category_id', 'product_id', 'product_variant_id', 'percentage', 'effective_from', 'effective_to', 'created_by'];

    protected $casts = ['percentage' => 'decimal:4', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
