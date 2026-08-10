<?php

namespace App\Console\Commands;

use App\Services\CrmUserService;
use Illuminate\Console\Command;

class CrmSyncUserChangesCommand extends Command
{
    protected $signature = 'crm:sync-user-changes';

    protected $description = 'Deprecated alias for the timestamp-based incremental users sync';

    public function handle(CrmUserService $service): int
    {
        $this->warn('Deprecated: use crm:sync-users --incremental.');
        $result = $service->syncUsers(full: false);
        if ($result['error'] ?? null) { $this->error('CRM incremental users sync failed.'); return self::FAILURE; }
        $this->info('CRM incremental users sync completed.');
        return self::SUCCESS;
    }
}
