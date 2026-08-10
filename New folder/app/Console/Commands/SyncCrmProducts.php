<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CrmProductSyncService;

class SyncCrmProducts extends Command
{
    protected $signature = 'products:sync-crm';
    protected $description = 'Sync products from Ariya CRM API into local database';

    public function handle(CrmProductSyncService $service): int
    {
        $res = $service->sync();
        $this->info("Done. created={$res['created']} updated={$res['updated']}");
        return self::SUCCESS;
    }
}
