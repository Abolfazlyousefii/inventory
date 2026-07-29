<?php

namespace App\Console\Commands;

use App\Services\CrmUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CrmSyncUsersCommand extends Command
{
    protected $signature = 'crm:sync-users {--full} {--incremental} {--limit=} {--dry-run}';
    protected $description = 'Synchronize ERP users from the canonical CRM users integration';

    public function handle(CrmUserService $service): int
    {
        if ($this->option('full') && $this->option('incremental')) {
            $this->error('--full and --incremental cannot be combined.');
            return self::INVALID;
        }
        if (! config('crm.sync_enabled')) { $this->error('CRM user sync is disabled.'); return self::FAILURE; }
        if (! filled(config('crm.sync.integration_token'))) { $this->error('CRM sync token is not configured.'); return self::FAILURE; }
        $limit = $this->option('limit') !== null ? filter_var($this->option('limit'), FILTER_VALIDATE_INT) : (int) config('crm.sync_limit', 100);
        if (! is_int($limit) || $limit < 1 || $limit > 500) { $this->error('--limit must be between 1 and 500.'); return self::INVALID; }
        $full = (bool) $this->option('full'); // Safe default is incremental; first run promotes itself to full.
        $run = fn () => $service->syncUsers((bool) $this->option('dry-run'), $full, requestedLimit: $limit);

        if ($this->option('dry-run')) return $this->render($run());
        $lock = Cache::lock('crm:users-sync', 3600);
        if (! $lock->get()) { $this->error('CRM users sync is already running.'); return self::FAILURE; }
        try { return $this->render($run()); } finally { $lock->release(); }
    }

    private function render(array $result): int
    {
        foreach (['mode','started_at','updated_since','pages','received','created','updated','unchanged','disabled','access_revoked','sellers_enabled','sellers_disabled','skipped','ambiguous','failed','unknown_roles','managers_resolved','managers_unresolved','finished_at'] as $key) {
            $this->line($key.': '.($result[$key] ?? '-'));
        }
        if ($result['error'] ?? null) { $this->error('Sync failed: '.$result['error']); return self::FAILURE; }
        return self::SUCCESS;
    }
}
