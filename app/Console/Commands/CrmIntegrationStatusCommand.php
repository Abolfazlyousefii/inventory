<?php

namespace App\Console\Commands;

use App\Models\IntegrationSyncState;
use App\Models\User;
use App\Services\CrmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CrmIntegrationStatusCommand extends Command
{
    protected $signature = 'crm:integration-status {--probe : Probe the canonical users endpoint read-only}';
    protected $description = 'Show sanitized CRM users integration health';

    public function handle(CrmClient $client): int
    {
        $ready = Schema::hasTable('integration_sync_states') && Schema::hasColumn('users', 'can_access_erp') && Schema::hasColumn('users', 'is_seller');
        $state = $ready ? IntegrationSyncState::where(['integration'=>'crm','stream'=>'users'])->first() : null;
        $configured = config('crm.sync_enabled') && filled(config('crm.base_url')) && filled(config('crm.users_endpoint')) && filled(config('crm.sync.integration_token'));
        $probe = 'not probed';
        if ($this->option('probe') && $configured) {
            try { $client->fetchIntegrationUsers('0', 10, null, true); $probe = 'reachable'; }
            catch (\Throwable) { $probe = 'unreachable'; }
        }
        $rows = [
            ['CRM sync enabled', config('crm.sync_enabled') ? 'yes' : 'no'], ['Configuration', $configured ? 'complete' : 'incomplete'],
            ['Users endpoint', (string) config('crm.users_endpoint')], ['SSL verification', config('crm.verify_ssl') ? 'enabled' : 'DISABLED'],
            ['Schema ready', $ready ? 'yes' : 'pending migrations'], ['Endpoint probe', $probe],
            ['Last successful users sync', $state?->last_succeeded_at?->toIso8601String() ?? 'never'],
        ];
        if (Schema::hasColumn('users', 'crm_user_id')) {
            $rows[] = ['CRM-linked users', (string) User::whereNotNull('crm_user_id')->count()];
            $rows[] = ['Duplicate CRM IDs', (string) User::whereNotNull('crm_user_id')->pluck('crm_user_id')->duplicates()->unique()->count()];
        }
        $this->table(['Check','Value'], $rows);
        return $configured && $ready && config('crm.verify_ssl') ? self::SUCCESS : self::FAILURE;
    }
}
