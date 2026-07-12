<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;

class SalesReturnCalculationService
{
    public function allocateInvoiceDiscount(Invoice $invoice): array
    {
        $items = $invoice->items()->orderBy('id')->get();
        $gross = $items->mapWithKeys(fn($i)=>[$i->id => (int)$i->quantity * (int)$i->price]);
        $grossTotal = (int)$gross->sum();
        $lineDiscountSum = (int)$items->sum(fn($i)=>(int)($i->line_discount_amount ?? 0));
        $extra = max((int)($invoice->discount_amount ?? 0) - $lineDiscountSum, 0);
        if ($extra <= 0 || $grossTotal <= 0) return $items->mapWithKeys(fn($i)=>[$i->id=>0])->all();
        $alloc=[]; $used=0;
        foreach ($items as $item) { $v=(int)floor($extra * ($gross[$item->id] ?? 0) / $grossTotal); $alloc[$item->id]=$v; $used += $v; }
        $rem = $extra - $used;
        foreach ($items as $item) { if ($rem-- <= 0) break; $alloc[$item->id]++; }
        return $alloc;
    }

    public function previouslyReturnedQuantities(array $invoiceItemIds): array
    {
        if (!$invoiceItemIds) return [];
        $new = DB::table('sales_return_document_items as i')->join('sales_return_documents as d','d.id','=','i.document_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)->whereIn('i.invoice_item_id',$invoiceItemIds)
            ->groupBy('i.invoice_item_id')->pluck(DB::raw('sum(i.return_quantity)'), 'i.invoice_item_id')->all();
        $legacy = DB::table('warehouse_transfer_items as wi')->join('warehouse_transfers as wt','wt.id','=','wi.warehouse_transfer_id')
            ->where('wt.voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)->whereNotNull('wi.invoice_item_id')->whereIn('wi.invoice_item_id',$invoiceItemIds)
            ->groupBy('wi.invoice_item_id')->pluck(DB::raw('sum(wi.quantity)'), 'wi.invoice_item_id')->all();
        $out=[]; foreach ($invoiceItemIds as $id) $out[$id]=(int)($new[$id]??0)+(int)($legacy[$id]??0); return $out;
    }
    public function previouslyReturnedAmounts(array $invoiceItemIds): array
    {
        if (!$invoiceItemIds) return [];
        return DB::table('sales_return_document_items as i')->join('sales_return_documents as d','d.id','=','i.document_id')
            ->where('d.status', SalesReturnDocument::STATUS_APPLIED)->whereIn('i.invoice_item_id',$invoiceItemIds)
            ->groupBy('i.invoice_item_id')->pluck(DB::raw('sum(i.refund_amount)'), 'i.invoice_item_id')->map(fn($v)=>(int)$v)->all();
    }

    public function calculateInternalPreview(Invoice $invoice, array $rows): array
    {
        $invoice->loadMissing('items.product','items.variant'); $alloc=$this->allocateInvoiceDiscount($invoice); $ids=$invoice->items->pluck('id')->all();
        $prevQty=$this->previouslyReturnedQuantities($ids); $prevAmt=$this->previouslyReturnedAmounts($ids); $byId=$invoice->items->keyBy('id'); $result=[];
        foreach ($rows as $idx=>$row) { $id=(int)($row['invoice_item_id']??0); $item=$byId->get($id); if(!$item) continue; $qty=(int)($row['return_quantity']??0); if($qty<=0) continue;
            $sold=(int)$item->quantity; $returned=(int)($prevQty[$id]??0); $returnable=max($sold-$returned,0); $qty=min($qty,$returnable);
            $gross=$sold*(int)$item->price; $lineDisc=(int)($item->line_discount_amount??0); $net=max($gross-$lineDisc-(int)($alloc[$id]??0),0);
            $amount = $qty >= $returnable ? max($net-(int)($prevAmt[$id]??0),0) : (int)floor($net*$qty/max($sold,1));
            $result[]=['invoice_item'=>$item,'quantity'=>$qty,'previous_quantity'=>$returned,'returnable_quantity'=>$returnable,'allocated_discount'=>(int)($alloc[$id]??0),'refund_amount'=>$amount,'refund_unit_price'=>$qty>0?(int)floor($amount/$qty):0]; }
        return $result;
    }
    public function calculateSazehPreview(array $rows): array { return collect($rows)->map(fn($r)=>['quantity'=>(int)($r['return_quantity']??0),'refund_unit_price'=>(int)($r['refund_unit_price']??0),'refund_amount'=>(int)($r['return_quantity']??0)*(int)($r['refund_unit_price']??0)])->all(); }
}
