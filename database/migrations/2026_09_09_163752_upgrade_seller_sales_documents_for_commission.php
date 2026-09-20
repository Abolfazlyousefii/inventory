<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'seller_sales_documents';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addColumnIfMissing('total_commission_amount', fn (Blueprint $table) => $table->bigInteger('total_commission_amount')->default(0)->after('total_sales_amount'));
        $this->addColumnIfMissing('total_adjustment_amount', fn (Blueprint $table) => $table->bigInteger('total_adjustment_amount')->default(0)->after('total_commission_amount'));
        $this->addColumnIfMissing('bonus_amount', fn (Blueprint $table) => $table->bigInteger('bonus_amount')->default(0)->after('total_adjustment_amount'));
        $this->addColumnIfMissing('bonus_reason', fn (Blueprint $table) => $table->string('bonus_reason', 1000)->nullable()->after('bonus_amount'));
        $this->addColumnIfMissing('cash_collected_amount', fn (Blueprint $table) => $table->bigInteger('cash_collected_amount')->default(0)->after('bonus_reason'));
        $this->addColumnIfMissing('net_commission_amount', fn (Blueprint $table) => $table->bigInteger('net_commission_amount')->default(0)->after('cash_collected_amount'));
        $this->addColumnIfMissing('status', fn (Blueprint $table) => $table->string('status', 20)->default('draft')->after('net_commission_amount'));
        $this->addColumnIfMissing('confirmed_by', fn (Blueprint $table) => $table->unsignedBigInteger('confirmed_by')->nullable()->after('status'));
        $this->addColumnIfMissing('confirmed_at', fn (Blueprint $table) => $table->timestamp('confirmed_at')->nullable()->after('confirmed_by'));
        $this->addColumnIfMissing('finalized_by', fn (Blueprint $table) => $table->unsignedBigInteger('finalized_by')->nullable()->after('confirmed_at'));
        $this->addColumnIfMissing('finalized_at', fn (Blueprint $table) => $table->timestamp('finalized_at')->nullable()->after('finalized_by'));
        $this->addColumnIfMissing('missing_rate_count', fn (Blueprint $table) => $table->unsignedInteger('missing_rate_count')->default(0)->after('finalized_at'));

        if (! $this->hasForeignKey('confirmed_by')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete());
        }

        if (! $this->hasForeignKey('finalized_by')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->foreign('finalized_by')->references('id')->on('users')->nullOnDelete());
        }

        $this->addIndexIfMissing('seller_sales_documents_status_index', fn (Blueprint $table) => $table->index('status'));
        $this->addIndexIfMissing('ssd_status_seller_period_idx', fn (Blueprint $table) => $table->index(['status', 'seller_id', 'period_from', 'period_to'], 'ssd_status_seller_period_idx'));
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasForeignKey('confirmed_by')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(['confirmed_by']));
        }

        if ($this->hasForeignKey('finalized_by')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign(['finalized_by']));
        }

        if ($this->hasIndex('seller_sales_documents_status_index')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(['status']));
        }

        if ($this->hasIndex('ssd_status_seller_period_idx')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex('ssd_status_seller_period_idx'));
        }

        foreach ([
            'missing_rate_count',
            'finalized_at',
            'finalized_by',
            'confirmed_at',
            'confirmed_by',
            'status',
            'net_commission_amount',
            'cash_collected_amount',
            'bonus_reason',
            'bonus_amount',
            'total_adjustment_amount',
            'total_commission_amount',
        ] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $callback): void
    {
        if (! Schema::hasColumn(self::TABLE, $column)) {
            Schema::table(self::TABLE, $callback);
        }
    }

    private function addIndexIfMissing(string $indexName, callable $callback): void
    {
        if (! $this->hasIndex($indexName)) {
            Schema::table(self::TABLE, $callback);
        }
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    private function hasForeignKey(string $column): bool
    {
        return collect(Schema::getForeignKeys(self::TABLE))
            ->contains(fn (array $foreign): bool => ($foreign['columns'] ?? []) === [$column]);
    }
};
