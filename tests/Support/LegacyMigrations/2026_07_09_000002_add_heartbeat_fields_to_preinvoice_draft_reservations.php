<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('preinvoice_draft_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('expires_at');
                $table->index('last_seen_at');
            }
            if (! Schema::hasColumn('preinvoice_draft_reservations', 'browser_session_id')) {
                $table->string('browser_session_id')->nullable()->after('last_seen_at');
                $table->index('browser_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('preinvoice_draft_reservations', function (Blueprint $table) {
            if (Schema::hasColumn('preinvoice_draft_reservations', 'browser_session_id')) {
                $table->dropIndex(['browser_session_id']);
                $table->dropColumn('browser_session_id');
            }
            if (Schema::hasColumn('preinvoice_draft_reservations', 'last_seen_at')) {
                $table->dropIndex(['last_seen_at']);
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
