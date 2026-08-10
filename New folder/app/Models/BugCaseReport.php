<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugCaseReport extends Model
{
    protected $fillable = [
        'bug_case_id', 'summary', 'raw_report', 'codex_prompt',
        'findings', 'suspected_files', 'broken_rules',
    ];

    protected $casts = [
        'findings' => 'array',
        'suspected_files' => 'array',
        'broken_rules' => 'array',
    ];

    public function bugCase()
    {
        return $this->belongsTo(BugCase::class);
    }
}
