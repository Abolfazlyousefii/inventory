<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

class AuditProductSalesStatus extends Command
{
    protected $signature = 'product-sales-status:audit {--apply : DEPRECATED: refused; use audited sales-status documents}';

    protected $description = 'Audit product and variant sales-status consistency (dry-run by default)';

    public function handle(): int
    {
        $invalidVariants = ProductVariant::query()->where('is_active', false)->where('sales_enabled', true)->count();
        $badAggregates = Product::query()->where(function ($query): void {
            $query->where(fn ($q) => $q->where('is_sellable', false)->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('sales_enabled', true)))
                ->orWhere(fn ($q) => $q->where('is_sellable', true)->whereDoesntHave('variants', fn ($v) => $v->where('is_active', true)->where('sales_enabled', true)));
        })->count();

        $this->table(['ناسازگاری', 'تعداد'], [['تنوع ساختاری غیرفعال ولی قابل فروش', $invalidVariants], ['وضعیت تجمیعی نادرست کالا', $badAggregates]]);
        if ($this->option('apply')) {
            $this->error('اعمال خودکار وضعیت فروش بدون سند مجاز نیست. از بخش مدیریت وضعیت فروش و سند ثبت‌شده استفاده کنید.');

            return self::FAILURE;
        }

        $this->warn('Dry-run: هیچ داده‌ای تغییر نکرد. برای اصلاح وضعیت فروش، سند مدیریتی ثبت کنید.');

        return self::SUCCESS;
    }
}
