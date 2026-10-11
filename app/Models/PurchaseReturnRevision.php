<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseReturnRevision extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['before'=>'array','after'=>'array'];
    public function user() { return $this->belongsTo(User::class); }
}
