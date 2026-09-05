<?php

namespace App\Services;

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use Carbon\CarbonInterface;

/**
 * Classification layer for warehouse reservation management.
 *
 * PreinvoiceDraftReservation mixes several concepts into a handful of status
 * methods (businessStatus(), managementStatus(), isCleanupCandidate(), ...).
 * This service separates those concepts into four independent facets — type,
 * lifecycle, health, and a combined management label — for display and
 * reporting purposes only. It creates no new business rules: every rule here
 * reuses an existing model scope, constant, or instance method (isOrphaned(),
 * businessStatus(), legacyCleanupCandidates(), PREINVOICE_*_AFTER_HOURS, ...).
 * Nothing here writes to the database, releases a reservation, or touches
 * physical stock.
 */
class ReservationClassificationService
{
    public const TYPE_TEMPORARY = 'temporary';

    public const TYPE_OFFICIAL = 'official';

    public const LIFECYCLE_ACTIVE = 'active';

    public const LIFECYCLE_CONSUMED = 'consumed';

    public const LIFECYCLE_RELEASED = 'released';

    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_WARNING = 'warning';

    public const HEALTH_CRITICAL = 'critical';

    public const LABEL_TEMPORARY_ACTIVE = 'temporary_active';

    public const LABEL_TEMPORARY_ORPHAN = 'temporary_orphan';

    public const LABEL_OFFICIAL_PREINVOICE = 'official_preinvoice';

    public const LABEL_CRITICAL = 'critical';

    public const LABEL_LEGACY_CANDIDATE = 'legacy_candidate';

    public const LABEL_CONSUMED = 'consumed';

    /**
     * Full classification for one reservation row: type, lifecycle, health,
     * and the combined management label used in the reservation table.
     *
     * @return array{type:string, lifecycle:string, health:string, label:string}
     */
    public function classify(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        return [
            'type' => $this->classifyType($reservation),
            'lifecycle' => $this->classifyLifecycle($reservation),
            'health' => $this->classifyHealth($reservation, $at),
            'label' => $this->classifyManagementLabel($reservation, $at),
        ];
    }

    /**
     * Temporary: preinvoice_order_id is null.
     * Official Preinvoice: reservation is tied to a preinvoice order.
     */
    public function classifyType(PreinvoiceDraftReservation $reservation): string
    {
        return $reservation->preinvoice_order_id === null ? self::TYPE_TEMPORARY : self::TYPE_OFFICIAL;
    }

    /**
     * Released: released_at is set (existing field, unchanged meaning).
     * Consumed: converted_at is set and not released (existing
     * scopeConvertedUnreleased predicate).
     * Active: anything still open.
     */
    public function classifyLifecycle(PreinvoiceDraftReservation $reservation): string
    {
        if ($reservation->released_at !== null) {
            return self::LIFECYCLE_RELEASED;
        }

        if ($reservation->converted_at !== null) {
            return self::LIFECYCLE_CONSUMED;
        }

        return self::LIFECYCLE_ACTIVE;
    }

    /**
     * Age-based monitoring only, reusing the model's existing
     * PREINVOICE_REVIEW_AFTER_HOURS (24h) / PREINVOICE_CRITICAL_AFTER_HOURS
     * (72h) thresholds and its existing preinvoiceAgeHours() helper.
     * Released reservations are no longer being held, so they are reported
     * healthy regardless of age.
     */
    public function classifyHealth(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): string
    {
        if ($reservation->released_at !== null) {
            return self::HEALTH_HEALTHY;
        }

        $ageHours = $reservation->preinvoiceAgeHours($at);

        return match (true) {
            $ageHours >= PreinvoiceDraftReservation::PREINVOICE_CRITICAL_AFTER_HOURS => self::HEALTH_CRITICAL,
            $ageHours >= PreinvoiceDraftReservation::PREINVOICE_REVIEW_AFTER_HOURS => self::HEALTH_WARNING,
            default => self::HEALTH_HEALTHY,
        };
    }

    /**
     * Combined management label shown in the reservation table. Order of
     * precedence mirrors the precedence already used by
     * PreinvoiceDraftReservation::managementStatus()/businessStatus():
     * terminal states first, then the most specific applicable concern.
     */
    public function classifyManagementLabel(PreinvoiceDraftReservation $reservation, ?CarbonInterface $at = null): string
    {
        $at ??= now();

        if ($this->classifyLifecycle($reservation) === self::LIFECYCLE_CONSUMED) {
            return self::LABEL_CONSUMED;
        }

        if ($this->isLegacyCandidate($reservation, $at)) {
            return self::LABEL_LEGACY_CANDIDATE;
        }

        if ($reservation->businessStatus($at) === PreinvoiceDraftReservation::STATUS_CRITICAL) {
            return self::LABEL_CRITICAL;
        }

        if ($this->classifyType($reservation) === self::TYPE_OFFICIAL) {
            return self::LABEL_OFFICIAL_PREINVOICE;
        }

        if ($reservation->isOrphaned($at)) {
            return self::LABEL_TEMPORARY_ORPHAN;
        }

        return self::LABEL_TEMPORARY_ACTIVE;
    }

    /** @return array<string, string> */
    public function typeLabels(): array
    {
        return [
            self::TYPE_TEMPORARY => 'موقت',
            self::TYPE_OFFICIAL => 'پیش‌فاکتور رسمی',
        ];
    }

    /** @return array<string, string> */
    public function lifecycleLabels(): array
    {
        return [
            self::LIFECYCLE_ACTIVE => 'فعال',
            self::LIFECYCLE_CONSUMED => 'مصرف‌شده',
            self::LIFECYCLE_RELEASED => 'آزادشده',
        ];
    }

    /** @return array<string, string> */
    public function healthLabels(): array
    {
        return [
            self::HEALTH_HEALTHY => 'سالم',
            self::HEALTH_WARNING => 'هشدار',
            self::HEALTH_CRITICAL => 'بحرانی',
        ];
    }

    /** @return array<string, string> */
    public function managementLabels(): array
    {
        return [
            self::LABEL_TEMPORARY_ACTIVE => 'موقت فعال',
            self::LABEL_TEMPORARY_ORPHAN => 'موقت رهاشده',
            self::LABEL_OFFICIAL_PREINVOICE => 'پیش‌فاکتور رسمی',
            self::LABEL_CRITICAL => 'بحرانی',
            self::LABEL_LEGACY_CANDIDATE => 'کاندید Legacy',
            self::LABEL_CONSUMED => 'مصرف‌شده',
        ];
    }

    /**
     * Mirrors PreinvoiceDraftReservation::scopeLegacyCleanupCandidates() as a
     * per-row check so a table of already-loaded reservations does not need
     * one query per row. Same predicate, same LEGACY_STALE_HOURS default;
     * see that scope (and LegacyReservationCleanupService, which queries it
     * directly) for the authoritative SQL version of this rule.
     */
    private function isLegacyCandidate(PreinvoiceDraftReservation $reservation, CarbonInterface $at): bool
    {
        if ($reservation->released_at !== null || $reservation->release_reason !== null || $reservation->quantity <= 0) {
            return false;
        }

        $lastActivity = $reservation->last_seen_at ?? $reservation->created_at;
        if ($lastActivity === null) {
            return false;
        }

        $cutoff = $at->copy()->subHours(max(1, PreinvoiceDraftReservation::LEGACY_STALE_HOURS));
        if ($lastActivity->gt($cutoff)) {
            return false;
        }

        if ($reservation->preinvoice_order_id === null) {
            return ! $reservation->hasActiveRelatedDraft();
        }

        $order = $reservation->relationLoaded('order')
            ? $reservation->getRelation('order')
            : $reservation->order;

        if ($order === null) {
            return true;
        }

        $hasInvoice = $order->relationLoaded('invoice')
            ? $order->getRelation('invoice') !== null
            : $order->invoice()->exists();

        if ($hasInvoice) {
            return false;
        }

        return ! in_array($order->status, PreinvoiceOrder::reservationHoldingStatuses(), true);
    }
}
