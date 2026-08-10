<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceSalesVisitorsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_aggregates_payments_per_invoice_without_double_counting_sales(): void
    {
        $owner=User::factory()->create();$owner->assignRole(Role::findOrCreate('Owner','web'));
        $sellerA=User::factory()->create(['name'=>'فروشنده الف','is_seller'=>true]);
        $sellerB=User::factory()->create(['name'=>'فروشنده ب','is_seller'=>true]);
        $customer=Customer::query()->create(['first_name'=>'مشتری','last_name'=>'مشترک','mobile'=>'09120000001']);
        $a1=$this->invoice($sellerA,$customer,1000);$a2=$this->invoice($sellerA,$customer,2000);$b1=$this->invoice($sellerB,$customer,4000);
        InvoicePayment::query()->create(['invoice_id'=>$a1->id,'method'=>'cash','amount'=>200]);
        InvoicePayment::query()->create(['invoice_id'=>$a1->id,'method'=>'cash','amount'=>300]);
        InvoicePayment::query()->create(['invoice_id'=>$b1->id,'method'=>'cash','amount'=>1000]);
        $this->assertSame(500,(int)InvoicePayment::query()->where('invoice_id',$a1->id)->sum('amount'));
        $this->invoice($sellerA,$customer,9000,Invoice::STATUS_NOT_SHIPPED);
        Invoice::query()->create(['uuid'=>fake()->uuid(),'seller_id'=>null,'customer_id'=>$customer->id,'customer_name'=>'بدون فروشنده','total'=>8000,'subtotal'=>8000,'status'=>Invoice::STATUS_SHIPPED]);

        $response=$this->actingAs($owner)->get(route('finance.reports.sales-visitors'));
        $response->assertOk()->assertSee('گزارش فروش ویزیتورها');
        $rows=collect($response->viewData('rows'))->keyBy('user_id');
        $this->assertCount(2,$rows);
        $this->assertSame(2,$rows[$sellerA->id]['invoice_count']);
        $this->assertSame(1,$rows[$sellerA->id]['customers_count']);
        $this->assertSame(3000,$rows[$sellerA->id]['total_sales']);
        $this->assertSame(500,$rows[$sellerA->id]['paid_amount']);
        $this->assertSame(2500,$rows[$sellerA->id]['remaining_amount']);
    }

    public function test_finance_reports_page_is_a_directory_without_dashboard_metrics(): void
    {
        $owner=User::factory()->create();$owner->assignRole(Role::findOrCreate('Owner','web'));
        $this->actingAs($owner)->get(route('finance.reports.index'))->assertOk()
            ->assertSee('گزارش‌های مالی')->assertSee('اسناد فروش و پورسانت فروشندگان')
            ->assertDontSee('داشبورد گزارش مالی')->assertDontSee('جمع پرداخت‌شده')
            ->assertSee(route('finance.seller-sales.index'),false)
            ->assertDontSee(route('finance.reports.sales-visitors'),false);
    }

    private function invoice(User $seller,Customer $customer,int $total,string $status=Invoice::STATUS_SHIPPED): Invoice
    {
        $order=PreinvoiceOrder::query()->create(['uuid'=>fake()->unique()->uuid(),'created_by'=>$seller->id,'seller_id'=>$seller->id,'status'=>PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,'customer_name'=>$customer->display_name,'customer_mobile'=>$customer->mobile,'customer_address'=>'تهران','province_id'=>1,'shipping_id'=>0]);
        return Invoice::query()->create(['uuid'=>fake()->unique()->uuid(),'preinvoice_order_id'=>$order->id,'seller_id'=>$seller->id,'customer_id'=>$customer->id,'customer_name'=>$customer->display_name,'total'=>$total,'subtotal'=>$total,'status'=>$status]);
    }
}
