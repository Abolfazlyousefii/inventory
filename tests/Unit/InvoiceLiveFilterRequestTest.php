<?php

namespace Tests\Unit;

use App\Http\Requests\InvoiceLiveFilterRequest;
use PHPUnit\Framework\TestCase;

class InvoiceLiveFilterRequestTest extends TestCase
{
    public function test_persian_arabic_and_english_order_codes_normalize_equally(): void
    {
        $this->assertSame('00481', InvoiceLiveFilterRequest::normalizeDigits('00481'));
        $this->assertSame('00481', InvoiceLiveFilterRequest::normalizeDigits('۰۰۴۸۱'));
        $this->assertSame('00481', InvoiceLiveFilterRequest::normalizeDigits('٠٠٤٨١'));
    }

    public function test_leading_zero_is_preserved_as_a_string(): void
    {
        $normalized = InvoiceLiveFilterRequest::normalizeDigits('۰۰۴۸۱');

        $this->assertIsString($normalized);
        $this->assertSame('0', $normalized[0]);
        $this->assertSame(5, strlen($normalized));
    }
}
