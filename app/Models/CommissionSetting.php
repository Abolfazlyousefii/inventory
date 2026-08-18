<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CommissionSetting extends Model
{
    protected $fillable = [
        'cycle_day',
        'pilot_mode',
        'seller_visibility_enabled',
        'targets_enabled',
        'updated_by',
    ];

    protected $casts = [
        'cycle_day' => 'integer',
        'pilot_mode' => 'boolean',
        'seller_visibility_enabled' => 'boolean',
        'targets_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        $now = now();
        DB::table((new static)->getTable())->insertOrIgnore([
            'id' => 1,
            'cycle_day' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return static::query()->findOrFail(1);
    }
}
