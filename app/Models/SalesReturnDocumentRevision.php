<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnDocumentRevision extends Model
{
    public $timestamps = false;
    protected $fillable = ['document_id','action','token','reason','before_snapshot','after_snapshot','previous_total','new_total','created_by','created_at'];
    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'previous_total' => 'integer', 'new_total' => 'integer', 'created_at' => 'datetime'];
    public function document(){ return $this->belongsTo(SalesReturnDocument::class, 'document_id'); }
}
