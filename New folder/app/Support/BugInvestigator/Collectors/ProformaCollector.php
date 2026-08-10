<?php
namespace App\Support\BugInvestigator\Collectors;
use App\Models\PreinvoiceOrder;
use Illuminate\Support\Facades\Schema;
class ProformaCollector { public function collect(?int $id=null): array { if (!Schema::hasTable('preinvoice_orders')) return ['missing'=>'preinvoice_orders']; $q=PreinvoiceOrder::query()->withCount('items'); if($id) $q->whereKey($id); return ['records'=>$q->latest()->limit($id?1:20)->get(['id','uuid','status','created_at','warehouse_reviewed_at'])->toArray()]; } }
