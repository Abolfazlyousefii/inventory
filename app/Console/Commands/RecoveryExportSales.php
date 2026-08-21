<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

class RecoveryExportSales extends Command
{
    protected $signature = 'recovery:export-sales {--from=2026-08-20 09:00:00}';
    protected $description = 'Export local sales documents after recovery cutoff';

    public function handle(): int
    {
        $from = $this->option('from');

        $invoices = Invoice::with(['items','payments','customer'])
            ->where('created_at','>=',$from)
            ->get();

        $path = 'recovery/sales_'.now()->format('Ymd_His').'.json';

        Storage::disk('local')->put($path, json_encode([
            'created_at'=>now(),
            'from'=>$from,
            'invoices'=>$invoices,
        ], JSON_UNESCAPED_UNICODE));

        $this->info('Created: storage/app/'.$path);
        $this->info('Invoices: '.$invoices->count());

        return self::SUCCESS;
    }
}
