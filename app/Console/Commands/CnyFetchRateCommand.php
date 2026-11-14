<?php

namespace App\Console\Commands;

use App\Services\ExchangeRates\NavasanRateFetcher;
use Illuminate\Console\Command;
use RuntimeException;

final class CnyFetchRateCommand extends Command
{
    protected $signature = 'cny:fetch-rate';
    protected $description = 'Import one validated Navasan CNY quote (never reprices products)';

    public function handle(NavasanRateFetcher $fetcher): int
    {
        try {
            $result = $fetcher->fetch();
            $this->info('Navasan CNY quote: '.$result['status'].'; ID: '.$result['rate']->id);
            return self::SUCCESS;
        } catch (RuntimeException $e) {
            // Errors contain only curated codes, never requests, URLs or secrets.
            $this->error('Navasan fetch failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
