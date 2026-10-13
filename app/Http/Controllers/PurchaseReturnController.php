<?php

namespace App\Http\Controllers;

use App\Models\{Purchase, PurchaseReturn, Supplier, WarehouseStock};
use App\Services\PurchaseReturnService;
use App\Support\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseReturnService $service) {}

    public function index(Request $request)
    {
        $filters=$request->validate(['q'=>['nullable','string','max:100'],'supplier_id'=>['nullable','integer'],'show_deleted'=>['nullable','in:1']]);
        $documents=PurchaseReturn::query()->when($request->boolean('show_deleted'),fn($q)=>$q->withTrashed())
            ->when($filters['supplier_id']??null,fn($q,$id)=>$q->where('supplier_id',$id))
            ->when($filters['q']??null,fn($q,$term)=>$q->where(fn($q)=>$q->where('document_number','like','%'.$term.'%')->orWhere('supplier_name','like','%'.$term.'%')))
            ->latest('id')->paginate(20)->withQueryString();
        return view('purchase-returns.index',['documents'=>$documents,'suppliers'=>Supplier::orderBy('name')->get(['id','name'])]);
    }

    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, PurchaseReturn $purchaseReturn) { return $this->form($request,$purchaseReturn); }

    private function form(Request $request, ?PurchaseReturn $document=null)
    {
        $request->validate(['supplier_id'=>['nullable','integer','exists:suppliers,id'],'purchase_id'=>['nullable','integer','exists:purchases,id']]);
        $supplierId=$document?->supplier_id ?? old('supplier_id',$request->input('supplier_id'));
        $purchaseId=$document?->purchase_id ?? old('purchase_id',$request->input('purchase_id'));
        $purchase=$purchaseId ? Purchase::with('items')->where('supplier_id',$supplierId)->findOrFail($purchaseId):null;
        $selected=$document?->items()->get()->keyBy('purchase_item_id') ?? collect();$rows=collect();
        if ($purchase) {
            $warehouseId=$this->service->warehouseId();
            $stocks=WarehouseStock::where('warehouse_id',$warehouseId)->whereIn('product_id',$purchase->items->pluck('product_id'))->get()->groupBy(fn($stock)=>$stock->product_id.':'.($stock->product_variant_id??0));
            $returnedByItem=$this->service->returnedQuantities($purchase->items->pluck('id')->all(),$document?->id);
            $oldByStock=$selected->groupBy(fn($item)=>$item->product_id.':'.($item->product_variant_id??0))->map(fn($items)=>(int)$items->sum('quantity'));
            foreach ($purchase->items as $item) {
                $stockKey=$item->product_id.':'.($item->product_variant_id??0);
                $stockRows=$stocks->get($stockKey,collect());
                $free=$stockRows->count()>1 ? 0 : (int)($stockRows->first()?->quantity??0);
                $old=(int)($selected->get($item->id)?->quantity??0);$returned=(int)$returnedByItem->get($item->id,0);
                $capacity=$free+(int)$oldByStock->get($stockKey,0);
                $rows->push((object)['item'=>$item,'returned'=>$returned,'free'=>$free,'old'=>$old,'stockKey'=>$stockKey,'capacity'=>$capacity,'limit'=>max(0,min((int)$item->quantity-$returned,$capacity)),
                    'price'=>(int)($selected->get($item->id)?->buy_price??$item->buy_price)]);
            }
        }
        return view('purchase-returns.form',[
            'document'=>$document,'supplierId'=>$supplierId,'purchase'=>$purchase,'rows'=>$rows,
            'suppliers'=>Supplier::whereHas('purchases')->orderBy('name')->get(['id','name']),
            'purchases'=>$supplierId ? Purchase::where('supplier_id',$supplierId)->latest('purchased_at')->get(['id','purchased_at','total_amount']):collect(),
            'token'=>old('submission_token',(string)Str::uuid()),
        ]);
    }

    private function payload(Request $request, bool $editing=false): array
    {
        $date=JalaliDate::toGregorianDate($request->input('returned_at_fa'));
        if (!$date) throw ValidationException::withMessages(['returned_at_fa'=>'تاریخ شمسی معتبر وارد کنید.']);
        $data=$request->validate([
            'supplier_id'=>['required','integer','exists:suppliers,id'],'purchase_id'=>['required','integer','exists:purchases,id'],
            'submission_token'=>[$editing?'nullable':'required','uuid'],'version'=>[$editing?'required':'nullable','integer','min:1'],
            'note'=>['nullable','string','max:2000'],'items'=>['required','array','max:1000'],
            'items.*.purchase_item_id'=>['required','integer','distinct'],'items.*.quantity'=>['required','integer','min:0','max:1000000'],
        ]);
        return $data+['returned_at'=>$date];
    }

    public function store(Request $request)
    {
        $doc=$this->service->create($this->payload($request),(int)$request->user()->id);
        return redirect()->route('purchase-returns.show',$doc)->with('success','برگشت از خرید ثبت و موجودی آزاد انبار مرکزی کم شد.');
    }
    public function update(Request $request, PurchaseReturn $purchaseReturn)
    {
        $doc=$this->service->update($purchaseReturn,$this->payload($request,true),(int)$request->user()->id);
        return redirect()->route('purchase-returns.show',$doc)->with('success','سند ویرایش و اختلاف موجودی اعمال شد.');
    }
    public function destroy(Request $request, PurchaseReturn $purchaseReturn)
    {
        $data=$request->validate(['version'=>['required','integer','min:1'],'reason'=>['nullable','string','max:1000']]);
        $this->service->delete($purchaseReturn,(int)$data['version'],(int)$request->user()->id,$data['reason']??null);
        return redirect()->route('purchase-returns.index')->with('success','سند حذف شد و کالاها به موجودی آزاد مرکزی برگشتند؛ سابقه محفوظ است.');
    }
    public function show(PurchaseReturn $purchaseReturn)
    {
        return view('purchase-returns.show',['document'=>$purchaseReturn->load(['items','revisions.user','purchase'])]);
    }
    public function print(PurchaseReturn $purchaseReturn)
    {
        return view('purchase-returns.print',['document'=>$purchaseReturn->load('items')]);
    }
}
