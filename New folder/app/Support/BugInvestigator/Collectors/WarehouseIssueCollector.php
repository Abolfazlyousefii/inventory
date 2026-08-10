<?php
namespace App\Support\BugInvestigator\Collectors;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;
class WarehouseIssueCollector { public function collect(?int $id=null): array { if (!Schema::hasTable('invoices')) return ['missing'=>'sales havaleh is represented by invoices.status']; $q=Invoice::query()->withCount('items')->whereNotNull('status'); if($id) $q->whereKey($id); return ['records'=>$q->latest()->limit($id?1:20)->get(['id','uuid','status','status_changed_at','preinvoice_order_id'])->toArray()]; } }
