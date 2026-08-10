<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupLegacyAccessCommand extends Command
{
    protected $signature = 'access:cleanup-legacy {--dry-run}';
    protected $description = 'فقط گزارش Permissionهای قدیمی؛ حذف واقعی عمداً پشتیبانی نمی‌شود';
    public function handle(): int { $count=Schema::hasTable('permissions')?DB::table('permissions')->where('key','not like','page.%')->count():0;$this->warn("Dry run: {$count} Permission قدیمی حفظ می‌شود و هیچ داده‌ای حذف نشد.");return self::SUCCESS; }
}
