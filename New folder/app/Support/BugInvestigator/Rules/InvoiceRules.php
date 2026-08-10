<?php
namespace App\Support\BugInvestigator\Rules;
use App\Models\Invoice; use Illuminate\Support\Facades\Schema;
class InvoiceRules { public function run(?int $id=null): array { $f=[];$b=[]; if(!Schema::hasTable('invoices')) return [['table invoices not found'],[]]; $q=Invoice::query()->withCount('items'); if($id)$q->whereKey($id); foreach($q->latest()->limit($id?1:50)->get() as $i){ if($i->preinvoice_order_id && !$i->preinvoiceOrder()->exists())$b[]="فاکتور #{$i->id} به پیش‌فاکتور ناموجود وصل است."; if($i->items_count<1)$b[]="فاکتور/حواله فروش #{$i->id} آیتم ندارد."; if($i->preinvoice_order_id && Invoice::where('preinvoice_order_id',$i->preinvoice_order_id)->count()>1)$b[]="برای پیش‌فاکتور #{$i->preinvoice_order_id} فاکتور تکراری وجود دارد."; } return [$f,$b]; } }
