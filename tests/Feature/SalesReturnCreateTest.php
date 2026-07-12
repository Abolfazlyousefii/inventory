<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocument;
use Tests\TestCase;
class SalesReturnCreateTest extends TestCase { public function test_document_labels_include_draft_and_sources(): void { $this->assertSame('پیش‌نویس', SalesReturnDocument::statusLabels()[SalesReturnDocument::STATUS_DRAFT]); $this->assertSame('فاکتور سازه‌حساب', SalesReturnDocument::sourceTypeLabels()[SalesReturnDocument::SOURCE_SAZEH_HESAB]); } }
