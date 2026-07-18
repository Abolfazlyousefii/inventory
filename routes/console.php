<?php

use App\Http\Controllers\PreinvoiceController;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AriyajanebiSyncService;
use App\Services\DefaultProductDesignService;
use App\Services\InventoryWebhookService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;




Artisan::command('preinvoice:repair-reservations {preinvoice_id}', function (int $preinvoice_id) {
    DB::transaction(function () use ($preinvoice_id) {
        $order = PreinvoiceOrder::query()
            ->with('items')
            ->whereKey($preinvoice_id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($order->status, [
            PreinvoiceOrder::STATUS_RESERVED_WAITING_WAREHOUSE,
            PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
            PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            PreinvoiceOrder::STATUS_FINANCE_REVIEWING,
            PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
        ], true) || $order->stock_released_at !== null) {
            $this->warn('This preinvoice does not have an active stock freeze. No reservation was repaired.');
            return;
        }

        app(PreinvoiceController::class)->syncPreinvoiceReservations($order);
        $this->info('Preinvoice reservations were synced.');
    });
})->purpose('Repair active inventory reservations for one preinvoice');

Artisan::command('products:add-default-electric-colors', function () {
    $service = app(DefaultProductDesignService::class);
    $categoryIds = $service->electricCategoryIds();

    if (empty($categoryIds)) {
        $this->warn('Electric category not found. Expected slug "barghijat" or Persian name "برقیجات".');

        return 0;
    }

    $checkedProducts = 0;
    $createdVariants = 0;
    $alreadyExistingColors = 0;

    Product::query()
        ->whereIn('category_id', $categoryIds)
        ->with(['category.parent', 'variants'])
        ->orderBy('id')
        ->chunkById(100, function ($products) use ($service, &$checkedProducts, &$createdVariants, &$alreadyExistingColors) {
            foreach ($products as $product) {
                DB::transaction(function () use ($service, $product, &$checkedProducts, &$createdVariants, &$alreadyExistingColors) {
                    $result = $service->ensureElectricDefaultColors($product, null, null, null);

                    if (! $result['checked']) {
                        return;
                    }

                    $checkedProducts++;
                    $createdVariants += (int) $result['created'];
                    $alreadyExistingColors += (int) $result['already_existing'];
                });
            }
        });

    $this->info("Checked products: {$checkedProducts}");
    $this->info("Created default color variants: {$createdVariants}");
    $this->info("Already existing default colors: {$alreadyExistingColors}");

    return 0;
})->purpose('Add missing black and white default variants to electric category products');

Artisan::command('inventory:set-central-stock {quantity=500 : Quantity to set for every product variant} {--force : Disabled legacy option}', function (int $quantity) {
    $this->error('This legacy bulk stock overwrite command is disabled because inventory must come from documents or approved stock-count corrections.');
    $this->warn('Use php artisan inventory:audit-corruption first, take a backup, then apply a reviewed repair script/SQL only for confirmed corrupted rows.');

    return 1;
})->purpose('Disabled legacy bulk stock overwrite command');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crm:sync-users')
    ->when(fn () => config('crm.sync_enabled'))
    ->everyFifteenMinutes();

Schedule::command('crm:sync-customers')
    ->when(fn () => config('crm.sync_enabled'))
    ->everyThreeMinutes();

Schedule::call(function () {
    InventoryWebhookService::processPending();
    AriyajanebiSyncService::processPending();
})->everyMinute();

Schedule::command('ariya:import-orders')->everyFiveMinutes();
Schedule::command('reservations:expire')->everyFiveMinutes();

Artisan::command('products:audit-variants {product : Product id/code/sku/short_barcode/barcode}', function (string $product) {
    $service = app(\App\Services\ProductVariantStructureService::class);
    $productModel = Product::query()
        ->where('id', $product)
        ->orWhere('code', $product)
        ->orWhere('sku', $product)
        ->orWhere('short_barcode', $product)
        ->orWhere('barcode', $product)
        ->first();

    if (! $productModel) {
        $this->error("Product not found: {$product}");
        return 1;
    }

    $audit = $service->audit($productModel);
    $this->info('Product: ' . ($productModel->code ?: $productModel->sku ?: $productModel->id) . ' - ' . $productModel->name);
    $this->line('Total variants: ' . $audit['total_variants']);
    $this->line('Active variants: ' . $audit['active_variants']);
    $this->line('Valid variants: ' . $audit['valid_variants']);
    $this->line('Invalid variants: ' . $audit['invalid_variants']);
    $this->line('Invalid variants with stock/reserved: ' . $audit['invalid_variants_with_stock']);
    $this->line('Invalid stock total: ' . $audit['invalid_stock_total']);
    $this->line('Invalid reserved total: ' . $audit['invalid_reserved_total']);

    if ($audit['invalid_with_stock']->isNotEmpty()) {
        $this->warn('Invalid variants with stock/reserved:');
        $audit['invalid_with_stock']->take(50)->each(function (ProductVariant $variant) {
            $this->line(sprintf(
                '#%d model_list_id=%s variety_code=%s variant_code=%s stock=%d reserved=%d active=%s',
                $variant->id,
                $variant->model_list_id ?? 'NULL',
                $variant->variety_code ?? 'NULL',
                $variant->variant_code ?? 'NULL',
                (int) $variant->stock,
                (int) $variant->reserved,
                $variant->is_active ? 'yes' : 'no'
            ));
        });
    }

    return 0;
})->purpose('Audit product variants against the current product structure without changing data');

Artisan::command('products:repair-variants {product : Product id/code/sku/short_barcode/barcode} {--dry-run : Show what would be changed} {--apply : Apply the repair}', function (string $product) {
    $service = app(\App\Services\ProductVariantStructureService::class);
    $productModel = Product::query()
        ->where('id', $product)
        ->orWhere('code', $product)
        ->orWhere('sku', $product)
        ->orWhere('short_barcode', $product)
        ->orWhere('barcode', $product)
        ->first();

    if (! $productModel) {
        $this->error("Product not found: {$product}");
        return 1;
    }

    if (! $this->option('dry-run') && ! $this->option('apply')) {
        $this->error('Use --dry-run to preview or --apply to deactivate invalid variants.');
        return 1;
    }

    $audit = $service->audit($productModel);
    $this->info('Product: ' . ($productModel->code ?: $productModel->sku ?: $productModel->id) . ' - ' . $productModel->name);
    $this->line('Invalid variants to deactivate: ' . $audit['invalid_variants']);
    $this->line('Invalid variants with stock/reserved: ' . $audit['invalid_variants_with_stock']);
    $this->line('No variants will be deleted.');

    if ($this->option('dry-run')) {
        $this->warn('Dry-run only. No data changed.');
        return 0;
    }

    $changed = 0;

    DB::transaction(function () use ($service, $productModel, &$changed) {
        $changed = $service->deactivateInvalidVariants($productModel);
        $service->recalculateProductSummary($productModel->refresh());
    });

    $this->info("Deactivated invalid variants: {$changed}");
    $this->info('Product summary recalculated from valid active variants.');

    return 0;
})->purpose('Deactivate invalid product variants without deleting stock/history');

Schedule::command('integration:ariya-site:reconcile-catalog')->dailyAt('02:00')->withoutOverlapping();
Schedule::call(fn () => \App\Models\Integration\IntegrationOutboxEvent::where('status','pending')->where('available_at','<=',now())->limit(100)->pluck('id')->each(fn($id)=>\App\Jobs\Integrations\DeliverAriyaOutboxEventJob::dispatch($id)->onQueue(config('ariya_site.queue','integrations'))))->everyFifteenMinutes()->name('ariya-site-outbox-retry')->withoutOverlapping();
