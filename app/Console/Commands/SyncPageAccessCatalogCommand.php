<?php

namespace App\Console\Commands;

use App\Support\PageAccessCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

class SyncPageAccessCatalogCommand extends Command
{
    protected $signature = 'access:sync-page-catalog {--dry-run} {--create-missing}';
    protected $description = 'بررسی یا ایجاد افزایشی Permissionهای صفحه‌ای بدون تغییر انتساب‌ها';

    public function handle(): int
    {
        $existing = Schema::hasTable('permissions') ? DB::table('permissions')->whereIn('key', PageAccessCatalog::permissions())->pluck('key')->all() : [];
        $missing = collect(PageAccessCatalog::pages())->reject(fn ($page) => in_array($page['permission'], $existing, true));
        $this->table(['Permission', 'عنوان', 'وضعیت'], $missing->map(fn ($page) => [$page['permission'], $page['label'], 'ایجاد نشده'])->all());
        if ($this->option('create-missing') && ! $this->option('dry-run')) {
            if (! Schema::hasTable('permissions')) { $this->error('جدول permissions وجود ندارد؛ ابتدا migrationهای پروژه را اجرا کنید.'); return self::FAILURE; }
            DB::transaction(function () use ($missing) {
                foreach ($missing as $page) DB::table('permissions')->insert(['key'=>$page['permission'],'name'=>$page['label'],'group'=>$page['group'],'guard_name'=>'web','created_at'=>now(),'updated_at'=>now()]);
            });
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->info($missing->count().' دسترسی صفحه‌ای ایجاد شد؛ هیچ Role یا User تغییر نکرد.');
        } else $this->info('Dry run: '.$missing->count().' دسترسی صفحه‌ای نیازمند ایجاد است.');
        return self::SUCCESS;
    }
}
