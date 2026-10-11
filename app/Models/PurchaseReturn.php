<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use SoftDeletes;
    protected $guarded = ['id'];
    protected $casts = ['returned_at'=>'date','version'=>'integer','total_amount'=>'integer','total_quantity'=>'integer'];
    public function items() { return $this->hasMany(PurchaseReturnItem::class); }
    public function purchase() { return $this->belongsTo(Purchase::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function revisions() { return $this->hasMany(PurchaseReturnRevision::class)->latest('id'); }
}
