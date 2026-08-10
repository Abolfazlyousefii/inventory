<?php

namespace App\Support;

use App\Models\City;
use App\Models\Province;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class IranLocations
{
    public static function provinces(): array
    {
        if (self::hasLocationTables()) {
            $provinces = Province::query()
                ->where('is_active', true)
                ->with(['cities' => fn ($q) => $q->where('is_active', true)->orderBy('name')->select(['id', 'province_id', 'name'])])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Province $province) => [
                    'id' => (int) $province->id,
                    'name' => $province->name,
                    'cities' => $province->cities->map(fn (City $city) => [
                        'id' => (int) $city->id,
                        'name' => $city->name,
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            if ($provinces !== []) {
                return $provinces;
            }
        }

        return config('iran.provinces', []);
    }

    public static function province(?int $provinceId): ?array
    {
        if (!$provinceId) {
            return null;
        }

        if (self::hasLocationTables()) {
            $province = Province::query()->where('is_active', true)->find($provinceId, ['id', 'name']);

            if ($province) {
                return ['id' => (int) $province->id, 'name' => $province->name];
            }

            if (Province::query()->where('is_active', true)->exists()) {
                return null;
            }
        }

        return collect(config('iran.provinces', []))->firstWhere('id', $provinceId);
    }

    public static function cities(?int $provinceId): array
    {
        if (!$provinceId) {
            return [];
        }

        if (self::hasLocationTables() && Province::query()->where('is_active', true)->exists()) {
            return City::query()
                ->where('province_id', $provinceId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (City $city) => ['id' => (int) $city->id, 'name' => $city->name])
                ->values()
                ->all();
        }

        $province = self::province($provinceId);

        return collect($province['cities'] ?? [])
            ->sortBy('name', SORT_NATURAL)
            ->values()
            ->all();
    }

    public static function provinceExists(?int $provinceId): bool
    {
        return self::province($provinceId) !== null;
    }

    public static function cityBelongsToProvince(?int $provinceId, ?int $cityId): bool
    {
        if (!$cityId) {
            return true;
        }

        if (!$provinceId) {
            return false;
        }

        if (self::hasLocationTables() && Province::query()->where('is_active', true)->exists()) {
            return City::query()
                ->whereKey($cityId)
                ->where('province_id', $provinceId)
                ->where('is_active', true)
                ->exists();
        }

        return collect(Arr::get(self::province($provinceId), 'cities', []))
            ->contains(fn (array $city) => (int) $city['id'] === (int) $cityId);
    }

    private static function hasLocationTables(): bool
    {
        try {
            return Schema::hasTable('provinces') && Schema::hasTable('cities');
        } catch (\Throwable) {
            return false;
        }
    }
}
