<?php
namespace App\Support\BugInvestigator\Collectors;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
class FailedJobCollector { public function collect(): array { return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->latest('id')->limit(10)->get(['id','queue','failed_at'])->toArray() : ['missing'=>'failed_jobs']; } }
