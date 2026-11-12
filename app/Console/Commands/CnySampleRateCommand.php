<?php
namespace App\Console\Commands;
use App\Models\CnyExchangeRate;
use App\Services\CnyPricingService;
use Illuminate\Console\Command;
class CnySampleRateCommand extends Command {
    protected $signature='cny:sample-rate {toman} {--apply : Reprice enabled local products}';
    protected $description='Set an explicitly labelled local sample yuan rate; never calls Navasan';
    public function handle(): int {
        if(!app()->environment(['local','testing']) || !config('cny_pricing.allow_sample') || config('cny_pricing.production_mode') || config('navasan.enabled')){$this->error('Sample rates are allowed only in local sample mode.');return self::FAILURE;}
        $text=(string)$this->argument('toman');
        if(!preg_match('/^\d{1,8}$/D',$text) || (int)$text<1 || (int)$text>10000000){$this->error('Enter a positive Toman rate up to 10,000,000.');return self::FAILURE;}
        CnyExchangeRate::create(['provider'=>'sample','rate_rial'=>(int)$text*10,'quoted_at'=>now()]);
        $this->info('Local SAMPLE rate saved; no external API used.');
        if($this->option('apply'))$this->info('Repriced products: '.app(CnyPricingService::class)->reprice());
        return self::SUCCESS;
    }
}
