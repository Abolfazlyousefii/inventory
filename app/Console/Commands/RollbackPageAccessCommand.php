<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RollbackPageAccessCommand extends Command
{
    protected $signature = 'access:rollback {--from= : Backup JSON path} {--confirm : Confirm destructive assignment restoration}';
    protected $description = 'Restore access assignments and remove page permissions created after a backup';

    public function handle(): int
    {
        $path = (string) $this->option('from');
        if (!$this->option('confirm') || $path === '' || !File::isFile($path) || !File::isFile($path.'.sha256')) {
            $this->error('A valid --from backup and --confirm are required.');
            return self::INVALID;
        }
        $json = File::get($path);
        if (!hash_equals(trim(File::get($path.'.sha256')), hash('sha256',$json))) { $this->error('Backup checksum mismatch.'); return self::FAILURE; }
        $backup = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($backup) {
            foreach (['role_has_permissions','model_has_roles','model_has_permissions','user_permissions'] as $table) {
                DB::table($table)->delete();
                foreach (array_chunk($backup['tables'][$table] ?? [], 500) as $rows) if ($rows !== []) DB::table($table)->insert($rows);
            }
            $originalPageIds = collect($backup['tables']['permissions'])->filter(fn ($row) => str_starts_with((string)($row['key'] ?? ''),'page.'))->pluck('id');
            DB::table('permissions')->where('key','like','page.%')->when($originalPageIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id',$originalPageIds))->delete();
        });
        $this->info('Access assignments restored; legacy and direct permissions were preserved from the snapshot.');
        return self::SUCCESS;
    }
}
