<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cny_exchange_rates', function (Blueprint $table): void {
            $table->string('symbol', 16)->nullable()->after('provider');
            $table->timestamp('fetched_at')->nullable()->after('quoted_at');
            $table->unique(['provider', 'symbol', 'quoted_at'], 'cny_rates_provider_symbol_quote_unique');
        });

        // A shared database record serializes reservations across workers/servers.
        Schema::create('navasan_api_guards', function (Blueprint $table): void {
            $table->string('provider', 30)->primary();
        });
        DB::table('navasan_api_guards')->insert(['provider' => 'navasan']);

        Schema::create('navasan_api_requests', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('attempted_at')->index();
            $table->string('status', 20)->default('started');
            $table->string('failure_code', 50)->nullable();
        });
    }

    public function down(): void
    {
        // If used in production, keep source provenance and request audit intact.
        // Roll back application code/feature flags instead of dropping historical data.
        if ((Schema::hasTable('navasan_api_requests') && DB::table('navasan_api_requests')->exists())
            || (Schema::hasColumn('cny_exchange_rates', 'symbol')
                && DB::table('cny_exchange_rates')->where('provider', 'navasan')->whereNotNull('symbol')->exists())) {
            throw new \RuntimeException('Navasan rate history exists: refusing destructive schema rollback.');
        }
        Schema::dropIfExists('navasan_api_requests');
        Schema::dropIfExists('navasan_api_guards');
        Schema::table('cny_exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('cny_rates_provider_symbol_quote_unique');
            $table->dropColumn(['symbol', 'fetched_at']);
        });
    }
};
