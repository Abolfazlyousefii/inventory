<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Fresh, single-variant ActivityLog evidence.
 *
 * MySQL cannot index `JSON_CONTAINS(properties->variant_id, ?)`, so a fresh
 * per-variant check used to force a full JSON scan inside one statement and
 * tripped `MySQL server has gone away` on production-sized activity tables.
 * This service reads the same evidence in bounded primary-key chunks instead
 * and parses `properties.variant_id` in PHP. It never writes.
 */
class FreshVariantActivityEvidenceService
{
    /**
     * Provenance-neutral actions. The global observer emits a generic `created`
     * row for automatic variants too, so neither action proves manual use.
     */
    public const NEUTRAL_ACTIONS = ['created', 'electric_default_color_created'];

    public const CHUNK_SIZE = 500;

    /**
     * Total non-neutral activity rows referencing the variant, counting a row
     * matched through both the direct subject and `properties.variant_id` once.
     */
    public function count(int $variantId): int
    {
        $count = (int) $this->directSubjectQuery($variantId)->count();
        $this->scanProperties($variantId, false, function () use (&$count): void {
            $count++;
        });

        return $count;
    }

    /** Whether any non-neutral activity row references the variant. */
    public function exists(int $variantId): bool
    {
        if ($this->directSubjectQuery($variantId)->exists()) {
            return true;
        }

        $found = false;
        $this->scanProperties($variantId, true, function () use (&$found): void {
            $found = true;
        });

        return $found;
    }

    /** @return Builder<ActivityLog> */
    private function directSubjectQuery(int $variantId)
    {
        return ActivityLog::query()
            ->whereNotIn('action', self::NEUTRAL_ACTIONS)
            ->where('subject_type', ProductVariant::class)
            ->where('subject_id', $variantId);
    }

    /**
     * Bounded read-only scan for `properties.variant_id` evidence. Rows already
     * counted by the direct-subject query are skipped so counts stay deduplicated.
     */
    private function scanProperties(int $variantId, bool $stopOnFirstMatch, Closure $onMatch): void
    {
        $stoppedEarly = false;
        $complete = ActivityLog::query()
            ->select(['id', 'subject_type', 'subject_id', 'properties'])
            ->whereNotIn('action', self::NEUTRAL_ACTIONS)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($logs) use ($variantId, $stopOnFirstMatch, $onMatch, &$stoppedEarly): ?bool {
                foreach ($logs as $log) {
                    if ($log->subject_type === ProductVariant::class && (int) $log->subject_id === $variantId) {
                        continue;
                    }

                    $properties = $log->properties;
                    if (! is_array($properties)
                        || ! isset($properties['variant_id'])
                        || ! is_int($properties['variant_id'])
                        || $properties['variant_id'] !== $variantId) {
                        continue;
                    }

                    $onMatch();
                    if ($stopOnFirstMatch) {
                        $stoppedEarly = true;

                        return false;
                    }
                }

                return null;
            });

        if (! $complete && ! $stoppedEarly) {
            throw new RuntimeException('ActivityLog fresh evidence scan did not complete.');
        }
    }
}
