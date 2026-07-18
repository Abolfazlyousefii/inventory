<?php
namespace App\Models\Integration;
use Illuminate\Database\Eloquent\Model;
class IntegrationInboundEvent extends Model { protected $fillable=['event_id','source','event_type','payload_hash','status','external_reference','response_code','error','processed_at']; protected $casts=['processed_at'=>'datetime']; }
