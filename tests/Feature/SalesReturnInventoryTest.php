<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocumentItem;
use Tests\TestCase;
class SalesReturnInventoryTest extends TestCase { public function test_item_conditions_are_separate_from_destination(): void { $this->assertSame('healthy', SalesReturnDocumentItem::CONDITION_HEALTHY); $this->assertSame('damaged', SalesReturnDocumentItem::CONDITION_DAMAGED); } }
