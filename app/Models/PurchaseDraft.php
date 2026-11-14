<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PurchaseDraft extends Model {
    protected $primaryKey='token';
    public $incrementing=false;
    protected $keyType='string';
    protected $guarded=[];
    protected $casts=['payload'=>'array','revision'=>'integer'];
}
