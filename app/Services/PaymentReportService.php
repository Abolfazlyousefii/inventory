<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Support\JalaliDate;

/** Read-only report: one invoice_payments row is one receipt, never a ledger row. */
class PaymentReportService
{
    public function base(array $f)
    {
        $latest=DB::table('cheques')->selectRaw('invoice_payment_id, MAX(id) as latest_id, COUNT(*) as copies')->groupBy('invoice_payment_id');
        $q=DB::table('invoice_payments as p')->leftJoinSub($latest,'cq',fn($j)=>$j->on('cq.invoice_payment_id','=','p.id'))
            ->leftJoin('cheques as c','c.id','=','cq.latest_id')->leftJoin('invoices as i','i.id','=','p.invoice_id')
            ->leftJoin('customers as customer','customer.id','=',DB::raw('COALESCE(p.customer_id,i.customer_id)'))
            ->leftJoin('users as u','u.id','=','p.created_by')->whereIn('p.method',['cash','online','cheque']);
        if($f['method']==='cash')$q->whereIn('p.method',['cash','online']);
        elseif($f['method'])$q->where('p.method',$f['method']);
        if($f['customer']){$term='%'.addcslashes($f['customer'],'%_\\').'%';$q->where(fn($x)=>$x->where('customer.name','like',$term)->orWhereRaw("TRIM(CONCAT(COALESCE(customer.first_name,''),' ',COALESCE(customer.last_name,''))) LIKE ?",[$term])->orWhere('customer.mobile','like',$term)->orWhere('i.customer_name','like',$term)->orWhere('i.customer_mobile','like',$term));}
        if($f['bank'])$q->whereRaw("COALESCE(NULLIF(c.bank_name,''),p.bank_name) = ?",[$f['bank']]);
        return $q;
    }

    public function dateSql(): string {return "CASE WHEN p.method = 'cheque' THEN COALESCE(c.received_at,p.paid_at) ELSE p.paid_at END";}
    public function received(array $f)
    {
        return $this->base($f)->whereRaw($this->dateSql().' >= ?',[$f['from']])
            ->whereRaw($this->dateSql().' < ?',[(new \DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d')]);
    }
    public function dueQuery(array $f,string $bucket)
    {
        $q=$this->base($f)->where(fn($x)=>$x->whereNotNull('c.id')->orWhere('p.method','cheque'));$today=now('Asia/Tehran')->toDateString();$week=now('Asia/Tehran')->addDays(7)->toDateString();
        return match($bucket){
            'past'=>$q->where('c.due_date','<',$today), 'today'=>$q->whereDate('c.due_date',$today),
            'week'=>$q->where('c.due_date','>',$today)->where('c.due_date','<=',$week),
            'later'=>$q->where('c.due_date','>',$week), default=>$q->whereNull('c.due_date')};
    }
    public function report(array $f): array
    {
        $group="CASE WHEN p.method='cheque' THEN 'cheque' ELSE 'cash' END";
        $summary=$this->received($f)->selectRaw($group.' as method, COUNT(*) as count, SUM(p.amount) as amount')->groupByRaw($group)->get()->keyBy('method');
        $trend=$this->received($f)->selectRaw('DATE('.$this->dateSql().') as day, '.$group.' as method, COUNT(*) as count, SUM(p.amount) as amount')->groupByRaw('DATE('.$this->dateSql().'), '.$group)->orderBy('day')->get();
        $days=[];foreach($trend as$r){$days[$r->day]??=['day'=>$r->day,'label'=>JalaliDate::date($r->day),'cash'=>0,'cheque'=>0,'count'=>0];$days[$r->day][$r->method]=(int)$r->amount;$days[$r->day]['count']+=(int)$r->count;}
        $due=[];foreach(['past'=>'سررسید گذشته','today'=>'سررسید امروز','week'=>'۷ روز آینده','later'=>'پس از ۷ روز','undated'=>'بدون سررسید'] as$key=>$label){$r=$this->dueQuery($f,$key)->selectRaw('COUNT(*) as count, COALESCE(SUM(p.amount),0) as amount')->first();$due[]=['key'=>$key,'label'=>$label,'count'=>(int)$r->count,'amount'=>(int)$r->amount];}
        $totals=[];foreach(['cash','cheque'] as$key)$totals[$key]=['count'=>(int)($summary[$key]->count??0),'amount'=>(int)($summary[$key]->amount??0)];
        return ['totals'=>$totals,'trend'=>array_values($days),'due'=>$due,
            'undated'=>$this->base($f)->whereNull(DB::raw($this->dateSql()))->count(),
            'duplicate_cheque_payments'=>$this->base($f)->where('cq.copies','>',1)->count(),
            'method_mismatches'=>$this->base($f)->whereNotNull('c.id')->where('p.method','!=','cheque')->count()];
    }
    public function rows($q)
    {
        return $q->select(['p.id','p.invoice_id','p.method','p.amount','p.payment_identifier','c.cheque_number','c.due_date','c.status','i.uuid as invoice_uuid','u.name as creator'])
            ->selectRaw($this->dateSql().' as received_at, COALESCE(NULLIF(c.bank_name,\'\'),p.bank_name) as bank')
            ->selectRaw("COALESCE(NULLIF(customer.name,''),NULLIF(TRIM(CONCAT(COALESCE(customer.first_name,''),' ',COALESCE(customer.last_name,''))),''),i.customer_name,c.customer_name,'بدون نام') as customer_name")
            ->orderByDesc('p.id');
    }
}
