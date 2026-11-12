<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CnyExchangeRate extends Model {
    protected $guarded=[];
    protected $casts=['rate_rial'=>'integer','quoted_at'=>'datetime','fetched_at'=>'datetime'];
}
