<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockThresholdRule extends Model
{
    protected $fillable = ['target_type', 'target_id', 'measure', 'minimum'];
    protected $casts = ['target_id' => 'integer', 'minimum' => 'integer'];
}
