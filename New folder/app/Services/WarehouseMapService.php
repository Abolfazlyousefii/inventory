<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\WarehouseLocation;
use App\Models\WarehouseLocationMovement;
use App\Models\WarehouseLocationStock;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseMapService
{
    public function locationsForVariant(int $variantId, int $warehouseId)
    {
        return WarehouseLocationStock::query()
            ->with('location')
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->orderByDesc('quantity')
            ->get();
    }

    public function totalQuantity(int $variantId, int $warehouseId, bool $lock = false): int
    {
        $variant = ProductVariant::query()->find($variantId);
        if (! $variant) {
            return 0;
        }

        $query = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $stock = $query->first();
        if ($stock) {
            return max(0, (int) $stock->quantity);
        }

        return max(0, (int) $variant->stock);
    }

    public function mappedQuantity(int $variantId, int $warehouseId, bool $lock = false): int
    {
        $query = WarehouseLocationStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return max(0, (int) $query->sum('quantity'));
    }

    public function unmappedQuantity(int $variantId, int $warehouseId): int
    {
        return $this->totalQuantity($variantId, $warehouseId) - $this->mappedQuantity($variantId, $warehouseId);
    }

    public function assignLocation(int $variantId, int $warehouseId, int $locationId, int $quantity, ?int $userId, ?string $note = null): WarehouseLocationStock
    {
        return DB::transaction(function () use ($variantId, $warehouseId, $locationId, $quantity, $userId, $note) {
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'تعداد باید بزرگ‌تر از صفر باشد.']);
            }

            $this->lockVariantStock($variantId, $warehouseId);
            $location = $this->activeLocation($warehouseId, $locationId, true);
            $unmapped = $this->totalQuantity($variantId, $warehouseId, true) - $this->mappedQuantity($variantId, $warehouseId, true);

            if ($quantity > $unmapped) {
                throw ValidationException::withMessages(['quantity' => 'تعداد وارد شده بیشتر از موجودی بدون مکان این تنوع است.']);
            }

            $stock = $this->lockOrCreateLocationStock($variantId, $warehouseId, $location->id);
            $stock->update(['quantity' => (int) $stock->quantity + $quantity]);

            $this->movement($warehouseId, $variantId, null, $location->id, $quantity, 'initial_mapping', $userId, $note);

            return $stock->fresh('location');
        });
    }

    public function transfer(int $variantId, int $warehouseId, int $fromLocationId, int $toLocationId, int $quantity, ?int $userId, ?string $note = null): void
    {
        DB::transaction(function () use ($variantId, $warehouseId, $fromLocationId, $toLocationId, $quantity, $userId, $note) {
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'تعداد جابه‌جایی باید بزرگ‌تر از صفر باشد.']);
            }
            if ($fromLocationId === $toLocationId) {
                throw ValidationException::withMessages(['to_location_id' => 'مکان مبدا و مقصد نباید یکی باشند.']);
            }

            $this->lockVariantStock($variantId, $warehouseId);
            $this->activeLocation($warehouseId, $fromLocationId, false);
            $this->activeLocation($warehouseId, $toLocationId, true);

            $from = WarehouseLocationStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_variant_id', $variantId)
                ->where('warehouse_location_id', $fromLocationId)
                ->lockForUpdate()
                ->first();

            if (! $from || (int) $from->quantity < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'موجودی مکان مبدا برای این جابه‌جایی کافی نیست.']);
            }

            $to = $this->lockOrCreateLocationStock($variantId, $warehouseId, $toLocationId);
            $from->update(['quantity' => (int) $from->quantity - $quantity]);
            $to->update(['quantity' => (int) $to->quantity + $quantity]);

            $this->movement($warehouseId, $variantId, $fromLocationId, $toLocationId, $quantity, 'transfer', $userId, $note);
        });
    }

    public function increaseLocationStock(int $variantId, int $warehouseId, int $locationId, int $quantity, ?array $reference = null): WarehouseLocationStock
    {
        return DB::transaction(function () use ($variantId, $warehouseId, $locationId, $quantity, $reference) {
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'تعداد باید بزرگ‌تر از صفر باشد.']);
            }
            $this->lockVariantStock($variantId, $warehouseId);
            $this->activeLocation($warehouseId, $locationId, true);
            $stock = $this->lockOrCreateLocationStock($variantId, $warehouseId, $locationId);
            $stock->update(['quantity' => (int) $stock->quantity + $quantity]);
            $this->movement($warehouseId, $variantId, null, $locationId, $quantity, $reference['type'] ?? 'manual_increase', $reference['user_id'] ?? null, $reference['note'] ?? null, $reference);
            return $stock->fresh('location');
        });
    }

    public function decreaseLocationStock(int $variantId, int $warehouseId, int $locationId, int $quantity, ?array $reference = null): WarehouseLocationStock
    {
        return DB::transaction(function () use ($variantId, $warehouseId, $locationId, $quantity, $reference) {
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'تعداد باید بزرگ‌تر از صفر باشد.']);
            }
            $this->lockVariantStock($variantId, $warehouseId);
            $stock = WarehouseLocationStock::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->where('warehouse_location_id', $locationId)->lockForUpdate()->first();
            if (! $stock || (int) $stock->quantity < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'موجودی مکان برای کاهش کافی نیست.']);
            }
            $stock->update(['quantity' => (int) $stock->quantity - $quantity]);
            $this->movement($warehouseId, $variantId, $locationId, null, $quantity, $reference['type'] ?? 'manual_decrease', $reference['user_id'] ?? null, $reference['note'] ?? null, $reference);
            return $stock->fresh('location');
        });
    }

    private function lockVariantStock(int $variantId, int $warehouseId): void
    {
        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            WarehouseStock::create([
                'warehouse_id' => $warehouseId,
                'product_id' => (int) $variant->product_id,
                'product_variant_id' => $variantId,
                'quantity' => max(0, (int) $variant->stock),
            ]);
        }
    }

    private function activeLocation(int $warehouseId, int $locationId, bool $mustBeActive): WarehouseLocation
    {
        $query = WarehouseLocation::query()->where('warehouse_id', $warehouseId)->whereKey($locationId)->lockForUpdate();
        if ($mustBeActive) {
            $query->where('is_active', true);
        }
        $location = $query->first();
        if (! $location) {
            throw ValidationException::withMessages(['warehouse_location_id' => $mustBeActive ? 'مکان انتخاب‌شده فعال یا معتبر نیست.' : 'مکان انتخاب‌شده معتبر نیست.']);
        }
        return $location;
    }

    private function lockOrCreateLocationStock(int $variantId, int $warehouseId, int $locationId): WarehouseLocationStock
    {
        $stock = WarehouseLocationStock::query()->where('warehouse_id', $warehouseId)->where('product_variant_id', $variantId)->where('warehouse_location_id', $locationId)->lockForUpdate()->first();
        return $stock ?: WarehouseLocationStock::create(['warehouse_id' => $warehouseId, 'product_variant_id' => $variantId, 'warehouse_location_id' => $locationId, 'quantity' => 0, 'reserved_quantity' => 0]);
    }

    private function movement(int $warehouseId, int $variantId, ?int $fromLocationId, ?int $toLocationId, int $quantity, string $type, ?int $userId, ?string $note = null, ?array $reference = null): void
    {
        WarehouseLocationMovement::create([
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'quantity' => $quantity,
            'type' => $type,
            'reference_type' => $reference['reference_type'] ?? null,
            'reference_id' => $reference['reference_id'] ?? null,
            'user_id' => $userId,
            'note' => $note,
        ]);
    }
}
