<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialTransferItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
