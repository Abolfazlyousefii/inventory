<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('warehouse_inbound_receipts') || ! Schema::hasTable('warehouse_inbound_receipt_items')) {
            return;
        }

        Schema::table('warehouse_inbound_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_inbound_receipts', 'operation_key')) {
                $table->string('operation_key', 120)->nullable()->after('source_id');
            }
        });

        Schema::table('warehouse_inbound_receipt_items', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouse_inbound_receipt_items', 'reason')) {
                $table->string('reason', 60)->nullable()->after('condition');
            }
            if (! Schema::hasColumn('warehouse_inbound_receipt_items', 'source_meta')) {
                $table->json('source_meta')->nullable()->after('reason');
            }
        });

        DB::table('warehouse_inbound_receipts')
            ->where('source_type', 'finance_adjustment')
            ->update(['source_type' => 'invoice_adjustment']);

        $fixedKeys = [];
        DB::table('warehouse_inbound_receipts')
            ->whereNull('operation_key')
            ->orderBy('id')
            ->get(['id', 'source_type', 'source_id'])
            ->each(function ($receipt) use (&$fixedKeys): void {
                $base = match ((string) $receipt->source_type) {
                    'sales_return' => 'initial',
                    'invoice_cancel' => 'cancel',
                    default => 'legacy-'.$receipt->id,
                };
                $group = $receipt->source_type.':'.($receipt->source_id ?? 'null').':'.$base;
                $operationKey = isset($fixedKeys[$group]) ? $base.'-legacy-'.$receipt->id : $base;
                $fixedKeys[$group] = true;

                DB::table('warehouse_inbound_receipts')->where('id', $receipt->id)->update([
                    'operation_key' => $operationKey,
                ]);
            });

        if (! $this->hasIndex('warehouse_inbound_receipts', 'wir_source_operation_unique')) {
            Schema::table('warehouse_inbound_receipts', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'operation_key'], 'wir_source_operation_unique');
            });
        }

        /*
         * The base 2026_08_19 migration already creates `reason` with Laravel's
         * automatic index name:
         * warehouse_inbound_receipt_items_reason_index
         *
         * Do not create a second equivalent index on fresh databases.
         * Legacy databases that have the column but no index still receive the
         * explicit hardened index.
         */
        if (
            ! $this->hasIndex('warehouse_inbound_receipt_items', 'wiri_reason_idx')
            && ! $this->hasIndex('warehouse_inbound_receipt_items', 'warehouse_inbound_receipt_items_reason_index')
        ) {
            Schema::table('warehouse_inbound_receipt_items', function (Blueprint $table) {
                $table->index('reason', 'wiri_reason_idx');
            });
        }

        if (! $this->hasIndex('warehouse_inbound_receipt_items', 'wiri_stock_movement_unique')) {
            DB::table('warehouse_inbound_receipt_items')
                ->whereNotNull('stock_movement_id')
                ->orderBy('id')
                ->get(['id', 'stock_movement_id'])
                ->groupBy('stock_movement_id')
                ->filter(fn ($items) => $items->count() > 1)
                ->each(function ($items): void {
                    DB::table('warehouse_inbound_receipt_items')
                        ->whereIn('id', $items->pluck('id')->slice(1)->all())
                        ->update(['stock_movement_id' => null]);
                });

            Schema::table('warehouse_inbound_receipt_items', function (Blueprint $table) {
                $table->unique('stock_movement_id', 'wiri_stock_movement_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('warehouse_inbound_receipts') || ! Schema::hasTable('warehouse_inbound_receipt_items')) {
            return;
        }

        if ($this->hasIndex('warehouse_inbound_receipt_items', 'wiri_stock_movement_unique')) {
            Schema::table(
                'warehouse_inbound_receipt_items',
                fn (Blueprint $table) => $table->dropUnique('wiri_stock_movement_unique')
            );
        }

        if ($this->hasIndex('warehouse_inbound_receipt_items', 'wiri_reason_idx')) {
            Schema::table(
                'warehouse_inbound_receipt_items',
                fn (Blueprint $table) => $table->dropIndex('wiri_reason_idx')
            );
        }

        /*
         * SQLite refuses DROP COLUMN while ANY index still references that
         * column. The base inbound migration creates this automatic index,
         * so it must be removed before dropping `reason`.
         *
         * On MariaDB this is also safe and makes rollback deterministic.
         */
        if ($this->hasIndex('warehouse_inbound_receipt_items', 'warehouse_inbound_receipt_items_reason_index')) {
            Schema::table(
                'warehouse_inbound_receipt_items',
                fn (Blueprint $table) => $table->dropIndex('warehouse_inbound_receipt_items_reason_index')
            );
        }

        if ($this->hasIndex('warehouse_inbound_receipts', 'wir_source_operation_unique')) {
            Schema::table(
                'warehouse_inbound_receipts',
                fn (Blueprint $table) => $table->dropUnique('wir_source_operation_unique')
            );
        }

        DB::table('warehouse_inbound_receipts')
            ->where('source_type', 'invoice_adjustment')
            ->update(['source_type' => 'finance_adjustment']);

        Schema::table('warehouse_inbound_receipt_items', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['reason', 'source_meta'],
                fn (string $column) => Schema::hasColumn('warehouse_inbound_receipt_items', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('warehouse_inbound_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_inbound_receipts', 'operation_key')) {
                $table->dropColumn('operation_key');
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name
        );
    }
};
