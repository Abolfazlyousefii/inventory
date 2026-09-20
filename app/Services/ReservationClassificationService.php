<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use Carbon\CarbonInterface;

/** Canonical, read-only row classification for reservation management. */
class ReservationClassificationService
{
    public const STATE_ACTIVE_VALID = 'active_valid';
    public const STATE_TEMPORARY_ACTIVE = 'temporary_active';
    public const STATE_TEMPORARY_STALE_RELEASABLE = 'temporary_stale_releasable';
    public const STATE_OFFICIAL_ACTIVE = 'official_active';
    public const STATE_CONSUMED = 'consumed';
    public const STATE_RELEASED = 'released';
    public const STATE_LEGACY_SAFE = 'legacy_safe';
    public const STATE_HISTORICAL_AMBIGUOUS = 'historical_ambiguous';
    public const STATE_INVOICE_LINKED = 'invoice_linked';
    public const STATE_INVALID_OFFICIAL = 'invalid_official';

    public const TYPE_TEMPORARY = 'temporary';
    public const TYPE_OFFICIAL = 'official';
    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_CONSUMED = 'consumed';
    public const LIFECYCLE_RELEASED = 'released';
    public const HEALTH_HEALTHY = 'healthy';
    public const HEALTH_WARNING = 'warning';
    public const HEALTH_CRITICAL = 'critical';

    // Transitional aliases keep existing display/filter callers operational.
    public const LABEL_TEMPORARY_ACTIVE = self::STATE_TEMPORARY_ACTIVE;
    public const LABEL_TEMPORARY_ORPHAN = self::STATE_TEMPORARY_STALE_RELEASABLE;
    public const LABEL_OFFICIAL_PREINVOICE = self::STATE_OFFICIAL_ACTIVE;
    public const LABEL_CRITICAL = self::STATE_INVALID_OFFICIAL;
    public const LABEL_LEGACY_CANDIDATE = self::STATE_LEGACY_SAFE;
    public const LABEL_CONSUMED = self::STATE_CONSUMED;

    /** @return array{state:string,reason:string,recommended_action:string,would_change_warehouse_stock:bool,type:string,lifecycle:string,health:string,label:string} */
    public function classify(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        [$state, $reason] = $this->stateAndReason($reservation, $at);
        $action = match ($state) {
            self::STATE_TEMPORARY_STALE_RELEASABLE => 'normal_release_candidate',
            self::STATE_LEGACY_SAFE => 'legacy_cleanup_candidate',
            self::STATE_HISTORICAL_AMBIGUOUS, self::STATE_INVALID_OFFICIAL => 'manual_review',
            self::STATE_CONSUMED => 'already_consumed',
            self::STATE_RELEASED => 'already_released',
            default => 'keep_active',
        };

        return [
            'state' => $state,
            'reason' => $reason,
            'recommended_action' => $action,
            'would_change_warehouse_stock' => $state === self::STATE_TEMPORARY_STALE_RELEASABLE,
            'type' => $this->classifyType($reservation),
            'lifecycle' => $this->classifyLifecycle($reservation),
            'health' => $this->classifyHealth($reservation, $at),
            'label' => $state,
        ];
    }

    /** @return array{string,string} */
    private function stateAndReason(PreinvoiceDraftReservation $reservation, CarbonInterface $at): array
    {
        if ($reservation->released_at !== null || $reservation->release_reason !== null) {
            return [self::STATE_RELEASED, 'reservation_lifecycle_closed'];
        }
        if ($reservation->converted_at !== null) {
            return [self::STATE_CONSUMED, 'reservation_converted_or_consumed'];
        }
        if ((int) $reservation->quantity <= 0) {
            return [self::STATE_HISTORICAL_AMBIGUOUS, 'non_positive_open_quantity'];
        }

        if ($reservation->preinvoice_order_id !== null) {
            $order = $reservation->relationLoaded('order') ? $reservation->getRelation('order') : $reservation->order;
            if ($order === null) {
                return [self::STATE_HISTORICAL_AMBIGUOUS, 'missing_official_relation'];
            }
            $invoice = $order->relationLoaded('invoice') ? $order->getRelation('invoice') : $order->invoice;
            if ($invoice !== null) {
                return [self::STATE_INVOICE_LINKED, 'linked_invoice_exists'];
            }
            if ($order->stock_released_at !== null) {
                return [self::STATE_HISTORICAL_AMBIGUOUS, 'official_stock_already_marked_released'];
            }
            if (in_array($order->status, PreinvoiceOrder::reservationHoldingStatuses(), true) && $order->stock_released_at === null) {
                return [self::STATE_OFFICIAL_ACTIVE, 'preinvoice_status_holds_reservation'];
            }
            if (! $this->isBeyondLegacyBoundary($reservation, $at)) {
                return [self::STATE_INVALID_OFFICIAL, 'official_status_does_not_hold_reservation'];
            }

            return [self::STATE_LEGACY_SAFE, 'inactive_official_preinvoice_with_known_provenance'];
        }

        if ($reservation->hasActiveRelatedDraft()) {
            return [self::STATE_ACTIVE_VALID, 'active_draft_owns_token'];
        }
        if ($reservation->hasValidHeartbeat($at)) {
            return [self::STATE_TEMPORARY_ACTIVE, 'fresh_heartbeat'];
        }
        if ($this->isBeyondLegacyBoundary($reservation, $at)) {
            return [self::STATE_HISTORICAL_AMBIGUOUS, 'old_temporary_provenance_uncertain'];
        }
        if ($reservation->isCleanupCandidate($at)) {
            return [self::STATE_TEMPORARY_STALE_RELEASABLE, 'temporary_heartbeat_stale'];
        }

        return [self::STATE_TEMPORARY_ACTIVE, 'temporary_within_valid_lifetime'];
    }

    public function classifyType(PreinvoiceDraftReservation $reservation): string
    {
        return $reservation->preinvoice_order_id === null ? self::TYPE_TEMPORARY : self::TYPE_OFFICIAL;
    }

    public function classifyLifecycle(PreinvoiceDraftReservation $reservation): string
    {
        if ($reservation->released_at !== null || $reservation->release_reason !== null) return self::LIFECYCLE_RELEASED;
        if ($reservation->converted_at !== null) return self::LIFECYCLE_CONSUMED;
        return self::LIFECYCLE_ACTIVE;
    }

    public function classifyHealth(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): string
    {
        if ($this->classifyLifecycle($reservation) === self::LIFECYCLE_RELEASED) return self::HEALTH_HEALTHY;
        $age = $reservation->preinvoiceAgeHours($at);
        return match (true) {
            $age >= PreinvoiceDraftReservation::PREINVOICE_CRITICAL_AFTER_HOURS => self::HEALTH_CRITICAL,
            $age >= PreinvoiceDraftReservation::PREINVOICE_REVIEW_AFTER_HOURS => self::HEALTH_WARNING,
            default => self::HEALTH_HEALTHY,
        };
    }

    public function classifyManagementLabel(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): string
    {
        return $this->classify($reservation, $at)['state'];
    }

    public function typeLabels(): array { return [self::TYPE_TEMPORARY => 'موقت', self::TYPE_OFFICIAL => 'رسمی']; }
    public function lifecycleLabels(): array { return [self::LIFECYCLE_ACTIVE => 'فعال', self::LIFECYCLE_CONSUMED => 'مصرف‌شده', self::LIFECYCLE_RELEASED => 'آزادشده']; }
    public function healthLabels(): array { return [self::HEALTH_HEALTHY => 'سالم', self::HEALTH_WARNING => 'هشدار', self::HEALTH_CRITICAL => 'بحرانی']; }
    public function managementLabels(): array
    {
        return [
            self::STATE_ACTIVE_VALID => 'فعال محافظت‌شده',
            self::STATE_TEMPORARY_ACTIVE => 'موقت فعال',
            self::STATE_TEMPORARY_STALE_RELEASABLE => 'منقضی واقعی قابل آزادسازی',
            self::STATE_OFFICIAL_ACTIVE => 'رسمی فعال',
            self::STATE_CONSUMED => 'مصرف‌شده',
            self::STATE_RELEASED => 'آزادشده',
            self::STATE_LEGACY_SAFE => 'Legacy قابل پاکسازی',
            self::STATE_HISTORICAL_AMBIGUOUS => 'تاریخی مبهم',
            self::STATE_INVOICE_LINKED => 'متصل به فاکتور',
            self::STATE_INVALID_OFFICIAL => 'رسمی نامعتبر - نیازمند بررسی',
        ];
    }

    private function isBeyondLegacyBoundary(PreinvoiceDraftReservation $reservation, CarbonInterface $at): bool
    {
        $activity = $reservation->last_seen_at ?? $reservation->created_at;
        return $activity !== null && $activity->lte($at->copy()->subHours(PreinvoiceDraftReservation::LEGACY_STALE_HOURS));
    }
}
