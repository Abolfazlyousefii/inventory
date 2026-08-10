<?php

namespace Tests\Feature;

use Tests\TestCase;

class SalesReturnNumericDocumentNumberTest extends TestCase
{
    private function serviceSource(): string
    {
        return file_get_contents(app_path('Services/SalesReturnService.php'));
    }

    public function test_old_sr_numbers_remain_unchanged(): void
    {
        $service = $this->serviceSource();
        $this->assertStringNotContainsString('update([\'document_number\'', $service);
        $this->assertStringNotContainsString('SR-000001', $service);
        $this->assertStringNotContainsString('SR-000002', $service);
    }

    public function test_next_number_continues_after_old_sr_numbers(): void
    {
        $this->assertStringContainsString("preg_match('/^SR-(\\d+)$/", $this->serviceSource());
    }

    public function test_numeric_and_old_formats_share_one_sequence(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString("preg_match('/^\\d+$/", $service);
        $this->assertStringContainsString("preg_match('/^SR-(\\d+)$/", $service);
    }

    public function test_higher_numeric_number_wins(): void
    {
        $this->assertStringContainsString('max((int) $sequence->last_number, (int) $maxExisting) + 1', $this->serviceSource());
    }

    public function test_invalid_formats_are_ignored(): void
    {
        $this->assertStringContainsString('return 0;', $this->serviceSource());
    }

    public function test_consecutive_creations_are_ordered(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('lockForUpdate()', $service);
        $this->assertStringContainsString("'last_number' => \$next", $service);
    }

    public function test_stale_sequence_recovers_from_documents(): void
    {
        $service = $this->serviceSource();
        $this->assertStringContainsString('pluck(\'document_number\')', $service);
        $this->assertStringContainsString("return (string) \$next;", $service);
    }
}
