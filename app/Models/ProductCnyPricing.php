<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductCnyPricing extends Model {
    protected $guarded=[];
    protected $casts=['buy_cny_units'=>'integer','enabled'=>'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
    public function appliedRate() { return $this->belongsTo(CnyExchangeRate::class, 'applied_rate_id'); }
}
