<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteStockHoldLine extends Model
{
    public $timestamps = false;
    protected $fillable = ['hold_id', 'product_id', 'variant_id', 'quantity'];
}
