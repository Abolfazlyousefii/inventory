<?php

namespace App\Services;

use App\Models\IntegrationSyncState;
use App\Models\User;
use App\Services\Crm\CrmUserMapper;
use App\Services\Crm\CrmUserSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CrmUserService
{
    public function __construct(
        private readonly CrmClient $crmClient,
        private readonly CrmUserMapper $mapper,
        private readonly CrmUserSynchronizer $synchronizer,
    ) {}

    public function syncUsers(bool $dryRun = false, bool $full = true, ?string $crmUserId = null, ?int $requestedLimit = null): array
    {
        if ($crmUserId !== null) throw new RuntimeException('Single-user reconciliation is not part of the users integration contract.');
        $started = CarbonImmutable::now('UTC');
        $state = IntegrationSyncState::query()->firstOrNew(['integration' => 'crm', 'stream' => 'users']);
        $updatedSince = null;
        if (! $full && $state->last_succeeded_at) {
            $updatedSince = CarbonImmutable::parse($state->last_succeeded_at)->subSeconds((int) config('crm.sync_overlap_seconds', 120))->utc()->toIso8601String();
        } elseif (! $full) {
            $full = true;
        }
        $stats = $this->stats($full ? 'full' : 'incremental', $started, $updatedSince, $dryRun);
        $limit = min(max($requestedLimit ?? (int) config('crm.sync_limit', 100), 1), 500);
        $cursor = '0';
        $seenCursors = [];
        $seenUsers = [];
        $maxPages = max(1, (int) config('crm.sync.max_pages', 1000));

        if (! $dryRun) {
            $state->fill(['last_started_at' => $started, 'last_error' => null])->save();
        }

        try {
            do {
                if ($stats['pages'] >= $maxPages || isset($seenCursors[$cursor])) throw new RuntimeException('crm_invalid_pagination');
                $seenCursors[$cursor] = true;
                $payload = $this->crmClient->fetchIntegrationUsers($cursor, $limit, $updatedSince, true);
                $rows = $this->mapper->extractUsers($payload);
                $stats['pages']++;
                $stats['received'] += count($rows);

                foreach ($rows as $row) {
                    $data = $this->mapper->map($row); // Invalid data fails the cycle deliberately.
                    $exists = User::query()->where('crm_user_id', $data->crmUserId)->exists();
                    $result = $this->synchronizer->sync($data, $dryRun);
                    $stats[$exists ? 'updated' : 'created']++;
                    $stats['unknown_roles'] += count($result['unknown_roles'] ?? []);
                    $seenUsers[] = $data->crmUserId;
                    if (! $data->isActive) $stats['disabled']++;
                    if (! $data->canAccessErp) $stats['access_revoked']++;
                    $stats[$data->isSeller ? 'sellers_enabled' : 'sellers_disabled']++;
                }

                $hasMore = $payload['has_more'] ?? null;
                $next = $payload['next_cursor'] ?? null;
                if (! is_bool($hasMore)) throw new RuntimeException('crm_invalid_response');
                if ($hasMore && (! is_int($next) && ! ctype_digit((string) $next))) throw new RuntimeException('crm_invalid_pagination');
                if ($hasMore && (string) $next === $cursor) throw new RuntimeException('crm_invalid_pagination');
                $cursor = (string) ($next ?? $cursor);
            } while ($hasMore);

            if (! $dryRun) {
                $stats['managers_resolved'] = $this->synchronizer->reconcileManagers(array_unique($seenUsers));
                $stats['managers_unresolved'] = User::query()->whereIn('crm_user_id', array_unique($seenUsers))
                    ->whereNotNull('manager_crm_user_id')->whereNull('manager_id')->count();
                $state->fill(['last_succeeded_at' => $started, 'last_error' => null, 'metadata' => $stats])->save();
            }
            $stats['finished_at'] = CarbonImmutable::now('UTC')->toIso8601String();
            return $stats + ['error' => null];
        } catch (\Throwable $e) {
            Log::error('CRM users sync failed', ['error_code' => $e->getMessage(), 'error_category' => $e::class]);
            if (! $dryRun) $state->fill(['last_failed_at' => now('UTC'), 'last_error' => mb_substr($e->getMessage(), 0, 500), 'metadata' => $stats])->save();
            return $stats + ['error' => $e->getMessage()];
        }
    }

    public function syncOnePayload(array $payload): array
    {
        return $this->synchronizer->sync($this->mapper->map($payload));
    }

    private function stats(string $mode, CarbonImmutable $started, ?string $since, bool $dryRun): array
    {
        return ['mode' => $mode, 'dry_run' => $dryRun, 'started_at' => $started->toIso8601String(), 'updated_since' => $since,
            'pages' => 0, 'received' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => 0,
            'access_revoked' => 0, 'sellers_enabled' => 0, 'sellers_disabled' => 0, 'skipped' => 0, 'ambiguous' => 0,
            'failed' => 0, 'unknown_roles' => 0, 'managers_resolved' => 0, 'managers_unresolved' => 0];
    }
}
