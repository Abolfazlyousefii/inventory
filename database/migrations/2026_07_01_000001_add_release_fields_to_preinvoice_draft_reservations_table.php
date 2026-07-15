<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_draft_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('converted_at');
            }
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'released_by')) {
                $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'release_reason')) {
                $table->string('release_reason')->nullable()->after('released_by');
            }
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'release_note')) {
                $table->text('release_note')->nullable()->after('release_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_draft_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_draft_reservations', 'released_by')) {
                $table->dropConstrainedForeignId('released_by');
            }
            foreach (['release_note', 'release_reason', 'released_at'] as $column) {
                if (Schema::hasColumn('preinvoice_draft_reservations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
