<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BackupPageAccessCommand extends Command
{
    protected $signature = 'access:backup {--output=}';
    protected $description = 'Create a read-only JSON snapshot before page-access migration';

    public function handle(): int
    {
        $tables = ['permissions','roles','role_has_permissions','model_has_roles','model_has_permissions','user_permissions'];
        if (! collect($tables)->every(fn ($table) => Schema::hasTable($table))) {
            $this->error('All access-control tables must exist; no backup was created.');
            return self::FAILURE;
        }
        $payload = ['created_at'=>now()->toIso8601String(), 'connection'=>DB::connection()->getName(), 'tables'=>[]];
        foreach ($tables as $table) $payload['tables'][$table] = DB::table($table)->orderBy($this->orderColumn($table))->get()->map(fn ($row) => (array) $row)->all();
        $json = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = $this->option('output') ?: storage_path('app/private/access-backups/page-access-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $json);
        File::put($path.'.sha256', hash('sha256', $json).PHP_EOL);
        $this->info('Backup: '.$path);
        $this->info('SHA-256: '.hash('sha256', $json));
        return self::SUCCESS;
    }

    private function orderColumn(string $table): string
    {
        return match ($table) { 'role_has_permissions','model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id', default => Schema::hasColumn($table,'id') ? 'id' : (Schema::hasColumn($table,'user_id') ? 'user_id' : 'permission_id') };
    }
}
