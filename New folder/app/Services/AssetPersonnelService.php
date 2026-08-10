<?php

namespace App\Services;

use App\Models\AssetPersonnel;

class AssetPersonnelService
{
    public function create(array $data): AssetPersonnel
    {
        return AssetPersonnel::query()->create($data);
    }

    public function update(AssetPersonnel $personnel, array $data): AssetPersonnel
    {
        $personnel->update($data);
        return $personnel->fresh();
    }

    public function toggleStatus(AssetPersonnel $personnel): AssetPersonnel
    {
        $personnel->update(['is_active' => !$personnel->is_active]);
        return $personnel->fresh();
    }
}
