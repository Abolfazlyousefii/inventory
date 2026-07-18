<?php
namespace App\Models\Integration;
use Illuminate\Database\Eloquent\Model;
class IntegrationOutboxEvent extends Model { protected $fillable=['event_id','destination','event_type','aggregate_type','aggregate_id','payload','status','attempts','available_at','last_attempt_at','delivered_at','last_http_status','last_error']; protected $casts=['payload'=>'array','available_at'=>'datetime','last_attempt_at'=>'datetime','delivered_at'=>'datetime','attempts'=>'integer']; }
