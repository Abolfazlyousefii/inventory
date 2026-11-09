<?php
namespace App\Console\Commands;
use App\Services\CnyPricingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
class CnyRepriceCommand extends Command {
    protected $signature='cny:reprice';
    protected $description='Recalculate active yuan sale prices using the latest validated stored quote';
    public function handle(): int {
        try{$this->info('Repriced products: '.app(CnyPricingService::class)->reprice());return self::SUCCESS;}
        catch(ValidationException $e){$this->error(collect($e->errors())->flatten()->implode(' '));return self::FAILURE;}
    }
}
