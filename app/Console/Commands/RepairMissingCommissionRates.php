<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CommissionPeriod;
use App\Services\Commissions\CommissionHistoricalRateRepairService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class RepairMissingCommissionRates extends Command
{
    protected $signature = 'commissions:repair-missing-rates
        {--period= : Mutable commission period ID}
        {--category= : Root category tree to inspect and repair}
        {--seller= : Optional seller filter for read-only impact analysis}
        {--dry-run : Explicitly request read-only mode}
        {--apply : Apply the tree-aware backdate plan and recalculate the period}';

    protected $description = 'Preview or safely fill leading historical rate gaps while preserving later commission-rate revisions';

    public function handle(CommissionHistoricalRateRepairService $repair): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('--dry-run و --apply هم‌زمان مجاز نیستند.');

            return self::FAILURE;
        }

        if ($this->option('apply') && $this->option('seller')) {
            $this->error('--seller فقط برای گزارش Read-only است؛ Backdate نرخ روی همه فروشندگان اثر دارد و با --apply مجاز نیست.');

            return self::FAILURE;
        }

        $period = CommissionPeriod::query()->find($this->option('period'));
        $category = Category::query()->find($this->option('category'));
        if (! $period || ! $category) {
            $this->error('شناسه دوره و دسته معتبر الزامی است.');

            return self::FAILURE;
        }

        try {
            $plan = $repair->plan(
                $period,
                $category,
                $this->option('seller') ? (int) $this->option('seller') : null,
            );
        } catch (Throwable $exception) {
            $this->error($this->exceptionMessage($exception));

            return self::FAILURE;
        }

        $this->info('Commission historical rate repair preview');
        $this->table(['Field', 'Value'], [
            ['Period', $plan['period_id']],
            ['Root category', $plan['root_category_id'].' - '.$category->name],
            ['Reference at', $plan['reference_at']],
            ['Scanned invoice items', $plan['summary']['scanned_items']],
            ['Repair targets', $plan['summary']['repair_targets']],
            ['Affected invoices', $plan['summary']['candidate_invoices']],
            ['Affected invoice items', $plan['summary']['candidate_items']],
            ['Affected sellers', $plan['summary']['candidate_sellers']],
            ['Historically missing', $plan['summary']['historically_missing_items']],
            ['Wrong fallback / older rule', $plan['summary']['historical_fallback_items']],
            ['Blocked targets', $plan['summary']['blocked_targets']],
            ['Unresolved items', $plan['summary']['unresolved_items']],
        ]);

        if ($plan['targets'] !== []) {
            $this->table(
                ['Target', 'Revision', 'Rate', 'Revision from', 'Revision until', 'State', 'Requested from', 'Invoices', 'Items', 'Missing', 'Fallback', 'Sellers', 'Preflight'],
                collect($plan['targets'])->map(fn (array $target) => [
                    $target['target_key'],
                    '#'.$target['revision_id'],
                    $target['percentage'].'%',
                    $target['current_effective_from'],
                    $target['current_effective_to'] ?? 'OPEN',
                    $target['revision_is_active'] ? 'ACTIVE' : 'HISTORICAL',
                    $target['requested_effective_from'],
                    $target['affected_invoices'],
                    $target['affected_items'],
                    $target['historically_missing_items'],
                    $target['historical_fallback_items'],
                    $target['affected_sellers'],
                    $target['blocked'] ? 'BLOCKED: '.$target['block_reason'] : 'OK',
                ])->all(),
            );
        }

        if ($plan['unresolved'] !== []) {
            $this->warn('نمونه ردیف‌های unresolved (حداکثر 20 مورد):');
            $this->table(
                ['Reason', 'Invoice', 'Item', 'Product', 'Variant', 'Seller', 'Historical', 'Desired'],
                collect($plan['unresolved'])->take(20)->map(fn (array $row) => [
                    $row['reason'],
                    $row['invoice_id'],
                    $row['invoice_item_id'],
                    $row['product_id'],
                    $row['variant_id'] ?? '—',
                    $row['seller_id'] ?? '—',
                    $row['historical_source'],
                    $row['desired_source'],
                ])->all(),
            );
        }

        if (! $this->option('apply')) {
            $this->info('DRY RUN completed; هیچ تغییری در نرخ‌ها، Ledger یا دوره نوشته نشد. پس از بررسی Preview فقط با --apply اجرا کنید.');

            return self::SUCCESS;
        }

        if ($plan['summary']['blocked_targets'] > 0 || $plan['summary']['unresolved_items'] > 0) {
            $this->error('APPLY متوقف شد؛ Preview دارای Target مسدود یا ردیف unresolved است. هیچ تغییری اعمال نشد.');

            return self::FAILURE;
        }

        if ($plan['targets'] === []) {
            $this->info('هیچ Backdate لازم نیست؛ دوره از نظر این درخت نرخ نیازمند Repair نیست.');

            return self::SUCCESS;
        }

        try {
            $result = $repair->repair($period->fresh(), $category);
        } catch (Throwable $exception) {
            $this->error($this->exceptionMessage($exception));

            return self::FAILURE;
        }

        if (! $result['changed']) {
            $this->info('هیچ تغییری لازم نبود.');

            return self::SUCCESS;
        }

        $this->info('Repair با موفقیت Commit شد: Gap ابتدای Timeline با Revision دقیق پر شد، Revisionهای بعدی حفظ شدند، دوره Recalculate و Ledger Verify شد.');

        return self::SUCCESS;
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->implode(' ');
        }

        return $exception->getMessage();
    }
}
