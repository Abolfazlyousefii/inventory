<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'seller_sales_document_items';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addColumnIfMissing('product_id', fn (Blueprint $table) => $table->unsignedBigInteger('product_id')->nullable()->after('invoice_total_snapshot'));
        $this->addColumnIfMissing('product_variant_id', fn (Blueprint $table) => $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id'));
        $this->addColumnIfMissing('product_name_snapshot', fn (Blueprint $table) => $table->string('product_name_snapshot', 500)->nullable()->after('product_variant_id'));
        $this->addColumnIfMissing('variant_name_snapshot', fn (Blueprint $table) => $table->string('variant_name_snapshot', 500)->nullable()->after('product_name_snapshot'));
        $this->addColumnIfMissing('quantity_snapshot', fn (Blueprint $table) => $table->unsignedInteger('quantity_snapshot')->default(0)->after('variant_name_snapshot'));
        $this->addColumnIfMissing('rate_snapshot', fn (Blueprint $table) => $table->string('rate_snapshot', 20)->nullable()->after('quantity_snapshot'));
        $this->addColumnIfMissing('rate_source_type', fn (Blueprint $table) => $table->string('rate_source_type', 30)->nullable()->after('rate_snapshot'));
        $this->addColumnIfMissing('rate_source_id', fn (Blueprint $table) => $table->unsignedBigInteger('rate_source_id')->nullable()->after('rate_source_type'));
        $this->addColumnIfMissing('rate_rule_id', fn (Blueprint $table) => $table->unsignedBigInteger('rate_rule_id')->nullable()->after('rate_source_id'));
        $this->addColumnIfMissing('item_net_amount', fn (Blueprint $table) => $table->bigInteger('item_net_amount')->default(0)->after('rate_rule_id'));
        $this->addColumnIfMissing('commission_amount', fn (Blueprint $table) => $table->bigInteger('commission_amount')->default(0)->after('item_net_amount'));
        $this->addColumnIfMissing('missing_rate', fn (Blueprint $table) => $table->boolean('missing_rate')->default(false)->after('commission_amount'));
        $this->addColumnIfMissing('calculation_version', fn (Blueprint $table) => $table->unsignedTinyInteger('calculation_version')->default(1)->after('missing_rate'));

        $this->addIndexIfMissing('ssd_items_product_idx', fn (Blueprint $table) => $table->index('product_id', 'ssd_items_product_idx'));
        $this->addIndexIfMissing('ssd_items_product_variant_idx', fn (Blueprint $table) => $table->index('product_variant_id', 'ssd_items_product_variant_idx'));
        $this->addIndexIfMissing('ssd_items_rate_source_idx', fn (Blueprint $table) => $table->index(['rate_source_type', 'rate_source_id'], 'ssd_items_rate_source_idx'));
        $this->addIndexIfMissing('ssd_items_missing_rate_idx', fn (Blueprint $table) => $table->index('missing_rate', 'ssd_items_missing_rate_idx'));
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ([
            'ssd_items_missing_rate_idx',
            'ssd_items_rate_source_idx',
            'ssd_items_product_variant_idx',
            'ssd_items_product_idx',
        ] as $indexName) {
            if ($this->hasIndex($indexName)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($indexName));
            }
        }

        foreach ([
            'calculation_version',
            'missing_rate',
            'commission_amount',
            'item_net_amount',
            'rate_rule_id',
            'rate_source_id',
            'rate_source_type',
            'rate_snapshot',
            'quantity_snapshot',
            'variant_name_snapshot',
            'product_name_snapshot',
            'product_variant_id',
            'product_id',
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
};
