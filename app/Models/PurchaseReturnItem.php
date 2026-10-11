<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseReturnItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['quantity'=>'integer','buy_price'=>'integer','line_total'=>'integer'];
}
