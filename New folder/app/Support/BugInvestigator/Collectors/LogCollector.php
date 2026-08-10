<?php
namespace App\Support\BugInvestigator\Collectors;
class LogCollector { public function collect(): array { $file=storage_path('logs/laravel.log'); if(!is_file($file)) return ['missing'=>'storage/logs/laravel.log']; $lines=@file($file) ?: []; return array_slice(array_map('trim',$lines), -30); } }
