<?php

namespace App\Console\Commands;

use App\Services\AriyajanebiOrderImportService;
use Illuminate\Console\Command;

class PullNewAriyajanebiOrdersCommand extends Command
{
    protected $signature = 'ariya:pull-new-orders {--show-latest : نمایش آخرین سفارش دریافت‌شده از API} {--show-first : نمایش اولین سفارشِ صفحه اول API}';
    protected $description = 'Pull new orders from Ariyajanebi and create pending warehouse invoices immediately';

    public function handle(AriyajanebiOrderImportService $service): int
    {
        if ($this->option('show-first')) {
            $first = $service->firstOrderSnapshot();
            if (!$first) {
                $error = $service->lastError();
                $this->warn($error ? ('خطا: ' . $error) : 'اولین سفارش از API قابل دریافت نبود.');
                return self::FAILURE;
            }

            $this->line('--- اولین سفارش API (صفحه اول) ---');
            $this->line('id: ' . ($first['id'] ?? '-'));
            if (($first['id'] ?? 0) <= 0) {
                $this->warn('شناسه سفارش معتبر نبود. raw_id: ' . ($first['raw_id'] ?? '-'));
            }
            $this->line('created_at: ' . ($first['created_at'] ?? '-'));
            $this->line('status: ' . ($first['status'] ?? '-'));
            $this->line('total: ' . ($first['total'] ?? '-'));
            $this->line('already_imported: ' . (($first['already_imported'] ?? false) ? 'yes' : 'no'));

            return self::SUCCESS;
        }

        $count = $service->importPendingOrders();

        if ($count > 0) {
            $this->info("{$count} سفارش جدید ثبت شد.");
        } else {
            $error = $service->lastError();
            if ($error) {
                $this->error('ایمپورت انجام نشد: ' . $error);
            } else {
                $this->comment('سفارش جدیدی برای ثبت وجود نداشت.');
            }
        }

        if ($this->option('show-latest')) {
            $latest = $service->latestOrderSnapshot();
            if (!$latest) {
                $this->warn('آخرین سفارش از API قابل دریافت نبود.');
            } else {
                $this->line('--- آخرین سفارش API ---');
                $this->line('id: ' . ($latest['id'] ?? '-'));
                if (($latest['id'] ?? 0) <= 0) {
                    $this->warn('شناسه سفارش معتبر نبود. raw_id: ' . ($latest['raw_id'] ?? '-'));
                }
                $this->line('created_at: ' . ($latest['created_at'] ?? '-'));
                $this->line('status: ' . ($latest['status'] ?? '-'));
                $this->line('total: ' . ($latest['total'] ?? '-'));
                $this->line('already_imported: ' . (($latest['already_imported'] ?? false) ? 'yes' : 'no'));
                $this->line('راهنما: اگر id درست است ولی ایمپورت نمی‌شود، جزئیات سفارش (endpoint /orders/{id}) احتمالاً آیتم خالی برمی‌گرداند.');
            }
        }

        return self::SUCCESS;
    }
}
