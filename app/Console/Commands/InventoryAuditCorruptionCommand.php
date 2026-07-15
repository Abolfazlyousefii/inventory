<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCorruptionAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InventoryAuditCorruptionCommand extends Command
{
    protected $signature = 'inventory:audit-corruption
        {--product= : Audit one product id}
        {--all : Audit all products}
        {--export= : Export CSV path, e.g. storage/app/inventory-audit.csv}';

    protected $description = 'Audit inventory corruption from internal documents without changing data.';

    public function handle(InventoryCorruptionAuditService $audit): int
    {
        $productId = $this->option('product') !== null ? (int) $this->option('product') : null;

        if (! $productId && ! $this->option('all')) {
            $this->error('Use --product=<id> or --all. This command is read-only.');
            return SymfonyCommand::FAILURE;
        }

        $rows = $audit->rows($productId);
        $summary = [
            ['metric' => 'variants_scanned', 'value' => $rows->count()],
            ['metric' => 'corruption_30023_pattern', 'value' => $rows->where('has_corruption_30023_pattern', 'yes')->count()],
            ['metric' => 'no_purchase_but_positive_stock', 'value' => $rows->where('has_no_purchase_but_positive_stock', 'yes')->count()],
            ['metric' => 'rows_with_unmapped_product_level_sale', 'value' => $rows->where('has_unmapped_product_level_sale', 'yes')->count()],
            ['metric' => 'total_unmapped_product_level_sale_qty', 'value' => $rows->unique('product_id')->sum('unmapped_product_level_sale_qty')],
            ['metric' => 'negative_expected_available_conflicts', 'value' => $rows->filter(fn ($row) => (int) $row['expected_available_stock'] < 0)->count()],
        ];

        $this->info('Inventory audit is read-only; no data was changed.');
        $this->table(['metric', 'value'], $summary);

        if ($rows->isNotEmpty()) {
            $this->table(array_keys($rows->first()), $rows->take(200)->all());
        }

        if ($export = $this->option('export')) {
            $this->exportCsv((string) $export, $rows->all());
            $this->info("CSV exported to {$export}");
        }

        return SymfonyCommand::SUCCESS;
    }

    private function exportCsv(string $path, array $rows): void
    {
        $handle = fopen('php://temp', 'w+');
        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        if (str_starts_with($path, 'storage/app/')) {
            Storage::put(substr($path, strlen('storage/app/')), $csv);
            return;
        }

        file_put_contents($path, $csv);
    }
}
