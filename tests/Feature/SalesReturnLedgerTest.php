<?php
namespace Tests\Feature;
use App\Models\SalesReturnDocument;
use Tests\TestCase;
class SalesReturnLedgerTest extends TestCase { public function test_document_number_column_is_fillable_for_idempotent_ledger_reference(): void { $this->assertContains('document_number', (new SalesReturnDocument())->getFillable()); } }
