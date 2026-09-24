<?php

namespace App\Console\Commands;

use App\Services\ElectricProductStructureHealService;
use Illuminate\Console\Command;

class HealElectricProductStructure extends Command
{
    protected $signature = 'inventory:heal-electric-product-structure
        {--ids= : Comma-separated product IDs (required)}
        {--apply : Apply the repair instead of a dry run}
        {--confirm : Required confirmation paired with --apply}';

    protected $description = 'Dry-run-first repair of electrical products whose Base was hidden by stale use_designs metadata';

    public function handle(ElectricProductStructureHealService $heal): int
    {
        $apply = (bool) $this->option('apply');
        $confirm = (bool) $this->option('confirm');
        $raw = trim((string) $this->option('ids'));

        if ($raw === '') {
            $this->error('--ids is required; there is no all-products mode.');

            return self::FAILURE;
        }
        if ($apply !== $confirm) {
            $this->error('Mutation requires both --apply and --confirm.');

            return self::FAILURE;
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($id) => $id !== '');
        if ($parts === [] || collect($parts)->contains(fn ($id) => ! ctype_digit($id) || (int) $id <= 0)) {
            $this->error('--ids must contain positive integer IDs only.');

            return self::FAILURE;
        }
        $ids = collect($parts)->map(fn ($id) => (int) $id)->unique()->values();

        // Conditions are re-checked for every product at run time; apply also
        // revalidates under row locks inside the service.
        $results = $ids->map(fn (int $id) => $apply ? $heal->heal($id) : $heal->assess($id));

        foreach ($results as $result) {
            $this->line('product_id='.$result['product_id'].' code='.$result['code'].' name='.$result['name']);
            $this->line('  status='.$result['status']);
            $this->line('  stock: '.$result['stock_before'].' → '.$result['stock_after']);
            $this->line('  price: '.$result['price_before'].' → '.$result['price_after']);
            $this->line('  base_variant_id='.($result['base_variant_id'] ?? '—')
                .' ('.($result['base_will_activate'] ? 'will be activated' : 'no activation change').')');
            $this->line('  deactivating=['.implode(',', $result['deactivating']).']');
            $this->line('  blockers='.implode(',', $result['blocking_reasons']));
            if ($result['unchecked_references'] !== []) {
                $this->line('  unchecked_missing_tables='.implode(',', $result['unchecked_references']));
            }
        }

        $changing = $results->filter(fn (array $result) => in_array($result['status'], [
            ElectricProductStructureHealService::ELIGIBLE,
            ElectricProductStructureHealService::HEALED,
        ], true));

        $this->line('selected='.$results->count());
        $this->line('eligible='.$changing->count());
        $this->line('healed='.$results->where('status', ElectricProductStructureHealService::HEALED)->count());
        $this->line('total_stock_restored='.$changing->sum(fn (array $result) => max(0, $result['stock_after'] - $result['stock_before'])));
        $this->info($apply ? 'Apply complete.' : 'Dry-run only; no data was changed.');

        return self::SUCCESS;
    }
}
