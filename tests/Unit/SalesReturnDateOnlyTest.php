<?php

namespace Tests\Unit;

use App\Models\SalesReturnDocument;
use Morilog\Jalali\Jalalian;
use Tests\TestCase;

class SalesReturnDateOnlyTest extends TestCase
{
    public function test_expected_jalali_date_maps_to_gregorian_date(): void
    {
        $gregorian = Jalalian::fromFormat('Y/m/d', '1405/05/04')
            ->toCarbon()
            ->format('Y-m-d');

        $this->assertSame('2026-07-26', $gregorian);
    }

    public function test_model_casts_external_invoice_date_to_date_only(): void
    {
        $model = new SalesReturnDocument;

        $this->assertSame('date', $model->getCasts()['external_invoice_date']);

        // A date cast should discard any legacy time component on retrieval.
        $model->external_invoice_date = '2026-07-26 14:35:20';

        $this->assertSame('2026-07-26', $model->external_invoice_date->format('Y-m-d'));
        $this->assertSame('00:00:00', $model->external_invoice_date->format('H:i:s'));
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
