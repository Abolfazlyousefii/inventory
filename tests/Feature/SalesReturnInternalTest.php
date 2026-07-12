<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocumentItem;
use Tests\TestCase;
class SalesReturnInternalTest extends TestCase { public function test_invoice_item_source_constant_is_stable(): void { $this->assertSame('invoice_item', SalesReturnDocumentItem::SOURCE_INVOICE_ITEM); } }
