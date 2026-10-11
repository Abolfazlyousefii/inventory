<?php

namespace App\Services;

use App\Models\{Category, Invoice};
use App\Support\SalesDocumentTotals;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/** Read-only projection: historical invoice prices; current central free stock. */
class TopProductsReportService
{
    public function report(array $filters): array
    {
        $categories = !empty($filters['category_id']) ? Category::selfAndDescendantIds((int)$filters['category_id']) : null;
        $query = DB::table('invoices as i')->leftJoin('preinvoice_orders as po', 'po.id', '=', 'i.preinvoice_order_id')
            ->where('i.status', Invoice::STATUS_SHIPPED)->whereNull('i.cancelled_at');
        if (!empty($filters['seller_id'])) $query->whereRaw(Invoice::effectiveSellerSql('i','po').' = ?', [(int)$filters['seller_id']]);
        $undated=(clone $query)->whereNull('i.shipped_at')->whereExists(function ($q) use ($filters,$categories) {
            $q->selectRaw('1')->from('invoice_items as missing_item')->leftJoin('products as missing_product','missing_product.id','=','missing_item.product_id')->whereColumn('missing_item.invoice_id','i.id')->where('missing_item.quantity','>',0);
            if($categories!==null)$q->whereIn('missing_product.category_id',$categories);
            if(!empty($filters['product_id']))$q->where('missing_item.product_id',$filters['product_id']);
        })->count();
        $query->where('i.shipped_at', '>=', $filters['from'].' 00:00:00')
            ->where('i.shipped_at', '<', Carbon::parse($filters['to'])->addDay()->toDateString().' 00:00:00');
        $rows = []; $invoiceIds = []; $invoices = []; $trend = [];
        $query->select('i.*')->chunkById(200, function ($batch) use (&$rows, &$invoiceIds, &$invoices, &$trend, $filters, $categories) {
            $items = DB::table('invoice_items as ii')->leftJoin('products as p', 'p.id','=','ii.product_id')
                ->leftJoin('product_variants as v', function ($j) { $j->on('v.id','=','ii.variant_id')->on('v.product_id','=','ii.product_id'); })
                ->whereIn('ii.invoice_id', $batch->pluck('id'))->orderBy('ii.id')
                ->select('ii.*','p.name','p.image_path','p.category_id','v.variant_name')->get()->groupBy('invoice_id');
            foreach ($batch as $invoice) {
                $invoice->items = $items->get($invoice->id, collect());
                $totals = SalesDocumentTotals::fromDocument($invoice);
                $subtotal = (int)$totals['subtotal_after_product_discount'];
                $extraDiscount = max($subtotal-(int)$totals['subtotal_after_discount'],0);
                $cumulative = 0; $allocated = 0; $invoiceQuantity = 0; $invoiceRevenue = 0;
                foreach ($invoice->items as $item) {
                    $net = SalesDocumentTotals::lineTotal($item);
                    $cumulative += $net;
                    // Cumulative allocation preserves every rial and is independent of filters.
                    $next = $subtotal > 0 ? (int)round($extraDiscount * ($cumulative / $subtotal)) : 0;
                    $revenue = $net - ($next - $allocated); $allocated = $next;
                    if ($item->quantity <= 0 || ($categories !== null && !in_array((int)$item->category_id,$categories,true))
                        || (!empty($filters['product_id']) && (int)$item->product_id !== (int)$filters['product_id'])) continue;
                    $variant = $filters['group'] === 'variant' ? (int)$item->variant_id : 0;
                    $key = $item->product_id.':'.$variant;
                    if (!isset($rows[$key])) $rows[$key] = ['product_id'=>(int)$item->product_id, 'variant_id'=>$variant,
                        'name'=>$item->name ?: 'کالای حذف‌شده #'.$item->product_id,
                        'variant_name'=>$variant ? ($item->variant_name ?: 'تنوع حذف‌شده #'.$variant) : ($filters['group']==='variant'?'بدون تنوع':''),
                        'image_path'=>$item->image_path, 'quantity'=>0, 'revenue'=>0, 'stock'=>null];
                    $rows[$key]['quantity'] += (int)$item->quantity; $rows[$key]['revenue'] += $revenue;
                    $invoiceQuantity += (int)$item->quantity; $invoiceRevenue += $revenue;
                }
                if (!$invoiceQuantity) continue;
                $invoiceIds[$invoice->id] = true;
                $day = substr($invoice->shipped_at,0,10);
                $trend[$day] ??= ['day'=>$day,'quantity'=>0,'revenue'=>0];
                $trend[$day]['quantity'] += $invoiceQuantity; $trend[$day]['revenue'] += $invoiceRevenue;
                if (!empty($filters['product_id'])) $invoices[] = ['id'=>$invoice->id,'uuid'=>$invoice->uuid,'shipped_at'=>$invoice->shipped_at,'quantity'=>$invoiceQuantity,'revenue'=>$invoiceRevenue];
            }
        }, 'i.id', 'id');
        $rows = array_values($rows);
        $central = DB::table('warehouses')->where('type','central')->pluck('id');
        if ($rows && $central->count() === 1) {
            $stock = DB::table('warehouse_stocks as w')->where('warehouse_id',$central->first())
                ->whereIn('w.product_id',array_unique(array_column($rows,'product_id')))
                ->where(function ($q) { $q->whereNotNull('w.product_variant_id')->orWhereNotExists(function ($sub) { $sub->selectRaw('1')->from('product_variants as pv')->whereColumn('pv.product_id','w.product_id'); }); })
                ->selectRaw('w.product_id, COALESCE(w.product_variant_id,0) variant_id, SUM(w.quantity) stock')
                ->groupBy('w.product_id','w.product_variant_id')->get();
            $stocks = []; foreach ($stock as $s) { $key=$s->product_id.':'.($filters['group']==='variant'?$s->variant_id:0); $stocks[$key]=($stocks[$key]??0)+(int)$s->stock; }
            foreach ($rows as &$row) $row['stock']=$stocks[$row['product_id'].':'.$row['variant_id']]??0; unset($row);
        }
        $summary = ['quantity'=>(int)array_sum(array_column($rows,'quantity')), 'revenue'=>(int)array_sum(array_column($rows,'revenue')), 'invoices'=>count($invoiceIds),'items'=>count($rows)];
        $metric = $filters['metric'];
        usort($rows, fn($a,$b)=>($b[$metric]<=>$a[$metric]) ?: ($a['product_id']<=>$b['product_id']) ?: ($a['variant_id']<=>$b['variant_id']));
        foreach ($rows as &$row) $row['share']=$summary[$metric]>0?round($row[$metric]/$summary[$metric]*100,2):0; unset($row);
        ksort($trend);
        return ['rows'=>$rows,'summary'=>$summary,'trend'=>array_values($trend),'invoices'=>$invoices,'stock_available'=>$central->count()===1,'undated_invoices'=>$undated];
    }
}
