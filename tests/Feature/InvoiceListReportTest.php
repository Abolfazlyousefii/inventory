<?php

use App\Models\{Customer,Invoice,InvoicePayment,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
beforeEach(function(){
    $this->withoutVite();$this->travelTo(now()->setDate(2026,11,9)->setTime(12,0));
    $this->actor=User::factory()->create();$this->actor->assignRole(Role::findOrCreate('Owner','web'));$this->actingAs($this->actor);
    $this->customer=Customer::create(['crm_customer_id'=>'REPORT-1','mobile'=>'09120000001','first_name'=>'مشتری','last_name'=>'گزارش']);
    $this->seller=User::factory()->create(['name'=>'فروشنده گزارش']);
});

function invoiceListReportFixture(array $values=[]): Invoice {
    static $number=100;
    $code=str_pad((string)++$number,5,'0',STR_PAD_LEFT);
    return Invoice::withoutEvents(fn()=>Invoice::forceCreate(array_replace([
        'uuid'=>$code,'customer_id'=>test()->customer->id,
        'seller_id'=>test()->seller->id,'customer_name'=>'مشتری گزارش','total'=>100000,'subtotal'=>100000,
        'status'=>Invoice::STATUS_SHIPPED,'created_at'=>now(),'updated_at'=>now(),
    ],$values)));
}

it('prints all matching invoices beyond the screen page with identical customer seller and date filters',function(){
    $items=collect();for($i=0;$i<45;$i++)$items->push(invoiceListReportFixture());
    $other=Customer::create(['crm_customer_id'=>'REPORT-2','mobile'=>'09120000002','first_name'=>'مشتری دیگر']);
    $excluded=[invoiceListReportFixture(['customer_id'=>$other->id]),invoiceListReportFixture(['seller_id'=>$this->actor->id]),
        invoiceListReportFixture(['created_at'=>now()->subDays(2)]),invoiceListReportFixture(['status'=>Invoice::STATUS_NOT_SHIPPED,'cancelled_at'=>now()])];
    $filters=['customer_id'=>$this->customer->id,'seller_id'=>$this->seller->id,'quick_range'=>'today','limit'=>10];
    $screen=$this->getJson(route('invoices.data',$filters+['include_summary'=>1]))->assertOk();
    $response=$this->get(route('invoices.list.print',$filters))->assertOk()->assertViewHas('summary',fn($s)=>$s['invoice_count']===45&&$s['total_sales']===4500000);
    $response->assertViewHas('invoices',fn($rows)=>$rows->count()===45);
    foreach($items as $invoice)$response->assertSee($invoice->uuid);
    foreach($excluded as $invoice)$response->assertDontSee('data-invoice-number="'.$invoice->uuid.'"',false);
    expect(substr_count($screen->json('desktop_html'),'<tr'))->toBe(10);
});

it('exports a real formatted workbook preserving codes and treating customer text as text',function(){
    $invoice=invoiceListReportFixture(['uuid'=>'00007','customer_name'=>'=1+1']);
    InvoicePayment::create(['invoice_id'=>$invoice->id,'customer_id'=>$this->customer->id,'created_by'=>$this->actor->id,'method'=>'cash','amount'=>25000,'paid_at'=>now()]);
    invoiceListReportFixture(['seller_id'=>$this->actor->id]);
    $response=$this->get(route('invoices.list.excel',['seller_id'=>$this->seller->id]))->assertOk();
    // Inspect the actual XLSX archive without requiring the optional ZipArchive extension.
    $zip=new PharData($response->baseResponse->getFile()->getPathname());
    $strings=simplexml_load_string($zip['xl/sharedStrings.xml']->getContent());
    $sheet=simplexml_load_string($zip['xl/worksheets/sheet1.xml']->getContent());
    $cell=fn($ref)=>$sheet->xpath('//*[local-name()="c" and @r="'.$ref.'"]')[0];
    $value=function($ref)use($cell,$strings){$c=$cell($ref);return (string)$c['t']==='s'?(string)$strings->si[(int)$c->v]->t:(int)$c->v;};
    expect($value('B5'))->toBe('00007')->and((string)$cell('B5')['t'])->toBe('s')
        ->and($value('D5'))->toBe('=1+1')->and((string)$cell('D5')['t'])->toBe('s')
        ->and($value('G5'))->toBe(100000)->and($value('H5'))->toBe(25000)->and($value('I5'))->toBe(75000)
        ->and($value('G6'))->toBe(100000)->and($value('H6'))->toBe(25000)->and($value('I6'))->toBe(75000)
        ->and((string)$sheet->sheetViews->sheetView['rightToLeft'])->toBeIn(['1','true'])
        ->and((string)$sheet->sheetViews->sheetView->pane['topLeftCell'])->toBe('A5')
        ->and((string)$sheet->autoFilter['ref'])->toBe('A4:I5');
});

it('validates dates and protects both reports with the invoice page permission',function(){
    foreach(['invoices.list.print','invoices.list.excel'] as $route){
        $this->getJson(route($route,['date_from'=>'1405/13/40']))->assertUnprocessable();
        $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
        $this->actingAs($this->actor);
    }
});

it('prints an empty result without inventing rows',function(){
    $this->get(route('invoices.list.print'))->assertOk()->assertSee('فاکتوری با این فیلترها پیدا نشد.')->assertViewHas('summary',fn($s)=>$s['invoice_count']===0);
});

it('retains paid amounts when a leading-zero order code is searched',function(){
    $invoice=invoiceListReportFixture(['uuid'=>'00009']);
    InvoicePayment::create(['invoice_id'=>$invoice->id,'customer_id'=>$this->customer->id,'created_by'=>$this->actor->id,'method'=>'cash','amount'=>25000,'paid_at'=>now()]);
    $this->get(route('invoices.list.print',['order_code'=>'۰۰۰۰۹']))->assertOk()->assertSee('25,000')->assertSee('75,000');
});

it('exports every row independently of cursor page size',function(){
    for($i=0;$i<45;$i++)invoiceListReportFixture();
    $response=$this->get(route('invoices.list.excel',['limit'=>10]))->assertOk();
    $zip=new PharData($response->baseResponse->getFile()->getPathname());
    $sheet=simplexml_load_string($zip['xl/worksheets/sheet1.xml']->getContent());
    expect($sheet->sheetData->row)->toHaveCount(50);
    $zero=$sheet->xpath('//*[local-name()="c" and @r="H5"]');
    expect($zero)->toHaveCount(1)->and((string)$zero[0]->v)->toBe('0');
});

it('includes both date boundaries and uses the same filters for the print and workbook',function(){
    $start=invoiceListReportFixture(['created_at'=>now()->startOfDay()]);
    $end=invoiceListReportFixture(['created_at'=>now()->endOfDay()]);
    invoiceListReportFixture(['created_at'=>now()->subDay()->endOfDay()]);
    invoiceListReportFixture(['created_at'=>now()->addDay()->startOfDay()]);
    $date=\Morilog\Jalali\Jalalian::fromCarbon(now())->format('Y/m/d');
    $filters=['date_from'=>$date,'date_to'=>$date,'seller_id'=>$this->seller->id,'customer_id'=>$this->customer->id];
    $this->get(route('invoices.list.print',$filters))->assertOk()->assertViewHas('summary',fn($s)=>$s['invoice_count']===2)->assertSee($start->uuid)->assertSee($end->uuid);
    $response=$this->get(route('invoices.list.excel',$filters))->assertOk();
    $zip=new PharData($response->baseResponse->getFile()->getPathname());
    $sheet=simplexml_load_string($zip['xl/worksheets/sheet1.xml']->getContent());
    expect($sheet->sheetData->row)->toHaveCount(7);
});
