<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Personnel\CrmOrganizationImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportCrmOrganization extends Command
{
    protected $signature='personnel:import-crm-organization {file} {--apply} {--prepare-missing} {--expected-plan=}';
    protected $description='Preview/import CRM reporting hierarchy by immutable CRM user IDs, without auth or financial changes';

    public function handle(CrmOrganizationImport $import): int
    {
        if(!is_file($this->argument('file'))){$this->error('Source file not found.');return self::FAILURE;}
        $payload=json_decode(File::get($this->argument('file')),true,512,JSON_THROW_ON_ERROR);
        $plan=$import->plan($payload);
        $this->line('SOURCE='.$plan['source_count'].' MATCHED='.$plan['matched_count'].' CHANGES='.count($plan['changes']).' ERRORS='.count($plan['errors']).' SELF_ROOTS='.count($plan['normalized_roots']));
        $this->line('PLAN='.$plan['hash']);
        if($this->option('prepare-missing')){
            $count=$import->prepareMissingProfiles($payload,(string)$this->option('expected-plan'));
            $this->info('PREPARED_DISABLED_PROFILES='.$count.'; run a fresh preview before applying hierarchy.');
            return self::SUCCESS;
        }
        if($plan['errors']!==[]){foreach($plan['errors'] as $error)$this->error($error);return self::FAILURE;}
        if(!$this->option('apply')){$this->info('Preview only. No data changed.');return self::SUCCESS;}
        if(!hash_equals($plan['hash'],(string)$this->option('expected-plan'))){$this->error('Review a fresh preview and supply its exact --expected-plan value.');return self::FAILURE;}
        if($plan['changes']===[]){$this->info('Already matches; no changes.');return self::SUCCESS;}
        $dir=storage_path('app/private/personnel-imports/'.gmdate('Ymd-His').'-'.substr($plan['hash'],0,8));
        File::makeDirectory($dir,0700,true);
        File::put($dir.'/plan.json',json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        File::put($dir.'/organization-before.json',json_encode(User::orderBy('id')->get()->map(fn($u)=>['id'=>$u->id]+$u->only(CrmOrganizationImport::FIELDS))->all(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        @chmod($dir.'/plan.json',0600);@chmod($dir.'/organization-before.json',0600);
        $count=$import->apply($payload,$plan['hash']);
        $this->info('APPLIED='.$count.' BACKUP='.$dir);
        return self::SUCCESS;
    }
}
