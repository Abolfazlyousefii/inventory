<?php

namespace Tests\Feature;

use App\Models\CustomerLedger;
use App\Models\InvoicePayment;
use Tests\TestCase;

class AccountStatementPaymentDateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Schema::create('categories', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    private function renderDate(?string $paidAt, string $method = 'cash', bool $isPayment = true): \DOMXPath
    {
        $ledger = new CustomerLedger;
        $ledger->created_at = '2026-10-18 10:59:41';
        $ledger->updated_at = '2026-10-19 18:00:00';
        $payment = $isPayment ? new InvoicePayment(['method' => $method, 'paid_at' => $paidAt]) : null;
        if ($payment) {
            $payment->created_at = $ledger->created_at;
            $payment->updated_at = $ledger->updated_at;
        }
        $html = view('account-statements.partials.entry-date', compact('ledger', 'payment'))->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?><table><tr>'.$html.'</tr></table>');

        return new \DOMXPath($dom);
    }

    public function test_backdated_payment_shows_its_original_payment_date_not_entry_date(): void
    {
        $xpath = $this->renderDate('2026-10-06');
        $this->assertSame('1405/07/14', $xpath->evaluate('string(//td/span)'));
        $this->assertStringContainsString('1405/07/26', $xpath->evaluate('string(//td/small)'));
        $this->assertStringContainsString('10:59', $xpath->evaluate('string(//td/small)'));
        $this->assertStringNotContainsString('1405/07/27', $xpath->evaluate('string(//td)'));
    }

    public function test_cheque_uses_payment_date_and_keeps_registration_timestamp_separate(): void
    {
        $xpath = $this->renderDate('2026-10-06', 'cheque');
        $this->assertSame('1405/07/14', $xpath->evaluate('string(//td/span)'));
        $this->assertStringContainsString('ثبت در سامانه', $xpath->evaluate('string(//td/small)'));
    }

    public function test_legacy_payment_without_date_falls_back_to_original_entry_date(): void
    {
        $this->assertSame('1405/07/26', $this->renderDate(null)->evaluate('string(//td/span)'));
    }

    public function test_other_ledger_entries_keep_their_existing_date_and_time(): void
    {
        $xpath = $this->renderDate(null, 'cash', false);
        $this->assertSame('1405/07/26', $xpath->evaluate('string(//td/span)'));
        $this->assertSame('10:59', $xpath->evaluate('string(//td/small)'));
    }
}
