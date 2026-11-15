<?php
namespace App\Console\Commands;
use App\Services\CnyPricingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
class CnyRepriceCommand extends Command {
    protected $signature='cny:reprice {--dry-run : Count eligible yuan products without changing prices}';
    protected $description='Recalculate active yuan sale prices using the latest validated stored quote';
    public function handle(): int {
        try{$dryRun=(bool)$this->option('dry-run');$count=app(CnyPricingService::class)->reprice($dryRun);$this->info(($dryRun?'Eligible yuan products (no changes): ':'Repriced products: ').$count);return self::SUCCESS;}
        catch(ValidationException $e){$this->error(collect($e->errors())->flatten()->implode(' '));return self::FAILURE;}
    }
}
