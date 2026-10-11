<?php
namespace App\Exports;
use Maatwebsite\Excel\Concerns\{FromArray,WithHeadings,ShouldAutoSize,WithCustomValueBinder};
use PhpOffice\PhpSpreadsheet\Cell\{Cell,DataType,DefaultValueBinder};
use App\Support\JalaliDate;
class PaymentReportExport extends DefaultValueBinder implements FromArray,WithHeadings,ShouldAutoSize,WithCustomValueBinder
{
    public function __construct(private $rows){}
    public function headings():array{return ['شناسه پرداخت','تاریخ دریافت','مشتری','روش','مبلغ (ریال)','بانک','چک / پیگیری','سررسید','وضعیت صیادی','فاکتور','ثبت‌کننده'];}
    public function array():array{return $this->rows->map(fn($r)=>[$r->id,JalaliDate::date($r->received_at),$r->customer_name,$r->method==='cheque'?'چکی':($r->method==='online'?'آنلاین':'نقدی'),(int)$r->amount,$r->bank,$r->cheque_number??$r->payment_identifier,JalaliDate::date($r->due_date),$r->method==='cheque'?($r->status==='registered'?'ثبت‌شده':($r->status==='unregistered'?'ثبت‌نشده':'نامشخص')):'—',$r->invoice_uuid,$r->creator])->all();}
    public function bindValue(Cell $c,$value):bool{if(is_string($value)){$c->setValueExplicit($value,DataType::TYPE_STRING);return true;}return parent::bindValue($c,$value);}
}
