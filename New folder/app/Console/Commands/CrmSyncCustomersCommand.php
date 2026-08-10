<?php

namespace App\Console\Commands;

use App\Services\CrmCustomerSyncService;
use Illuminate\Console\Command;

class CrmSyncCustomersCommand extends Command
{
    protected $signature = 'crm:sync-customers';

    protected $description = 'Bidirectionally sync customers between CRM and inventory';

    public function handle(CrmCustomerSyncService $service): int
    {
        $result = $service->sync();

        if (!empty($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'CRM customers synced successfully. pulled_created=%d pulled_updated=%d deleted=%d pushed_created=%d pushed_updated=%d failed=%d',
            $result['pulled_created'],
            $result['pulled_updated'],
            $result['deleted'],
            $result['pushed_created'],
            $result['pushed_updated'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
