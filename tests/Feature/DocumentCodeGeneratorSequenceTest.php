<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Support\DocumentCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('never reissues a reserved number even before its document has been saved', function () {
    DB::table('document_sequences')->where('type', 'invoice')->update(['last_number' => 2052]);
    expect(DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class))->toBe('02053')
        ->and(DocumentCodeGenerator::generateUnique5DigitCode(Invoice::class))->toBe('02054')
        ->and(DocumentCodeGenerator::generateUnique5DigitCode(PreinvoiceOrder::class))->toBe('02055');
});

it('preserves the counter creation date and advances beyond existing documents', function () {
    DB::table('document_sequences')->where('type', 'invoice')->update(['last_number' => 2000, 'created_at' => '2026-01-01 00:00:00']);
    Invoice::create(['uuid' => '02500', 'total' => 1000, 'subtotal' => 1000]);
    expect(DocumentCodeGenerator::nextInvoiceCode())->toBe('02501')
        ->and(DB::table('document_sequences')->where('type', 'invoice')->value('created_at'))->toBe('2026-01-01 00:00:00');
});

it('does not allocate an official number without its locking counter table', function () {
    Schema::drop('document_sequences');
    expect(fn () => DocumentCodeGenerator::nextInvoiceCode())->toThrow(RuntimeException::class);
});
