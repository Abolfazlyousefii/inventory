<?php
namespace App\Support\BugInvestigator\Rules;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
class StockRules { public function run(?int $id=null): array { $f=[];$b=[]; foreach(['products'=>'stock','warehouse_stocks'=>'quantity'] as $table=>$col){ if(!Schema::hasTable($table)){ $f[]="جدول {$table} پیدا نشد."; continue; } $q=DB::table($table)->where($col,'<',0); if($id&&$table==='products')$q->where('id',$id); foreach($q->limit(50)->get(['id',$col]) as $r)$b[]="{$table} #{$r->id} موجودی منفی دارد ({$r->$col})."; } if(!Schema::hasTable('stock_movements')) $f[]='جدول stock_movements برای تطبیق گردش موجودی پیدا نشد.'; return [$f,$b]; } }
