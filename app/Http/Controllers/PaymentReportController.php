<?php
namespace App\Http\Controllers;

use App\Services\{PaymentReportService,SalesDocumentAccessService};
use App\Support\{JalaliDate,PageAccessCatalog};
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Validation\{Rule,ValidationException};
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PaymentReportExport;

class PaymentReportController extends Controller
{
    public function filters(Request $request): array
    {
        $d=$request->validate(['date_from'=>'nullable|string|max:16','date_to'=>'nullable|string|max:16','method'=>['nullable',Rule::in(['cash','cheque'])],'customer'=>'nullable|string|max:120','bank'=>'nullable|string|max:255','scope'=>['nullable',Rule::in(['received','due'])],'bucket'=>['nullable',Rule::in(['past','today','week','later','undated'])],'day'=>'nullable|date_format:Y-m-d','page'=>'nullable|integer|min:1|max:100000']);
        $today=Jalalian::fromDateTime(now('Asia/Tehran'));$a=$d['date_from']??$today->format('Y/m').'/01';$b=$d['date_to']??$today->format('Y/m/d');$from=JalaliDate::toGregorianDate($a);$to=JalaliDate::toGregorianDate($b);
        if(!$from||!$to||$from>$to)throw ValidationException::withMessages(['date_from'=>'بازهٔ تاریخ شمسی معتبر انتخاب کنید.']);
        return ['from'=>$from,'to'=>$to,'date_from'=>JalaliDate::date($from),'date_to'=>JalaliDate::date($to),'method'=>$d['method']??null,'customer'=>$d['customer']??null,'bank'=>$d['bank']??null];
    }
    public function index(Request $r,PaymentReportService $s)
    {
        $filters=$this->filters($r);$report=$s->report($filters);
        $banks=DB::table('invoice_payments')->whereNotNull('bank_name')->where('bank_name','!=','')->pluck('bank_name')->merge(DB::table('cheques')->whereNotNull('bank_name')->where('bank_name','!=','')->pluck('bank_name'))->unique()->sort()->values();
        return view('finance.payments-report.index',compact('filters','report','banks'));
    }
    private function query(Request $r,PaymentReportService $s,array $f)
    {
        if($r->input('scope')==='due')return $s->dueQuery($f,$r->input('bucket','week'));
        $q=$s->received($f);if($r->filled('day'))$q->whereRaw('DATE('.$s->dateSql().') = ?',[$r->day]);return $q;
    }
    public function detail(Request $r,PaymentReportService $s)
    {
        $f=$this->filters($r);$page=$s->rows($this->query($r,$s,$f))->paginate(25);$invoices=PageAccessCatalog::userCanRoute($r->user(),'invoices.show')?Invoice::whereIn('id',$page->pluck('invoice_id')->filter())->get()->keyBy('id'):collect();$access=app(SalesDocumentAccessService::class);
        $page->getCollection()->transform(function($row)use($invoices,$access,$r){$i=$invoices->get($row->invoice_id);$row->url=$i&&$access->canViewInvoiceReadonly($i,$r->user())?route('invoices.show',$i->uuid):null;$row->received_at=JalaliDate::date($row->received_at);$row->due_date=JalaliDate::date($row->due_date);return $row;});
        return response()->json(['rows'=>$page->items(),'page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'total'=>$page->total()]);
    }
    public function export(Request $r,PaymentReportService $s)
    {
        $r->validate(['format'=>['required',Rule::in(['xlsx','print'])]]);$filters=$this->filters($r);$rows=$s->rows($this->query($r,$s,$filters))->get();
        if($r->format==='print')return view('finance.payments-report.print',compact('filters','rows'));
        return Excel::download(new PaymentReportExport($rows),'receipts-'.$filters['from'].'-'.$filters['to'].'.xlsx');
    }
}
