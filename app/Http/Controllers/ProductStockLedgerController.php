<?php
namespace App\Http\Controllers;

use App\Models\{Product, StockMovement, Invoice, Purchase};
use Illuminate\Http\Request;

class ProductStockLedgerController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $data=$request->validate(['variant_id'=>['nullable','integer','min:1']]);
        $selectedVariant=!empty($data['variant_id'])?$product->variants()->whereKey($data['variant_id'])->firstOrFail():null;
        $history=app(\App\Services\ProductStockHistoryService::class)->history($product->id,$selectedVariant?->id);
        $all=$history['rows'];
        $summary=(object)['incoming'=>$all->where('type','in')->sum('quantity'),'outgoing'=>$all->where('type','out')->sum('quantity'),'records'=>$all->count(),'sold'=>$history['sold'],'current'=>$history['current']];
        $page=\Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $movements=new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page,30)->values(),$all->count(),30,$page,['path'=>$request->url()]);
        $movements->appends($selectedVariant?['variant_id'=>$selectedVariant->id]:[]);
        $purchaseIds=[];$invoiceIds=[];
        foreach($movements as $row){if($row->reference_type===Purchase::class&&$row->reference_id)$purchaseIds[$row->id]=(int)$row->reference_id;elseif(str_starts_with((string)$row->reason,'purchase')&&preg_match('/^PUR-(\d+)$/',(string)$row->reference,$m))$purchaseIds[$row->id]=(int)$m[1];if($row->reference_type===Invoice::class&&$row->reference_id)$invoiceIds[$row->id]=(int)$row->reference_id;}
        $purchases=Purchase::whereIn('id',array_values($purchaseIds))->pluck('id','id');$invoices=Invoice::whereIn('id',array_values($invoiceIds))->pluck('uuid','id');$sources=[];
        foreach($movements as $row){$url=null;$label=$row->reference ?: ($row->reference_id ? 'سند #'.$row->reference_id : '—');if(isset($purchaseIds[$row->id])&&$purchases->has($purchaseIds[$row->id])){$url=route('purchases.show',$purchaseIds[$row->id]);$label='خرید #'.$purchaseIds[$row->id];}elseif(isset($invoiceIds[$row->id])&&$invoices->has($invoiceIds[$row->id])){$label='فاکتور '.$invoices[$invoiceIds[$row->id]];$url=route('invoices.show',$invoices[$invoiceIds[$row->id]]);}$sources[$row->id]=['label'=>$label,'url'=>$url];}
        $countRows=$movements->getCollection()->filter(fn($row)=>in_array($row->reference_type,['stock_count_document',\App\Models\StockCountDocument::class],true)&&$row->reference_id);
        if($countRows->isNotEmpty()){
            $counts=\App\Models\StockCountDocument::whereIn('id',$countRows->pluck('reference_id'))->pluck('document_number','id');
            foreach($countRows as $row)if($counts->has($row->reference_id))$sources[$row->id]=['label'=>$counts[$row->reference_id],'url'=>route('stock-count-documents.show',$row->reference_id)];
        }
        $transferRows=$movements->getCollection()->filter(fn($row)=>$row->reference_type===\App\Models\WarehouseTransfer::class&&$row->reference_id);
        if($transferRows->isNotEmpty()){
            $transfers=\App\Models\WarehouseTransfer::whereIn('id',$transferRows->pluck('reference_id'))->pluck('id','id');
            foreach($transferRows as $row)if($transfers->has($row->reference_id))$sources[$row->id]=['label'=>$row->reference ?: 'حواله #'.$row->reference_id,'url'=>route('vouchers.show',$row->reference_id)];
        }
        $receiptRows=$movements->getCollection()->filter(fn($row)=>$row->reference_type===\App\Models\WarehouseInboundReceiptItem::class && $row->reference_id);
        if($receiptRows->isNotEmpty()){
            $receipts=\App\Models\WarehouseInboundReceiptItem::whereIn('id',$receiptRows->pluck('reference_id'))->with('receipt:id,receipt_number')->get()->keyBy('id');
            foreach($receiptRows as $row) if($receipts->get($row->reference_id)?->receipt) {
                $receipt=$receipts[$row->reference_id]->receipt;
                $sources[$row->id]=['label'=>$receipt->receipt_number,'url'=>route('warehouse.inbound.show',$receipt->id)];
            }
        }
        foreach($movements as $row) if(!($row instanceof StockMovement)) $sources[$row->id]=['label'=>$row->reference,'url'=>$row->source_url];
        return view('products.stock-ledger',compact('product','selectedVariant','movements','summary','sources'));
    }
}
