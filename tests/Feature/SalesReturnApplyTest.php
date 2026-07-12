<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocument;
use Tests\TestCase;
class SalesReturnApplyTest extends TestCase { public function test_applied_status_constant_is_stable(): void { $this->assertSame('applied', SalesReturnDocument::STATUS_APPLIED); } }
