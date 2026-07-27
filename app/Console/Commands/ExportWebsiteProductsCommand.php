<?php

namespace App\Console\Commands;

use App\Services\Exports\WebsiteProductExportService;
use Illuminate\Console\Command;
use Throwable;

class ExportWebsiteProductsCommand extends Command
{
    protected $signature = 'products:export-for-website
        {--include-zero-stock : Include active sellable variants whose free stock is zero}
        {--exclude-zero-price : Exclude variants without an effective positive selling price}
        {--output= : CSV path inside storage/app}
        {--format=csv : Export format (only csv is supported)}
        {--chunk=500 : Number of variants processed per database chunk}';

    protected $description = 'Export active, sellable product variants for the website without changing database data.';

    public function __construct(
        private readonly WebsiteProductExportService $exportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (strtolower(trim((string) $this->option('format'))) !== 'csv') {
            $this->error('فرمت خروجی پشتیبانی نمی‌شود؛ در حال حاضر فقط --format=csv مجاز است.');

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 5000) {
            $this->error('مقدار --chunk باید یک عدد صحیح بین 1 تا 5000 باشد.');

            return self::FAILURE;
        }

        $progressBar = null;

        try {
            $this->components->info('در حال ساخت خروجی محصولات موجود برای سایت...');

            $result = $this->exportService->export([
                'include_zero_stock' => (bool) $this->option('include-zero-stock'),
                'exclude_zero_price' => (bool) $this->option('exclude-zero-price'),
                'output' => $this->option('output'),
                'chunk' => $chunk,
            ], function (int $processed, int $total) use (&$progressBar): void {
                if ($progressBar === null) {
                    $progressBar = $this->output->createProgressBar($total);
                    $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
                    $progressBar->start();
                }

                $progressBar->setProgress(min($processed, $total));
            });

            if ($progressBar !== null) {
                $progressBar->finish();
                $this->newLine(2);
            }

            $this->components->info('خروجی محصولات سایت با موفقیت ساخته شد.');
            $this->table(
                ['شاخص', 'مقدار'],
                [
                    ['مسیر کامل فایل', $result['output_file']],
                    ['فایل خلاصه', $result['summary_file']],
                    ['حجم فایل', $this->humanBytes((int) $result['file_size_bytes'])],
                    ['محصول مادر', number_format((int) $result['products_count'])],
                    ['تنوع خروجی', number_format((int) $result['variants_count'])],
                    ['تنوع موجود', number_format((int) $result['in_stock_count'])],
                    ['تنوع بدون موجودی', number_format((int) $result['zero_stock_count'])],
                    ['تنوع بدون قیمت', number_format((int) $result['zero_price_count'])],
                    ['حذف‌شده به‌علت قیمت صفر', number_format((int) $result['excluded_zero_price_count'])],
                    ['بدون تصویر', number_format((int) $result['missing_image_count'])],
                    ['محصول بدون دسته‌بندی', number_format((int) $result['products_without_category_count'])],
                    ['مغایرت cache موجودی', number_format((int) $result['stock_cache_mismatch_count'])],
                    ['خطا/هشدار داده', number_format((int) $result['errors_count'])],
                    ['مدت اجرا', number_format((float) $result['duration_seconds'], 3).' ثانیه'],
                    ['Peak Memory Usage', $this->humanBytes((int) $result['peak_memory_bytes'])],
                ],
            );

            if ((int) $result['zero_price_count'] > 0) {
                $this->components->warn('برخی تنوع‌های خروجی قیمت معتبر ندارند؛ فایل ساخته شد اما این ردیف‌ها باید بررسی شوند.');
            }
            if ((int) $result['errors_count'] > 0) {
                $this->components->warn('برای جزئیات خطاها و مغایرت‌ها فایل Summary JSON را بررسی کنید.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($progressBar !== null) {
                $progressBar->finish();
                $this->newLine(2);
            }

            $this->components->error('ساخت خروجی ناموفق بود: '.$exception->getMessage());
            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line($exception->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, 2).' '.$unit;
            }
            $value /= 1024;
        }

        return $bytes.' B';
    }
}
