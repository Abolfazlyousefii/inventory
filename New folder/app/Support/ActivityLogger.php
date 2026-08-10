<?php

namespace App\Support;

use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(string $action, Model $subject, string $description, array $properties = []): void
    {
        ActivityLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'description' => $description,
            'properties' => $properties,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
