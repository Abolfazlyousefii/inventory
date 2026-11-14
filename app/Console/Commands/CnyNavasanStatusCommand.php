<?php

namespace App\Console\Commands;

use App\Models\CnyExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only operational check. Intentionally never prints the API key or URL. */
final class CnyNavasanStatusCommand extends Command
{
    protected $signature = 'cny:navasan-status';
    protected $description = 'Show safe CNY rate/rolling quota status without calling the API';

    public function handle(): int
    {
        $this->line('Navasan fetch enabled: '.(config('navasan.enabled') ? 'yes' : 'no'));
        $this->line('Fetch schedule enabled: '.(config('navasan.schedule_enabled') ? 'yes' : 'no'));
        $this->line('Reprice schedule enabled: '.(config('cny_pricing.schedule_enabled') ? 'yes' : 'no'));
        $this->line('Configured response unit: '.config('navasan.response_unit'));
        if (!Schema::hasTable('cny_exchange_rates') || !Schema::hasTable('navasan_api_requests')) {
            $this->warn('Required exchange-rate migration has not been run.');
            return self::FAILURE;
        }
        $used = DB::table('navasan_api_requests')->where('attempted_at', '>', now()->subDays(31))->count();
        $limit = (int) config('navasan.monthly_rolling_limit');
        $this->line("API attempts in last 31 days: {$used}/{$limit}");
        $lastAttempt = DB::table('navasan_api_requests')->orderByDesc('id')->first();
        if ($lastAttempt) {
            $this->line('Last request: '.$lastAttempt->attempted_at.' / '.$lastAttempt->status
                .($lastAttempt->failure_code ? ' / '.$lastAttempt->failure_code : ''));
        }
        $rate = CnyExchangeRate::where('provider', 'navasan')->where('symbol', 'cny')
            ->orderByDesc('quoted_at')->first();
        if (!$rate) {
            $this->warn('No accepted Navasan CNY quote.');
        } else {
            $this->line('Last accepted quote: ID '.$rate->id.', '.$rate->rate_rial
                .' IRR/CNY, quoted '.$rate->quoted_at->toIso8601String());
            $this->line('Quote freshness: '.($rate->quoted_at->gte(now()->subMinutes(
                (int) config('cny_pricing.max_rate_age_minutes', 30))) ? 'within limit' : 'stale'));
        }
        return self::SUCCESS;
    }
}
