<?php

namespace Tests\Unit;

use App\Models\SalesReturnDocument;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\TestCase;

class SalesReturnDateOnlyTest extends TestCase
{
    public function test_expected_jalali_date_maps_to_gregorian_date(): void
    {
        $gregorian = Jalalian::fromFormat('Y/m/d', '1405/05/04')
            ->toCarbon()
            ->format('Y-m-d');

        $this->assertSame('2026-07-26', $gregorian);
    }

    public function test_model_and_migration_use_date_instead_of_datetime(): void
    {
        $model = new SalesReturnDocument;
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_07_12_000001_create_sales_return_documents_table.php'
        );

        $this->assertSame('date', $model->getCasts()['external_invoice_date']);
        $this->assertStringContainsString("\$table->date('external_invoice_date')", $migration);
        $this->assertStringNotContainsString("\$table->dateTime('external_invoice_date')", $migration);
    }

    public function test_return_form_forces_official_date_only_picker_options(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/vouchers/return-from-sale/create.blade.php'
        );

        $this->assertStringContainsString('data-jdp-only-date', $view);
        $this->assertStringContainsString('time:false', $view);
        $this->assertStringContainsString('hideAfterChange:true', $view);
        $this->assertStringContainsString('updateOptions(dateOnlyOptions)', $view);
        $this->assertStringContainsString("date_format:Y-m-d", file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Requests/StoreSalesReturnRequest.php'
        ));
        $this->assertStringContainsString('تاریخ را به‌شکل ۱۴۰۵/۰۵/۰۴ وارد کنید.', $view);
    }

    public function test_date_display_normalizes_legacy_time_without_rendering_it(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/vouchers/return-from-sale/create.blade.php'
        );

        $this->assertStringContainsString('function normalizeGregorianDateInput(value)', $view);
        $this->assertStringContainsString("hidden.value=dateOnly||''", $view);
        $this->assertStringContainsString("fa.value=dateOnly?formatJalaliDate(dateOnly):''", $view);
        $this->assertStringContainsString("fa.value=parsed?formatJalaliDate(parsed):''", $view);
    }
}
