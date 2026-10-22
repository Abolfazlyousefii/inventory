<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fresh activity evidence for a single variant, without scanning the log table.
 *
 * Requires migration 2026_10_21_180000_add_indexed_variant_reference_to_activity_logs.
 * properties_variant_id is a VIRTUAL generated column maintained by the DB:
 * it contains properties.variant_id only when the JSON value is an integer.
 * This preserves the previous strict PHP is_int() behavior, without stale caches.
 */
class FreshVariantActivityEvidenceService
{
    /** A generic creation log is not evidence that a person used a variant. */
    public const NEUTRAL_ACTIONS = ['created', 'electric_default_color_created'];

    /**
     * Count non-neutral activity records referring to a variant, counting a
     * record once if it refers through both subject and JSON properties.
     */
    public function count(int $variantId): int
    {
        $direct = (int) $this->directSubjectQuery($variantId)->count();

        $indirect = (int) $this->propertyReferenceQuery($variantId)
            ->where(function (Builder $query) use ($variantId): void {
                // Exclude records already counted in the direct-subject query.
                // Include NULL subject_type / subject_id, like the old PHP scan.
                $query->where('subject_type', '<>', ProductVariant::class)
                    ->orWhereNull('subject_type')
                    ->orWhere('subject_id', '<>', $variantId)
                    ->orWhereNull('subject_id');
            })
            ->count();

        return $direct + $indirect;
    }

    /** Whether any non-neutral activity record refers to a variant. */
    public function exists(int $variantId): bool
    {
        if ($this->directSubjectQuery($variantId)->exists()) {
            return true;
        }

        return $this->propertyReferenceQuery($variantId)->exists();
    }

    /** @return Builder<ActivityLog> */
    private function directSubjectQuery(int $variantId): Builder
    {
        return ActivityLog::query()
            ->whereNotIn('action', self::NEUTRAL_ACTIONS)
            ->where('subject_type', ProductVariant::class)
            ->where('subject_id', $variantId);
    }

    /** @return Builder<ActivityLog> */
    private function propertyReferenceQuery(int $variantId): Builder
    {
        return ActivityLog::query()
            ->whereNotIn('action', self::NEUTRAL_ACTIONS)
            ->where('properties_variant_id', $variantId);
    }
}
