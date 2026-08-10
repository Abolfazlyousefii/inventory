<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    protected $fillable = [
        'warehouse_id',
        'zone',
        'rack',
        'box',
        'code',
        'description',
        'is_active',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $location) {
            $location->zone = self::normalizePart((string) $location->zone, 'Z');
            $location->rack = self::normalizePart((string) $location->rack, 'R');
            $location->box = self::normalizePart((string) $location->box, 'B');
            $location->code = self::makeCode($location->zone, $location->rack, $location->box);
        });
    }

    public static function normalizePart(string $value, string $prefix): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?: '';

        if ($value !== '' && ! str_starts_with($value, $prefix)) {
            $value = $prefix . str_pad($value, 2, '0', STR_PAD_LEFT);
        }

        return $value;
    }

    public static function makeCode(string $zone, string $rack, string $box): string
    {
        return implode('-', [$zone, $rack, $box]);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stocks()
    {
        return $this->hasMany(WarehouseLocationStock::class);
    }
}
