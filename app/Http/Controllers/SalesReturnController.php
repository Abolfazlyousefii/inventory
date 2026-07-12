<?php
namespace App\Http\Controllers;
use App\Http\Requests\{ApplySalesReturnRequest,StoreSalesReturnRequest,UpdateSalesReturnRequest};
use App\Models\{Category,SalesReturnDocument,Warehouse,WarehouseTransfer};
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class SalesReturnController extends Controller
{
 public function __construct(private SalesReturnService $service) {}
 public function create(){ return view('sales-returns.create',$this->formData()); }
 public function store(StoreSalesReturnRequest $request){ $data=$this->enrich($request); $doc=$this->service->createDraft($data, auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc, auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success','سند برگشت از فروش ذخیره شد.'); }
 public function show(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return view('sales-returns.show',compact('document')); }
 public function edit(SalesReturnDocument $document){ if(!$document->isDraft()) abort(403); $document->load('items'); return view('sales-returns.create',$this->formData()+['document'=>$document]); }
 public function update(UpdateSalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->updateDraft($document,$this->enrich($request),auth()->id()); if($request->input('action')==='apply') $doc=$this->service->apply($doc,auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success','سند بروزرسانی شد.'); }
 public function apply(ApplySalesReturnRequest $request, SalesReturnDocument $document){ $doc=$this->service->apply($document,auth()->id()); return redirect()->route('sales-returns.show',$doc)->with('success',$doc->wasChanged('status')?'سند ثبت نهایی شد.':'این سند قبلاً ثبت نهایی شده است.'); }
 public function cancel(Request $request, SalesReturnDocument $document){ $doc=$this->service->cancelDraft($document,auth()->id(),$request->input('cancel_reason')); return redirect()->route('sales-returns.show',$doc)->with('success','پیش‌نویس لغو شد.'); }
 public function print(SalesReturnDocument $document){ $document->load('customer','invoice','items.destinationWarehouse','creator','applier'); return view('sales-returns.print',compact('document')); }
 private function formData(): array { $warehouses=Warehouse::where('is_active',true)->whereIn('type',['central','return'])->orderBy('type')->get(); return ['warehouses'=>$warehouses,'categories'=>Category::orderBy('name')->get(),'returnReasons'=>['خرابی کالا'=>'خرابی کالا','مغایرت کالا'=>'مغایرت کالا','اشتباه در ارسال'=>'اشتباه در ارسال','انصراف مشتری'=>'انصراف مشتری','ایراد ظاهری'=>'ایراد ظاهری','ایراد فنی'=>'ایراد فنی','ثبت اشتباه'=>'ثبت اشتباه','سایر'=>'سایر']]; }
 private function enrich(Request $r): array { $data=$r->validated(); $data['can_override_destination']=auth()->user()?->can('sales_returns.override_destination') ?? false; $data['override_invoice_status']=auth()->user()?->can('sales_returns.override_invoice_status') ?? false; return $data; }
}
