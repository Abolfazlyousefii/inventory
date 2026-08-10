<?php

namespace Tests\Feature;

use App\Http\Requests\SalesReturnIndexRequest;
use App\Models\Customer;
use App\Models\SalesReturnDocument;
use App\Services\SalesReturnReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Morilog\Jalali\Jalalian;
use Tests\TestCase;

class SalesReturnDateTimeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_jalali_date_time_range_and_customer_filter_are_shared_by_list_and_customer_print(): void
    {
        $selectedCustomer = Customer::query()->create([
            'first_name' => 'مشتری',
            'last_name' => 'انتخاب‌شده',
            'mobile' => '09120000001',
        ]);
        $otherCustomer = Customer::query()->create([
            'first_name' => 'مشتری',
            'last_name' => 'دیگر',
            'mobile' => '09120000002',
        ]);

        $expected = $this->createDocument(
            '50001',
            $selectedCustomer,
            SalesReturnDocument::STATUS_APPLIED,
            '1405/05/06 09:45:00'
        );
        $this->createDocument(
            '50002',
            $selectedCustomer,
            SalesReturnDocument::STATUS_APPLIED,
            '1405/05/06 11:00:00'
        );
        $this->createDocument(
            '50003',
            $otherCustomer,
            SalesReturnDocument::STATUS_APPLIED,
            '1405/05/06 09:45:00'
        );
        $this->createDocument(
            '50004',
            $selectedCustomer,
            SalesReturnDocument::STATUS_CANCELLED,
            '1405/05/06 10:00:00'
        );

        $request = SalesReturnIndexRequest::create('/returns', 'GET', [
            'customer_id' => $selectedCustomer->id,
            'date_from' => '۱۴۰۵/۰۵/۰۶ ۰۹:۳۰:۰۰',
            'date_to' => '۱۴۰۵/۰۵/۰۶ ۱۰:۳۰:۰۰',
        ]);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $filters = $request->filters();
        $service = app(SalesReturnReportService::class);
        $listRows = collect($service->getPaginatedRows($filters)->items());
        $customerPrintRows = $service->getPdfRows($filters);

        $this->assertSame('1405/05/06 09:30:00', $filters['date_from']);
        $this->assertSame('1405/05/06 10:30:00', $filters['date_to']);
        $this->assertSame([$expected->id], $listRows->pluck('source_id')->all());
        $this->assertSame([$expected->id], $customerPrintRows->pluck('source_id')->all());
        $this->assertFalse($listRows->contains('status', SalesReturnDocument::STATUS_CANCELLED));
    }

    public function test_print_buttons_build_their_query_from_current_filter_form(): void
    {
        $blade = file_get_contents(resource_path('views/vouchers/return-from-sale/index.blade.php'));

        $this->assertStringContainsString('const qs=cleanParams().toString()', $blade);
        $this->assertStringNotContainsString('const qs=window.location.search||\'\'', $blade);
    }

    private function createDocument(
        string $number,
        Customer $customer,
        string $status,
        string $jalaliDateTime
    ): SalesReturnDocument {
        $date = Jalalian::fromFormat('Y/m/d H:i:s', $jalaliDateTime)->toCarbon();
        $document = SalesReturnDocument::query()->create([
            'document_number' => $number,
            'source_type' => SalesReturnDocument::SOURCE_SAZEH_HESAB,
            'status' => $status,
            'customer_id' => $customer->id,
            'total_quantity' => 1,
            'total_refund_amount' => 1000,
            'applied_at' => $date,
        ]);
        $document->forceFill([
            'created_at' => $date,
            'updated_at' => $date,
        ])->save();

        return $document;
    }
}
