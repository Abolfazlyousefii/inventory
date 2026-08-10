<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BugCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'module', 'entity_type', 'entity_id', 'severity',
        'status', 'created_by', 'started_at', 'finished_at', 'error_message',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function report()
    {
        return $this->hasOne(BugCaseReport::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
