<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionCalculationWarning extends Model
{
    protected $guarded = [];

    protected $casts = ['context' => 'array'];
}
