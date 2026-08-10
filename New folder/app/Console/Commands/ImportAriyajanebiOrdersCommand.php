<?php

namespace App\Console\Commands;

use App\Services\AriyajanebiOrderImportService;
use Illuminate\Console\Command;

class ImportAriyajanebiOrdersCommand extends Command
{
    protected $signature = 'ariya:import-orders';
    protected $description = 'Import pending website orders from Ariyajanebi API and create warehouse-pending invoices';

    public function handle(AriyajanebiOrderImportService $service): int
    {
        $count = $service->importPendingOrders();
        $this->info("Imported {$count} order(s).");
        return self::SUCCESS;
    }
}

