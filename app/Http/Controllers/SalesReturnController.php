<?php

namespace App\Http\Controllers;

use App\Exports\SalesReturnsExport;
use App\Http\Requests\{ApplySalesReturnRequest,SalesReturnIndexRequest,StoreSalesReturnRequest,UpdateSalesReturnRequest};
use App\Models\{Category,Customer,Product,ProductVariant,SalesReturnDocument,User,Warehouse,WarehouseTransfer};
use App\Services\{SalesReturnQueryService,SalesReturnService};
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SalesReturnController extends Controller
{
 public function __construct(private SalesReturnService $service, private SalesReturnQueryService $reports) {}
 public function index(SalesReturnIndexRequest $request){ $filters=$request->filters()+['status'=>$request->input('status','all')]; $documents=$this->reports->buildDocumentQuery($filters)->with(['items.destinationWarehouse:id,name,type'])->paginate((int)($filters['per_page']??30))->withQueryString(); return view('sales-returns.index',['documents'=>$documents,'filters'=>$filters,'summary'=>$this->reports->summary($filters),'tabCounts'=>$this->reports->tabCounts($filters),'warehouses'=>Warehouse::query()->whereIn('type',['central','return'])->orderBy('name')->get(['id','name','type']),'customers'=>Customer::query()->when($filters['customer_id']??null,fn($q,$id)=>$q->whereKey($id))->get(['id','first_name','last_name','mobile']),'products'=>Product::query()->when($filters['product_id']??null,fn($q,$id)=>$q->whereKey($id))->get(['id','name']),'variants'=>ProductVariant::query()->when($filters['product_variant_id']??null,fn($q,$id)=>$q->whereKey($id))->get(['id','variant_name','variant_code']),'users'=>User::query()->whereIn('id',array_filter([$filters['created_by']??null,$filters['applied_by']??null]))->get(['id','name']),'reportService'=>$this->reports]); }
 public function exportExcel(SalesReturnIndexRequest $request){ $filters=$request->filters(); $file='sales-returns-'.now()->format('Ymd-His').'.xlsx'; return Excel::download(new SalesReturnsExport($filters,$this->reports),$file); }
 public function exportPdf(SalesReturnIndexRequest $request){ return $this->pdfResponse('sales-returns.report-pdf',$this->reportData($request),'sales-returns-report-'.now()->format('Ymd-His').'.pdf', 'landscape'); }
 public function printReport(SalesReturnIndexRequest $request){ return view('sales-returns.report-print',$this->reportData($request)); }
 public function create(){ return view('sales-returns.create',$this->formData()); }
 public function store(StoreSalesReturnRequest $request){ $data=$this->enrich($request); $doc=$this->service->createDraft($data, auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc, auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success','سند برگشت از فروش ذخیره شد.'); }
 public function show(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','items.product','items.variant','creator','applier'); $healthCheck=$this->reports->appliedHealthCheck($document); return view('sales-returns.show',compact('document','healthCheck')); }
 public function edit(SalesReturnDocument $document){ if(!$document->isDraft()) abort(403); $document->load('items'); return view('sales-returns.create',$this->formData()+['document'=>$document]); }
 public function update(UpdateSalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->updateDraft($document,$this->enrich($request),auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc,auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success','سند بروزرسانی شد.'); }
 public function apply(ApplySalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->apply($document,auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success',$doc->wasChanged('status')?'سند ثبت نهایی شد.':'این سند قبلاً ثبت نهایی شده است.'); }
 public function cancel(Request $request, SalesReturnDocument $document){ $doc=$this->service->cancelDraft($document,auth()->id(),$request->input('cancel_reason')); return redirect()->route('sales-returns.show',$doc)->with('success','پیش‌نویس لغو شد.'); }
 public function print(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return view('sales-returns.print',compact('document')); }
 public function pdf(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return $this->pdfResponse('sales-returns.document-pdf',compact('document'),'sales-return-'.$document->document_number.'.pdf'); }
 private function reportData(SalesReturnIndexRequest $request): array { $filters=$request->filters(); return ['filters'=>$filters,'documents'=>$this->reports->buildDocumentQuery($filters)->with('items.destinationWarehouse:id,name,type')->limit(1000)->get(),'summary'=>$this->reports->summary($filters),'reportService'=>$this->reports]; }
 private function pdfResponse(string $view,array $data,string $filename,string $orientation='portrait'){ $dompdf=new Dompdf(['isRemoteEnabled'=>true,'defaultFont'=>'DejaVu Sans']); $dompdf->loadHtml(view($view,$data)->render(),'UTF-8'); $dompdf->setPaper('A4',$orientation); $dompdf->render(); return response($dompdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$filename.'"']); }
 private function formData(): array { return ['warehouses'=>Warehouse::where('is_active',true)->whereIn('type',['central','return'])->get(), 'categories'=>Category::orderBy('name')->get(), 'legacyReturnUrl'=>route('vouchers.section.index','return-from-sale')]; }
 private function enrich(Request $request): array { $data=$request->validated(); $data['can_override_destination']=$request->user()?->can('sales_returns.override_destination') || $request->user()?->hasRole(['admin','Admin']); return $data; }
}
