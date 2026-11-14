<?php

namespace App\Console\Commands;

use App\Models\CnyExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Read-only: never prints secrets, and never calls an exchange API. */
final class CnyNobitexStatusCommand extends Command
{
    protected $signature = 'cny:nobitex-status';
    protected $description = 'Show the Nobitex CNY proxy configuration and last saved rate';

    public function handle(): int
    {
        $this->line('Nobitex fetch enabled: '.(config('nobitex.enabled') ? 'yes' : 'no'));
        $this->line('Nobitex schedule enabled: '.(config('nobitex.schedule_enabled') ? 'yes' : 'no'));
        $this->line('Reprice schedule enabled: '.(config('cny_pricing.schedule_enabled') ? 'yes' : 'no'));
        $this->line('USD/CNY reference: automatic Frankfurter daily publication');
        $this->line('FX publication max age: '.config('nobitex.max_fx_age_hours').' hours');
        $this->line('Scheduled times: 09:00, 12:00, 17:00 Asia/Tehran');
        $this->warn('Source is USDT/IRT, NOT a direct Chinese yuan market quote.');
        if (!Schema::hasTable('cny_exchange_rates')) {
            $this->warn('CNY rates table does not exist; run approved migrations first.');
            return self::FAILURE;
        }
        $rate = CnyExchangeRate::where('provider', 'nobitex_usdt_proxy')->where('symbol', 'cny')
            ->orderByDesc('quoted_at')->orderByDesc('id')->first();
        if (!$rate) {
            $this->warn('No accepted Nobitex-derived quote.');
        } else {
            $this->line('Last proxy rate: ID '.$rate->id.', '.$rate->rate_rial
                .' IRR/CNY, orderbook updated '.$rate->quoted_at->toIso8601String());
            $this->line('Rate freshness: '.($rate->quoted_at->gte(now()->subMinutes(
                (int) config('cny_pricing.max_rate_age_minutes', 30))) ? 'within limit' : 'stale'));
        }
        return self::SUCCESS;
    }
}
