<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCnyPricing extends Model {
    protected $guarded=[];
    protected $casts=['buy_cny_units'=>'integer','enabled'=>'boolean'];
}
