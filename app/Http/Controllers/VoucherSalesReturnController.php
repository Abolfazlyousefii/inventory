<?php

namespace App\Http\Controllers;

use App\Exports\SalesReturnsExport;
use App\Http\Requests\{ApplySalesReturnRequest,SalesReturnIndexRequest,StoreSalesReturnRequest,UpdateSalesReturnRequest};
use App\Models\{Category,Customer,Product,ProductVariant,SalesReturnDocument,User,Warehouse,WarehouseTransfer};
use App\Services\{SalesReturnQueryService,SalesReturnService};
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class VoucherSalesReturnController extends Controller
{
 public function __construct(private SalesReturnService $service, private SalesReturnQueryService $reports) {}
 public function index(SalesReturnIndexRequest $request){ $filters=$request->filters()+['status'=>$request->input('status','all')]; $documents=$this->reports->buildDocumentQuery($filters)->with(['items.destinationWarehouse:id,name,type','customer','creator'])->limit(30)->get(); $legacyReturns=WarehouseTransfer::query()->where('voucher_type',WarehouseTransfer::TYPE_CUSTOMER_RETURN)->with(['items.product','items.variant','toWarehouse','customer','user'])->latest()->limit(30)->get(); $returnRows=$this->returnRows($documents,$legacyReturns); return view('vouchers.return-from-sale.index',['returnRows'=>$returnRows,'filters'=>$filters,'warehouses'=>Warehouse::query()->whereIn('type',['central','return'])->orderBy('name')->get(['id','name','type'])]); }
 public function exportExcel(SalesReturnIndexRequest $request){ $filters=$request->filters(); $file='sales-returns-'.now()->format('Ymd-His').'.xlsx'; return Excel::download(new SalesReturnsExport($filters,$this->reports),$file); }
 public function exportPdf(SalesReturnIndexRequest $request){ return $this->pdfResponse('vouchers.return-from-sale.report-pdf',$this->reportData($request),'sales-returns-report-'.now()->format('Ymd-His').'.pdf', 'landscape'); }
 public function printReport(SalesReturnIndexRequest $request){ return view('vouchers.return-from-sale.report-print',$this->reportData($request)); }
 public function create(){ return view('vouchers.return-from-sale.create',$this->formData()); }
 public function store(StoreSalesReturnRequest $request){ $data=$this->enrich($request); $doc=$this->service->createDraft($data, auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc, auth()->id()); return redirect()->route('vouchers.return-from-sale.show',$doc)->with('success','سند برگشت از فروش ذخیره شد.'); }
 public function show(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','items.product','items.variant','creator','applier'); $healthCheck=$this->reports->appliedHealthCheck($document); return view('vouchers.return-from-sale.show',compact('document','healthCheck')); }
 public function edit(SalesReturnDocument $document){ if(!$document->isDraft()) abort(403); $document->load('items'); return view('vouchers.return-from-sale.create',$this->formData()+['document'=>$document]); }
 public function update(UpdateSalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->updateDraft($document,$this->enrich($request),auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc,auth()->id()); return redirect()->route('vouchers.return-from-sale.show',$doc)->with('success','سند بروزرسانی شد.'); }
 public function apply(ApplySalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->apply($document,auth()->id()); return redirect()->route('vouchers.return-from-sale.show',$doc)->with('success',$doc->wasChanged('status')?'سند ثبت نهایی شد.':'این سند قبلاً ثبت نهایی شده است.'); }
 public function cancel(Request $request, SalesReturnDocument $document){ $doc=$this->service->cancelDraft($document,auth()->id(),$request->input('cancel_reason')); return redirect()->route('vouchers.return-from-sale.show',$doc)->with('success','پیش‌نویس لغو شد.'); }
 public function print(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return view('vouchers.return-from-sale.print',compact('document')); }
 public function pdf(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return $this->pdfResponse('vouchers.return-from-sale.document-pdf',compact('document'),'sales-return-'.$document->document_number.'.pdf'); }

 private function returnRows($documents, $legacyReturns)
 {
     $newRows = $documents->map(function (SalesReturnDocument $document) {
         $items = $document->items;
         $itemsSummary = $items->map(fn ($item) => trim(($item->product_name_snapshot ?: $item->product?->name ?: '—').' / '.($item->variant_name_snapshot ?: $item->variant?->variant_name ?: '—').' × '.number_format((int) $item->return_quantity)))->filter()->implode('، ');
         return ['source'=>'new','id'=>$document->id,'number'=>$document->document_number,'date'=>$document->created_at?->format('Y-m-d H:i') ?: '—','customer'=>$document->customer?->display_name ?: '—','items_summary'=>$itemsSummary ?: '—','quantity'=>(int) $document->total_quantity,'return_type'=>SalesReturnDocument::sourceTypeLabels()[$document->source_type] ?? '—','warehouse'=>$items->pluck('destinationWarehouse.name')->filter()->unique()->implode('، ') ?: '—','amount'=>(int) $document->total_refund_amount,'creator'=>$document->creator?->name ?: '—','show_url'=>route('vouchers.return-from-sale.show',$document),'print_url'=>route('vouchers.return-from-sale.print',$document)];
     });
     $legacyRows = $legacyReturns->map(function (WarehouseTransfer $voucher) {
         $items = $voucher->items;
         $itemsSummary = $items->map(fn ($item) => trim(($item->product?->name ?: '—').' / '.($item->variant?->variant_name ?: ($item->variant_name ?: '—')).' × '.number_format((int) $item->quantity)))->filter()->implode('، ');
         return ['source'=>'legacy','id'=>$voucher->id,'number'=>$voucher->reference ?: (string) $voucher->id,'date'=>$voucher->transferred_at?->format('Y-m-d H:i') ?: ($voucher->created_at?->format('Y-m-d H:i') ?: '—'),'customer'=>$voucher->customer?->display_name ?: ($voucher->beneficiary_name ?: '—'),'items_summary'=>$itemsSummary ?: ($voucher->note ?: '—'),'quantity'=>(int) $items->sum('quantity'),'return_type'=>WarehouseTransfer::returnSourceLabel($voucher->return_type),'warehouse'=>$voucher->toWarehouse?->name ?: '—','amount'=>(int) $voucher->total_amount,'creator'=>$voucher->user?->name ?: '—','show_url'=>route('vouchers.show',$voucher),'print_url'=>route('vouchers.show',$voucher)];
     });
     return $newRows->concat($legacyRows)->sortByDesc('date')->values();
 }

 private function reportData(SalesReturnIndexRequest $request): array { $filters=$request->filters(); return ['filters'=>$filters,'documents'=>$this->reports->buildDocumentQuery($filters)->with('items.destinationWarehouse:id,name,type')->limit(1000)->get(),'summary'=>$this->reports->summary($filters),'reportService'=>$this->reports]; }
 private function pdfResponse(string $view,array $data,string $filename,string $orientation='portrait'){ $dompdf=new Dompdf(['isRemoteEnabled'=>true,'defaultFont'=>'DejaVu Sans']); $dompdf->loadHtml(view($view,$data)->render(),'UTF-8'); $dompdf->setPaper('A4',$orientation); $dompdf->render(); return response($dompdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$filename.'"']); }
 private function formData(): array { return ['warehouses'=>Warehouse::where('is_active',true)->whereIn('type',['central','return'])->get(), 'categories'=>Category::orderBy('name')->get(), 'legacyReturnUrl'=>route('vouchers.return-from-sale.index')]; }
 private function enrich(Request $request): array { $data=$request->validated(); $data['can_override_destination']=$request->user()?->can('sales_returns.override_destination') || $request->user()?->hasRole(['admin','Admin']); return $data; }
}
