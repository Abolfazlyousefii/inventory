<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerApiController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreinvoiceCustomerNameSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'first_name', 'last_name', 'mobile', 'address', 'postal_code', 'extra_description', 'reservation_tier'] as $f) {
                $t->string($f)->nullable();
            }$t->integer('opening_balance')->default(0);
            $t->integer('province_id')->nullable();
            $t->integer('city_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('customer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->integer('customer_id');
            $t->string('type');
            $t->integer('amount');
            $t->string('reference_type')->nullable();
            $t->integer('reference_id')->nullable();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('status');
        });
    }

    private function search(string $q): array
    {
        return app(CustomerApiController::class)->search(Request::create('/preinvoice/api/customers', 'GET', ['q' => $q]))->getData(true)['data']['customers'];
    }

    public function test_persian_and_arabic_letters_match_both_stored_spellings(): void
    {
        DB::table('customers')->insert([['first_name' => 'علي', 'last_name' => 'كريمي', 'mobile' => '09121111111'], ['first_name' => 'علی', 'last_name' => 'کریمی', 'mobile' => '09122222222']]);
        foreach (['علی کریمی', 'علي كريمي', 'علی كریمي'] as $q) {
            $this->assertSame([2, 1], array_column($this->search($q), 'id'));
        }$this->assertSame('علي', DB::table('customers')->where('id', 1)->value('first_name'));
    }

    public function test_name_only_customer_is_found_and_original_display_is_preserved(): void
    {
        DB::table('customers')->insert(['name' => 'محمود علي كريمي', 'mobile' => '09121111111']);
        $results = $this->search('محمود علی کریمی');
        $this->assertCount(1, $results);
        $this->assertSame('محمود علي كريمي', $results[0]['name']);
    }

    public function test_mobile_digits_empty_search_and_deleted_customers_keep_existing_behavior(): void
    {
        DB::table('customers')->insert([['name' => 'مشتری', 'mobile' => '09123456789', 'deleted_at' => null], ['name' => 'مشتری حذف شده', 'mobile' => '09123456788', 'deleted_at' => now()]]);
        foreach (['09123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩'] as $q) {
            $this->assertSame([1], array_column($this->search($q), 'id'));
        }$this->assertSame([], $this->search(''));
        $this->assertSame([], $this->search('مشتری حذف شده'));
    }

    public function test_search_is_read_only_and_limit_stays_twenty(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            DB::table('customers')->insert(['name' => 'علي نمونه '.$i]);
        }DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(20, $this->search('علی'));
        foreach (DB::getQueryLog() as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)/i',$q['query']);
        }
    }
}
