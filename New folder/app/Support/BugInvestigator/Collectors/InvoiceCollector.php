<?php
namespace App\Support\BugInvestigator\Collectors;
use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;
class InvoiceCollector { public function collect(?int $id=null): array { if (!Schema::hasTable('invoices')) return ['missing'=>'invoices']; $q=Invoice::query()->withCount('items'); if($id) $q->whereKey($id); return ['records'=>$q->latest()->limit($id?1:20)->get(['id','uuid','preinvoice_order_id','status','created_at'])->toArray()]; } }
