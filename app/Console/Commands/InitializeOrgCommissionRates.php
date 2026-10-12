<?php

namespace App\Console\Commands;

use App\Models\OrgCommissionDocument;
use App\Models\User;
use App\Services\Finance\OrgCommissionSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InitializeOrgCommissionRates extends Command
{
    protected $signature = 'org-commission:initialize-rates {document : Approved document number} {--actor= : Administrator user ID} {--apply : Save rates; otherwise preview only}';

    protected $description = 'Initialize missing fixed administrative commission rates from an approved historical document without changing that document.';

    public function handle(OrgCommissionSettingsService $settings): int
    {
        $document = OrgCommissionDocument::where('document_number', $this->argument('document'))->firstOrFail();
        $source = $settings->fromDocument($document);
        foreach ($source['departments'] as $dept) {
            foreach ($dept['members'] as $member) {
                $this->line($dept['name'].' / '.$member['name'].' = '.$member['percentage'].'%');
            }
        }
        if (! $this->option('apply')) {
            $this->info('Preview only; no data changed.');

            return self::SUCCESS;
        }
        $actor = User::findOrFail((int) $this->option('actor'));

        return DB::transaction(function () use ($settings, $document, $actor) {
            $locked = OrgCommissionDocument::lockForUpdate()->findOrFail($document->id);
            $locked->load(['allocations.memberShares']);
            $before = $locked->getAttributes();
            $path = 'private/recoveries/org-commission-rates/'.now()->format('Ymd-His-u').'.json';
            if (! Storage::disk('local')->put($path, json_encode(['source' => $locked->toArray(), 'settings' => $settings->snapshot(true, false)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Backup failed.');
            }
            $settings->initializeFromDocument($locked, $actor->id);
            if ($locked->fresh()->getAttributes() !== $before) {
                throw new \RuntimeException('Historical document changed.');
            }
            $this->info('Fixed rates initialized; source document unchanged. Backup: '.$path);

            return self::SUCCESS;
        });
    }
}
