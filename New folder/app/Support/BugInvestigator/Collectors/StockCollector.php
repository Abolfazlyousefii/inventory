<?php
namespace App\Support\BugInvestigator\Collectors;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
class StockCollector { public function collect(?int $id=null): array { $out=[]; foreach(['products'=>'stock','warehouse_stocks'=>'quantity'] as $table=>$col){ if(!Schema::hasTable($table)){ $out[$table]='missing'; continue; } $q=DB::table($table); if($id && $table==='products') $q->where('id',$id); $out[$table]=$q->orderBy('id','desc')->limit($id?1:20)->get(['id',$col])->toArray(); } return $out; } }
