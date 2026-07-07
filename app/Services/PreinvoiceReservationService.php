<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PreinvoiceReservationService
{
    public function expireOverdueReservations(): array
    {
        $orderIds = PreinvoiceDraftReservation::query()
            ->whereNotNull('preinvoice_order_id')
            ->where('reservation_scope', 'official')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('released_at')
            ->whereHas('order', fn ($query) => $query->where('status', PreinvoiceOrder::STATUS_PENDING_FINANCE))
            ->distinct()
            ->pluck('preinvoice_order_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $expiredOrders = 0;
        $releasedReservations = 0;
        $releasedQuantity = 0;

        foreach ($orderIds as $orderId) {
            $order = PreinvoiceOrder::query()->whereKey($orderId)->first();
            if (! $order) {
                continue;
            }

            $result = $this->expirePreinvoiceReservations($order);
            if (($result['expired'] ?? false) === true) {
                $expiredOrders++;
                $releasedReservations += (int) ($result['released_reservations'] ?? 0);
                $releasedQuantity += (int) ($result['released_quantity'] ?? 0);
            }
        }

        return [
            'expired_orders' => $expiredOrders,
            'released_reservations' => $releasedReservations,
            'released_quantity' => $releasedQuantity,
        ];
    }

    public function expirePreinvoiceReservations(PreinvoiceOrder $order, ?User $actor = null, string $reason = 'expired'): array
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $lockedOrder = PreinvoiceOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE || $lockedOrder->invoice()->exists()) {
                return ['expired' => false, 'released_reservations' => 0, 'released_quantity' => 0];
            }

            if (! in_array($lockedOrder->status, [PreinvoiceOrder::STATUS_PENDING_FINANCE, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED], true)) {
                return ['expired' => false, 'released_reservations' => 0, 'released_quantity' => 0];
            }

            $reservations = PreinvoiceDraftReservation::query()
                ->where('preinvoice_order_id', $lockedOrder->id)
                ->where('reservation_scope', 'official')
                ->whereNull('released_at')
                ->where('quantity', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                return ['expired' => false, 'released_reservations' => 0, 'released_quantity' => 0];
            }

            $releasedReservations = 0;
            $releasedQuantity = 0;

            foreach ($reservations as $reservation) {
                $result = $this->releaseReservation($reservation, $actor, $reason, 'رزرو رسمی پیش‌فاکتور منقضی شد.');
                if (($result['released'] ?? false) === true) {
                    $releasedReservations++;
                    $releasedQuantity += (int) ($result['quantity'] ?? 0);
                }
            }

            if ($releasedReservations > 0 && $lockedOrder->status === PreinvoiceOrder::STATUS_PENDING_FINANCE) {
                $oldStatus = $lockedOrder->status;
                $lockedOrder->forceFill([
                    'status' => PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
                    'stock_released_at' => now(),
                    'stock_frozen_until' => null,
                ])->save();

                $lockedOrder->reviews()->create([
                    'user_id' => $actor?->id,
                    'action' => 'reservation_expired',
                    'reason' => 'رزرو موجودی پیش‌فاکتور منقضی و آزاد شد.',
                    'before_items' => ['status' => $oldStatus],
                    'after_items' => ['status' => PreinvoiceOrder::STATUS_RESERVATION_EXPIRED, 'released_quantity' => $releasedQuantity],
                ]);

                ActivityLogger::log('reservation_expired', $lockedOrder->fresh(), 'رزرو موجودی پیش‌فاکتور منقضی و آزاد شد.', [
                    'old_status' => $oldStatus,
                    'new_status' => PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
                    'released_reservations' => $releasedReservations,
                    'released_quantity' => $releasedQuantity,
                    'actor_id' => $actor?->id,
                ]);

                Log::info('Preinvoice reservation expired.', [
                    'preinvoice_order_id' => $lockedOrder->id,
                    'uuid' => $lockedOrder->uuid,
                    'released_reservations' => $releasedReservations,
                    'released_quantity' => $releasedQuantity,
                ]);
            }

            return [
                'expired' => $releasedReservations > 0,
                'released_reservations' => $releasedReservations,
                'released_quantity' => $releasedQuantity,
            ];
        });
    }

    public function releaseReservation(PreinvoiceDraftReservation $reservation, ?User $actor, string $reason, ?string $note = null): array
    {
        return DB::transaction(function () use ($reservation, $actor, $reason, $note) {
            $lockedReservation = PreinvoiceDraftReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedReservation->released_at !== null || (int) $lockedReservation->quantity <= 0) {
                return ['released' => false, 'quantity' => 0];
            }

            $order = PreinvoiceOrder::query()
                ->whereKey((int) $lockedReservation->preinvoice_order_id)
                ->lockForUpdate()
                ->first();

            if (! $order || ! in_array($order->status, [PreinvoiceOrder::STATUS_PENDING_FINANCE, PreinvoiceOrder::STATUS_RESERVATION_EXPIRED], true)) {
                return ['released' => false, 'quantity' => 0];
            }

            if ($order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE || $order->invoice()->exists()) {
                return ['released' => false, 'quantity' => 0];
            }

            if ($lockedReservation->reservation_scope !== 'official') {
                return ['released' => false, 'quantity' => 0];
            }

            $quantity = (int) $lockedReservation->quantity;
            $this->releaseStockForReservation(
                (int) $lockedReservation->product_id,
                (int) $lockedReservation->variant_id,
                $quantity
            );

            $lockedReservation->forceFill([
                'released_at' => now(),
                'released_by' => $actor?->id,
                'release_reason' => $reason,
                'release_note' => $note,
            ])->save();

            return ['released' => true, 'quantity' => $quantity];
        });
    }

    public function assertFinanceApprovable(PreinvoiceOrder $order, ?User $actor = null): void
    {
        if ($order->status === PreinvoiceOrder::STATUS_RESERVATION_EXPIRED) {
            throw ValidationException::withMessages(['preinvoice' => $this->expiredMessage()]);
        }

        if ($order->status !== PreinvoiceOrder::STATUS_PENDING_FINANCE) {
            return;
        }

        $activeReservations = PreinvoiceDraftReservation::query()
            ->where('preinvoice_order_id', $order->id)
            ->where('reservation_scope', 'official')
            ->whereNull('released_at')
            ->where('quantity', '>', 0)
            ->get();

        if ($activeReservations->isEmpty()) {
            throw ValidationException::withMessages(['preinvoice' => 'رزرو فعال برای این پیش‌فاکتور وجود ندارد.']);
        }

        $expired = $activeReservations
            ->filter(fn (PreinvoiceDraftReservation $reservation) => $reservation->expires_at !== null && $reservation->expires_at->lte(now()))
            ->isNotEmpty();

        if ($expired) {
            $this->expirePreinvoiceReservations($order, $actor);
            throw ValidationException::withMessages(['preinvoice' => $this->expiredMessage()]);
        }
    }

    public function expiredMessage(): string
    {
        return 'رزرو این پیش‌فاکتور منقضی شده است و باید توسط فروشنده مجدداً بررسی و ثبت نهایی شود.';
    }

    private function releaseStockForReservation(int $productId, int $variantId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        $variant = ProductVariant::query()
            ->whereKey($variantId)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $variant->product_id !== $productId) {
            throw ValidationException::withMessages(['products' => 'تنوع رزرو شده با کالای پیش‌فاکتور همخوانی ندارد.']);
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        $stock = WarehouseStock::query()
            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = WarehouseStock::query()->create([
                'warehouse_id' => WarehouseStockService::centralWarehouseId(),
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity' => 0,
            ]);
            $stock = WarehouseStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }

        $stock->forceFill(['quantity' => (int) $stock->quantity + $quantity])->save();
        $variant->forceFill(['reserved' => max(0, (int) $variant->reserved - $quantity)])->save();

        if ($product) {
            $product->forceFill(['reserved' => max(0, (int) $product->reserved - $quantity)])->save();
        }

        WarehouseStockService::syncVariantStockFromCentral($variantId);
        WarehouseStockService::syncProductStockFromCentral($productId);
    }
}
