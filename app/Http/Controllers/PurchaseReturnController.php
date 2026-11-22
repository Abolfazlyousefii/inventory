<?php

namespace App\Http\Controllers;

use App\Models\{Purchase, PurchaseReturn, Supplier, Warehouse, WarehouseStock};
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
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:active,cancelled,all'],
            'from_fa' => ['nullable', 'string', 'max:10'],
            'to_fa' => ['nullable', 'string', 'max:10'],
            'show_deleted' => ['nullable', 'in:1'], // Keep old bookmarked archive URLs working.
        ]);
        $status = $filters['status'] ?? ($request->boolean('show_deleted') ? 'all' : 'active');
        $from = $this->filterDate($filters['from_fa'] ?? null, 'from_fa');
        $to = $this->filterDate($filters['to_fa'] ?? null, 'to_fa');
        if ($from && $to && $from > $to) {
            throw ValidationException::withMessages(['to_fa' => 'تاریخ پایان نباید قبل از تاریخ شروع باشد.']);
        }
        $term = trim((string) ($filters['q'] ?? ''));
        $normalizedTerm = $this->latinDigits($term);
        $documents = PurchaseReturn::with(['warehouse', 'purchase'])->withCount('items')
            ->when($status === 'all', fn ($q) => $q->withTrashed())
            ->when($status === 'cancelled', fn ($q) => $q->onlyTrashed())
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($from, fn ($q) => $q->whereDate('returned_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('returned_at', '<=', $to))
            ->when($term !== '', fn ($q) => $q->where(function ($q) use ($term, $normalizedTerm) {
                $q->where('document_number', 'like', '%'.$normalizedTerm.'%')
                    ->orWhere('supplier_name', 'like', '%'.$term.'%')
                    ->orWhere('purchase_id', 'like', '%'.$normalizedTerm.'%');
            }))
            ->latest('id')->paginate(20)->withQueryString();

        return view('purchase-returns.index', [
            'documents' => $documents,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'status' => $status,
        ]);
    }

    private function filterDate(?string $value, string $field): ?string
    {
        if ($value === null || $value === '') return null;
        $date = JalaliDate::toGregorianDate($value);
        if (!$date) throw ValidationException::withMessages([$field => 'تاریخ شمسی فیلتر معتبر نیست.']);
        return $date;
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ));
    }

    /** Paginated Select2 lookup; does not load a supplier's full purchase history into the form. */
    public function purchases(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if ($supplier->isInventoryAdjustmentSource()) {
            throw ValidationException::withMessages(['supplier_id' => 'این تأمین‌کننده مرجع برگشت از خرید نیست.']);
        }
        $term = $this->latinDigits(trim((string) ($data['q'] ?? '')));
        $purchases = Purchase::where('supplier_id', $supplier->id)
            ->when($term !== '', fn ($q) => $q->where('id', 'like', '%'.$term.'%'))
            ->latest('id')->paginate(20, ['id', 'purchased_at', 'total_amount'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'results' => $purchases->getCollection()->map(fn ($purchase) => [
                'id' => $purchase->id,
                'text' => 'خرید '.$purchase->id.' · '.JalaliDate::date($purchase->purchased_at).' · '.number_format((int) $purchase->total_amount).' ریال',
            ])->values(),
            'pagination' => ['more' => $purchases->hasMorePages()],
        ]);
    }

    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, PurchaseReturn $purchaseReturn) { return $this->form($request,$purchaseReturn); }

    private function form(Request $request, ?PurchaseReturn $document=null)
    {
        $request->validate(['supplier_id'=>['nullable','integer','exists:suppliers,id'],'purchase_id'=>['nullable','integer','exists:purchases,id'],'warehouse_id'=>['nullable','integer','exists:warehouses,id']]);
        $supplierId=$document?->supplier_id ?? old('supplier_id',$request->input('supplier_id'));
        if (!$document && $supplierId && Supplier::find((int)$supplierId)?->isInventoryAdjustmentSource()) {
            throw ValidationException::withMessages(['supplier_id'=>'کسری و اضافی منبع انبارداری است و خریدهای آن مرجع برگشت به تأمین‌کننده نیستند.']);
        }
        $purchaseId=$document?->purchase_id ?? old('purchase_id',$request->input('purchase_id'));
        $purchase=$purchaseId ? Purchase::with('items')->where('supplier_id',$supplierId)->findOrFail($purchaseId):null;
        $selection=old('warehouse_id',$request->input('warehouse_id'));
        $warehouseId=$document ? (int)$document->warehouse_id : ($selection!==null && $selection!=='' ? (int)$selection : $this->service->warehouseId());
        $warehouse=Warehouse::findOrFail($warehouseId);
        abort_if(!$document && !$warehouse->is_active,422,'انبار انتخاب‌شده فعال نیست؛ به فرم برگشت برگردید و یک انبار فعال انتخاب کنید.');
        $selected=$document?->items()->get()->keyBy('purchase_item_id') ?? collect();$rows=collect();
        if ($purchase) {
            $stocks=WarehouseStock::where('warehouse_id',$warehouseId)->whereIn('product_id',$purchase->items->pluck('product_id'))->get()->groupBy(fn($stock)=>$stock->product_id.':'.($stock->product_variant_id??0));
            $returnedByItem=$this->service->returnedQuantities($purchase->items->pluck('id')->all(),$document?->id);
            $netTotals=$this->service->purchaseLineNetTotals($purchase,$purchase->items);
            $returnedAmounts=$this->service->returnedAmounts($purchase->items->pluck('id')->all(),$document?->id);
            $oldByStock=$selected->groupBy(fn($item)=>$item->product_id.':'.($item->product_variant_id??0))->map(fn($items)=>(int)$items->sum('quantity'));
            foreach ($purchase->items as $item) {
                $stockKey=$item->product_id.':'.($item->product_variant_id??0);
                $stockRows=$stocks->get($stockKey,collect());
                $free=$stockRows->count()>1 ? 0 : (int)($stockRows->first()?->quantity??0);
                $old=(int)($selected->get($item->id)?->quantity??0);$returned=(int)$returnedByItem->get($item->id,0);
                $capacity=$free+(int)$oldByStock->get($stockKey,0);
                $rows->push((object)['item'=>$item,'returned'=>$returned,'free'=>$free,'old'=>$old,'stockKey'=>$stockKey,'capacity'=>$capacity,'limit'=>max(0,min((int)$item->quantity-$returned,$capacity)),
                    'netTotal'=>(int)($netTotals[$item->id]??0),'previousAmount'=>(int)($returnedAmounts[$item->id]??0),
                    'price'=>(int)($selected->get($item->id)?->buy_price??$item->buy_price)]);
            }
        }
        return view('purchase-returns.form',[
            'document'=>$document,'supplierId'=>$supplierId,'purchase'=>$purchase,'rows'=>$rows,
            'warehouseId'=>$warehouseId,'warehouse'=>$warehouse,
            'warehouses'=>Warehouse::where('is_active',true)->orderByRaw("CASE WHEN type = 'central' THEN 0 ELSE 1 END")->orderBy('name')->get(),
            'suppliers'=>Supplier::whereHas('purchases')->orderBy('name')->get()->reject(fn(Supplier $supplier)=>$supplier->isInventoryAdjustmentSource())->values(),
            'purchases'=>$supplierId ? Purchase::where('supplier_id', $supplierId)->latest('id')->limit(20)->get(['id', 'purchased_at', 'total_amount']) : collect(),
            'token'=>old('submission_token',(string)Str::uuid()),
        ]);
    }

    private function payload(Request $request, bool $editing=false): array
    {
        // Server-side digit normalization also supports forms submitted without JavaScript.
        if (is_array($request->input('items'))) {
            $items = $request->input('items');
            foreach ($items as &$row) {
                if (is_array($row) && isset($row['quantity']) && is_string($row['quantity'])) {
                    $row['quantity'] = $this->latinDigits(trim($row['quantity']));
                }
            }
            unset($row);
            $request->merge(['items' => $items]);
        }
        $date=JalaliDate::toGregorianDate($request->input('returned_at_fa'));
        if (!$date) throw ValidationException::withMessages(['returned_at_fa'=>'تاریخ شمسی معتبر وارد کنید.']);
        $data=$request->validate([
            'supplier_id'=>['required','integer','exists:suppliers,id'],'purchase_id'=>['required','integer','exists:purchases,id'],
            'warehouse_id'=>['required','integer','exists:warehouses,id'],
            'submission_token'=>[$editing?'nullable':'required','uuid'],'version'=>[$editing?'required':'nullable','integer','min:1'],
            'note'=>['nullable','string','max:2000'],'items'=>['required','array','max:1000'],
            'items.*.purchase_item_id'=>['required','integer','distinct'],'items.*.quantity'=>['required','integer','min:0','max:1000000'],
        ]);
        return $data+['returned_at'=>$date];
    }

    public function store(Request $request)
    {
        $doc=$this->service->create($this->payload($request),(int)$request->user()->id);
        return redirect()->route('purchase-returns.show',$doc)->with('success','برگشت از خرید ثبت و موجودی آزاد انبار مبدأ انتخاب‌شده کم شد.');
    }
    public function update(Request $request, PurchaseReturn $purchaseReturn)
    {
        $doc=$this->service->update($purchaseReturn,$this->payload($request,true),(int)$request->user()->id);
        return redirect()->route('purchase-returns.show',$doc)->with('success','سند ویرایش و اختلاف موجودی اعمال شد.');
    }
    public function destroy(Request $request, PurchaseReturn $purchaseReturn)
    {
        $data=$request->validate(
            ['version'=>['required','integer','min:1'],'reason'=>['required','string','max:1000','regex:/\S/u']],
            ['reason.required'=>'دلیل ابطال سند الزامی است.','reason.regex'=>'دلیل ابطال نمی‌تواند فقط فاصله باشد.','reason.max'=>'دلیل ابطال نباید بیش از ۱۰۰۰ نویسه باشد.']
        );
        $this->service->delete($purchaseReturn,(int)$data['version'],(int)$request->user()->id,trim($data['reason']));
        return redirect()->route('purchase-returns.index')->with('success','سند ابطال شد، موجودی به انبار مبدأ بازگشت و اثر حساب تأمین‌کننده اصلاح شد؛ سوابق محفوظ است.');
    }
    public function show(PurchaseReturn $purchaseReturn)
    {
        return view('purchase-returns.show',['document'=>$purchaseReturn->load(['items','revisions.user','purchase','warehouse'])]);
    }
    public function print(PurchaseReturn $purchaseReturn)
    {
        return view('purchase-returns.print',['document'=>$purchaseReturn->load(['items','warehouse'])]);
    }
}
