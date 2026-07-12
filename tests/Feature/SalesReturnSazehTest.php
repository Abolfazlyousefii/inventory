<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocument;
use Tests\TestCase;
class SalesReturnSazehTest extends TestCase { public function test_sazeh_source_constant_is_stable(): void { $this->assertSame('sazeh_hesab', SalesReturnDocument::SOURCE_SAZEH_HESAB); } }
