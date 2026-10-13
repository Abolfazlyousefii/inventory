<?php

namespace App\Http\Controllers;

use App\Services\{TopProductsReportService, SalesDocumentAccessService};
use App\Models\Invoice;
use App\Support\{JalaliDate, PageAccessCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};
use Illuminate\Pagination\LengthAwarePaginator;
use Morilog\Jalali\Jalalian;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\TopProductsExport;

class TopProductsReportController extends Controller
{
    public function filters(Request $request): array
    {
        $data=$request->validate(['date_from'=>['nullable','string','max:16'],'date_to'=>['nullable','string','max:16'],
            'group'=>['nullable',Rule::in(['product','variant'])],'metric'=>['nullable',Rule::in(['quantity','revenue'])],
            'category_id'=>['nullable','integer','exists:categories,id'],'seller_id'=>['nullable','integer','exists:users,id'],
            'page'=>['nullable','integer','min:1','max:100000']]);
        $today=Jalalian::fromDateTime(now());
        $from=$data['date_from']??$today->format('Y/m').'/01'; $to=$data['date_to']??$today->format('Y/m/d');
        $start=JalaliDate::toGregorianDate($from); $end=JalaliDate::toGregorianDate($to);
        if (!$start || !$end || $start>$end) throw ValidationException::withMessages(['date_from'=>'بازهٔ تاریخ شمسی معتبر انتخاب کنید؛ شروع باید قبل از پایان باشد.']);
        return ['from'=>$start,'to'=>$end,'date_from'=>JalaliDate::date($start),'date_to'=>JalaliDate::date($end),
            'group'=>$data['group']??'product','metric'=>$data['metric']??'quantity','category_id'=>$data['category_id']??null,'seller_id'=>$data['seller_id']??null,'product_id'=>null];
    }

    public function index(Request $request, TopProductsReportService $service)
    {
        $filters=$this->filters($request); $report=$service->report($filters);
        $all=collect($report['rows']); $page=(int)$request->input('page',1);
        $rows=new LengthAwarePaginator($all->forPage($page,30)->values(),$all->count(),30,$page,['path'=>$request->url(),'query'=>$request->query()]);
        $chart=['bars'=>$all->take(10)->values(),'slices'=>$all->take(5)->values(),'total'=>$report['summary'][$filters['metric']],'metric'=>$filters['metric']];
        return view('finance.top-products.index', compact('filters','report','rows','chart')+[
            'categories'=>DB::table('categories')->orderBy('name')->get(['id','name','parent_id']),
            'sellers'=>DB::table('users')->orderBy('name')->get(['id','name'])]);
    }

    public function detail(Request $request, TopProductsReportService $service)
    {
        $request->validate(['product_id'=>['required','integer','min:1']]);
        $filters=$this->filters($request); $filters['product_id']=(int)$request->product_id; $filters['group']='variant';
        $report=$service->report($filters);
        $ids=array_column($report['invoices'],'id');
        $invoices=PageAccessCatalog::userCanRoute($request->user(),'invoices.show') ? Invoice::whereIn('id',$ids)->get()->keyBy('id') : collect();
        $access=app(SalesDocumentAccessService::class);
        foreach($report['invoices'] as &$row) {
            $invoice=$invoices->get($row['id']);
            $row['url']=$invoice && $access->canViewInvoiceReadonly($invoice,$request->user()) ? route('invoices.show',$row['uuid']) : null;
            $row['shipped_at']=JalaliDate::dateTime($row['shipped_at']);
        } unset($row);
        foreach($report['trend'] as &$row) $row['day']=JalaliDate::date($row['day']); unset($row);
        return response()->json($report);
    }

    public function export(Request $request, TopProductsReportService $service)
    {
        $request->validate(['format'=>['required',Rule::in(['xlsx','print'])]]);
        $filters=$this->filters($request); $report=$service->report($filters);
        $filters['category_name']=$filters['category_id'] ? DB::table('categories')->where('id',$filters['category_id'])->value('name') : 'همه';
        $filters['seller_name']=$filters['seller_id'] ? DB::table('users')->where('id',$filters['seller_id'])->value('name') : 'همه';
        $filters['undated_invoices']=$report['undated_invoices'];
        if($request->format==='print') return view('finance.top-products.print',compact('filters','report'));
        return Excel::download(new TopProductsExport($report['rows'],$filters), 'top-products-'.$filters['from'].'-'.$filters['to'].'.xlsx');
    }
}
