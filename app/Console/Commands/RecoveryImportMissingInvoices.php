<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoveryImportMissingInvoices extends Command
{
    protected $signature = 'recovery:import-missing-invoices
        {--numbers= : Comma separated local invoice numbers to import}
        {--dry-run : Only show what will happen}
        {--apply : Execute import}';

    protected $description = 'Safely import missing invoices from a recovery source database';

    public function handle(): int
    {
        $this->error('This command is a recovery skeleton. Configure source database connection before apply.');
        $this->line('Do not run --apply until source DB mapping is configured.');

        return self::SUCCESS;
    }
}
