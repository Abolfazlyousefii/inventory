<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditProductSalesStatus extends Command
{
    protected $signature = 'product-sales-status:audit {--apply : Repair aggregate and structurally invalid sales flags}';

    protected $description = 'Audit product and variant sales-status consistency (dry-run by default)';

    public function handle(): int
    {
        $invalidVariants = ProductVariant::query()->where('is_active', false)->where('sales_enabled', true)->count();
        $badAggregates = Product::query()->where(function ($query): void {
            $query->where(fn ($q) => $q->where('is_sellable', false)->whereHas('variants', fn ($v) => $v->where('is_active', true)->where('sales_enabled', true)))
                ->orWhere(fn ($q) => $q->where('is_sellable', true)->whereDoesntHave('variants', fn ($v) => $v->where('is_active', true)->where('sales_enabled', true)));
        })->count();

        $this->table(['ناسازگاری', 'تعداد'], [['تنوع ساختاری غیرفعال ولی قابل فروش', $invalidVariants], ['وضعیت تجمیعی نادرست کالا', $badAggregates]]);
        if (! $this->option('apply')) {
            $this->warn('Dry-run: هیچ داده‌ای تغییر نکرد. برای اصلاح از --apply استفاده کنید.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            ProductVariant::query()->where('is_active', false)->where('sales_enabled', true)->update(['sales_enabled' => false]);
            Product::query()->select('id')->orderBy('id')->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $sellable = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->where('sales_enabled', true)->exists();
                    Product::query()->whereKey($product->id)->update(['is_sellable' => $sellable]);
                }
            });
        });
        $this->info('اصلاح وضعیت فروش انجام شد.');

        return self::SUCCESS;
    }
}
