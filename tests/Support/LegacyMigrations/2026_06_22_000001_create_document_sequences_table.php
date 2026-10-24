<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INITIAL_INVOICE_SEQUENCE_FLOOR = 117;

    public function up(): void
    {
        if (! Schema::hasTable('document_sequences')) {
            Schema::create('document_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('type')->unique();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        $lastNumber = $this->currentMaxOfficialInvoiceNumber();

        DB::table('document_sequences')->updateOrInsert(
            ['type' => 'invoice'],
            ['last_number' => $lastNumber, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }

    private function currentMaxOfficialInvoiceNumber(): int
    {
        $max = self::INITIAL_INVOICE_SEQUENCE_FLOOR;

        foreach (['invoices', 'preinvoice_orders'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            $value = DB::table($table)
                ->pluck('uuid')
                ->filter(fn ($code) => is_string($code) && preg_match('/^\d{5}$/', $code) === 1)
                ->map(fn ($code) => (int) $code)
                ->max() ?? 0;

            $max = max($max, (int) $value);
        }

        return $max;
    }
};
