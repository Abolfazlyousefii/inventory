<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CommissionPeriod;
use App\Services\Commissions\CommissionRateAuditService;
use Illuminate\Console\Command;

class AuditCommissionRates extends Command
{
    protected $signature = 'commissions:audit-rates {--period= : Commission period ID} {--category= : Root category ID} {--seller= : Optional seller user ID} {--json}';

    protected $description = 'Read-only audit of effective commission rates for a category tree and period';

    public function handle(CommissionRateAuditService $audit): int
    {
        $period = CommissionPeriod::query()->find($this->option('period'));
        $category = Category::query()->find($this->option('category'));
        if (! $period || ! $category) {
            $this->error('شناسه دوره و دسته معتبر الزامی است.');

            return self::FAILURE;
        }

        $report = $audit->audit($period, $category, $this->option('seller') ? (int) $this->option('seller') : null);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['علت', 'تعداد'], collect($report['counts'])->map(fn ($count, $type) => [$type, $count])->values()->all());
        $this->table(['Product', 'Variant', 'Category path', 'Current rate', 'Source', 'Invoices', 'Missing ledger', 'Classification'], collect($report['rows'])->map(fn ($row) => [
            $row['product_id'].' - '.$row['product_name'], $row['variant_id'] ?? '—', $row['category_path'], $row['current_effective_rate'] ?? '—',
            $row['current_source'] ?? '—', implode(',', $row['invoice_ids']), $row['missing_rate_ledger_count'], $row['classification'],
        ])->all());

        return self::SUCCESS;
    }
}
