<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use App\Services\ReturnedAutomaticBundleRecoveryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual, one-pack reconciliation after a canceled invoice was received as a
 * finished SKU while the bundle is now sold from component allocations.
 */
final class ReclassifyReturnedAutomaticBundle extends Command
{
    protected $signature = 'bundle:reclassify-returned
        {bundle_variant_id : Automatic bundle selling variant}
        {source_movement_id : Original invoice_cancel_return stock movement}
        {--recipe-hash= : SHA-256 from preview after inspecting physical components}
        {--physically-verified : Warehouse operator confirms exact current recipe exists inside this returned pack}
        {--operator-user-id= : Existing Inventory user ID accountable for the confirmed physical reconciliation}
        {--apply : Convert this one returned pack; omitted means read-only preview}';

    protected $description = 'Preview or safely reclassify one physically verified returned finished bundle into automatic allocations';

    public function handle(ReturnedAutomaticBundleRecoveryService $service): int
    {
        $bundleVariantId = (int) $this->argument('bundle_variant_id');
        $sourceMovementId = (int) $this->argument('source_movement_id');

        try {
            $plan = $this->option('apply')
                ? $service->apply(
                    $bundleVariantId,
                    $sourceMovementId,
                    (string) ($this->option('recipe-hash') ?? ''),
                    (bool) $this->option('physically-verified'),
                    (int) ($this->option('operator-user-id') ?? 0),
                )
                : $service->preview($bundleVariantId, $sourceMovementId);

            $this->info('Bundle variant: '.$plan['bundle_variant_id']
                .' | returned finished units: '.$plan['finished_stock']
                .' | source stock movement: '.$plan['source_movement_id']
                .' | receipt: '.$plan['source_reference']);
            $variants = ProductVariant::query()->with('product')
                ->whereIn('id', array_keys($plan['recipe']))->get()->keyBy('id');
            $rows = [];
            foreach ($plan['recipe'] as $id => $quantity) {
                $part = $variants->get($id);
                $rows[] = [$id, $part?->product?->name ?? 'Unknown',
                    $part?->variant_name ?? 'Unknown', $quantity];
            }
            $this->table(['Component variant', 'Product', 'Variant', 'Physical units required'], $rows);
            $this->line('Recipe SHA-256: '.$plan['recipe_hash']);

            if ($this->option('apply')) {
                $this->info('One finished SKU unit moved into protected bundle components. No ordinary component stock was deducted.');
            } else {
                $this->warn('READ-ONLY. Verify the REAL returned pack contains exactly the listed units of EVERY component.');
                $this->warn('If it contains old 20-unit components or any mismatch, STOP: repack and audit surplus separately.');
                $this->comment('Only after physical count, independent stock backup and explicit approval,'
                    .' use --apply --physically-verified --operator-user-id=<audited user id> --recipe-hash=<the exact hash above>.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No returned bundle reclassification was performed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
