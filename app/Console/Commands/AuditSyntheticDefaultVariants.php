<?php

namespace App\Console\Commands;

use App\Services\SyntheticDefaultVariantAuditService;
use Illuminate\Console\Command;

class AuditSyntheticDefaultVariants extends Command
{
    protected $signature = 'inventory:audit-synthetic-default-variants
        {--product-id= : Limit to one product ID}
        {--variant-id= : Limit to one variant ID}';

    protected $description = 'Read-only audit of legacy synthetic electrical default variants';

    public function handle(SyntheticDefaultVariantAuditService $audit): int
    {
        $productId = $this->option('product-id') !== null ? (int) $this->option('product-id') : null;
        $variantId = $this->option('variant-id') !== null ? (int) $this->option('variant-id') : null;
        $rows = $audit->rows($productId ?: null, $variantId ?: null);
        $headers = [
            'product_id', 'product_name', 'variant_id', 'variant_name', 'variant_code',
            'synthetic_class', 'synthetic_evidence', 'warehouse_stock', 'reserved',
            'purchase_refs', 'invoice_refs', 'preinvoice_refs', 'reservation_refs',
            'stock_movement_refs', 'other_reference_tables', 'site_mapping',
            'safe_to_remove', 'blocking_reasons',
        ];

        $this->line(implode(',', $headers));
        $this->table($headers, $rows->map(function (array $row) use ($headers): array {
            return collect($headers)->map(function (string $header) use ($row): mixed {
                $value = $row[$header] ?? '';

                return is_bool($value) ? ($value ? 'yes' : 'no') : $value;
            })->all();
        })->all());

        $summary = $audit->summary($rows);
        foreach ($summary as $key => $count) {
            $this->line($key.'='.$count);
        }
        $this->info('Read-only audit complete; no data was changed.');

        return self::SUCCESS;
    }
}
