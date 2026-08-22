<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionRateResolver;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairMissingCommissionRates extends Command
{
    protected $signature = 'commissions:repair-missing-rates {--period= : Mutable commission period ID} {--category= : Category rate target ID} {--seller= : Optional seller filter for impact report} {--dry-run : Explicitly request read-only mode} {--apply : Apply the safe backdate and recalculate}';

    protected $description = 'Safely backdate one late category rate to a mutable period start; dry-run unless --apply is supplied';

    public function handle(CommissionRateService $rates, CommissionRateResolver $resolver, CommissionCalculationService $calculation): int
    {
        $period = CommissionPeriod::query()->find($this->option('period'));
        $category = Category::query()->find($this->option('category'));
        if (! $period || ! $category) {
            $this->error('شناسه دوره و دسته معتبر الزامی است.');

            return self::FAILURE;
        }
        if (! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
            $this->error('Repair فقط برای دوره باز یا در حال بررسی مجاز است.');

            return self::FAILURE;
        }

        $revision = CommissionRateRevision::query()->where('target_key', CommissionTarget::key('category', $category->id))->where('active_marker', 1)->first();
        if (! $revision || $revision->effective_from->lte($period->start_at)) {
            $this->error('این دسته نرخ فعالِ دیرشروع‌شده‌ای برای Repair ندارد.');

            return self::FAILURE;
        }

        $categoryIds = Category::selfAndDescendantIds($category->id);
        $items = InvoiceItem::query()->with(['invoice.preinvoiceOrder', 'product.category', 'variant'])->whereHas('product', fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->whereHas('invoice', fn ($query) => $query->whereRaw('COALESCE(document_date, created_at) >= ?', [$period->start_at])->whereRaw('COALESCE(document_date, created_at) < ?', [$period->end_at]))
            ->get()
            ->when($this->option('seller'), fn ($collection) => $collection->filter(fn ($item) => (int) $item->invoice->effective_seller_id === (int) $this->option('seller')))
            ->filter(fn ($item) => $item->invoice->display_document_date->lt($revision->effective_from)
                && $resolver->resolve($item->product, $item->variant, $item->invoice->display_document_date)->isMissing);
        if ($items->isEmpty()) {
            $this->error('هیچ ردیف فاقد نرخی که مشخصاً از شروع دیرهنگام این دسته ناشی شده باشد پیدا نشد.');

            return self::FAILURE;
        }
        $impact = [
            ['Target', $revision->target_key],
            ['Current percentage', $revision->percentage.'%'],
            ['Current effective_from', $revision->effective_from->toDateTimeString()],
            ['Requested effective_from', $period->start_at->toDateTimeString()],
            ['Affected invoices', $items->pluck('invoice_id')->unique()->count()],
            ['Affected invoice items', $items->count()],
            ['Affected sellers', $items->map(fn ($item) => $item->invoice->effective_seller_id)->filter()->unique()->count()],
        ];
        $this->table(['Field', 'Value'], $impact);

        if (! $this->option('apply')) {
            $this->info('Dry run completed; no database mutation was performed. Use --apply explicitly to repair.');

            return self::SUCCESS;
        }

        $actor = User::query()->find($revision->created_by);
        if (! $actor) {
            $this->error('کاربر ثبت‌کننده revision موجود نیست؛ Repair متوقف شد.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($rates, $calculation, $category, $period, $actor) {
                $rates->backdateActiveRate('category', $category->id, $period->start_at, $actor);
                $calculation->recalculate($period->fresh());
            });
        } catch (\Throwable $exception) {
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->implode(' ')
                : $exception->getMessage();
            $this->error($message);

            return self::FAILURE;
        }

        $this->info('Rate backdated safely and the mutable period recalculated through CommissionCalculationService.');

        return self::SUCCESS;
    }
}
