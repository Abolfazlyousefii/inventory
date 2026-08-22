<?php

namespace App\Console\Commands;

use App\Support\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'همگام‌سازی فهرست دسترسی‌های نرم‌افزار با پایگاه داده';

    public function handle(): int
    {
        $result = PermissionCatalog::syncToDatabase();

        $this->components->info('فهرست دسترسی ها با موفقیت همگام شد.');
        $this->table(['Legacy permissions', 'Page permissions', 'ایجادشده', 'به روزشده', 'بدون تغییر'], [[
            $result['legacy'],
            $result['pages'],
            $result['created'],
            $result['updated'],
            $result['unchanged'],
        ]]);
        $this->components->info('هیچ نقش یا دسترسی مستقیم کاربری تغییر نکرد.');

        return self::SUCCESS;
    }
}
