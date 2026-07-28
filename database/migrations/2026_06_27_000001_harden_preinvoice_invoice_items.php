<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preinvoice_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('preinvoice_order_items', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('price');
            }
            if (! Schema::hasColumn('preinvoice_order_items', 'line_discount_amount')) {
                $table->unsignedBigInteger('line_discount_amount')->default(0)->after('sort_order');
            }
        });

        $this->backfillSortOrder('preinvoice_order_items', 'preinvoice_order_id', 'oid');

        Schema::table('preinvoice_order_items', function (Blueprint $table) {
            $table->index(['preinvoice_order_id', 'sort_order'], 'preinvoice_items_order_idx');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('line_total');
            }
            if (! Schema::hasColumn('invoice_items', 'line_discount_amount')) {
                $table->unsignedBigInteger('line_discount_amount')->default(0)->after('sort_order');
            }
        });

        $this->backfillSortOrder('invoice_items', 'invoice_id', 'iid');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->index(['invoice_id', 'sort_order'], 'invoice_items_order_idx');
        });

        $duplicatePreinvoices = DB::table('invoices')
            ->whereNotNull('preinvoice_order_id')
            ->select('preinvoice_order_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('preinvoice_order_id')
            ->having('aggregate', '>', 1)
            ->exists();

        if (! $duplicatePreinvoices) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unique('preinvoice_order_id', 'invoices_preinvoice_order_id_unique');
            });
        }

        if (! Schema::hasTable('invoice_edit_audits')) {
            Schema::create('invoice_edit_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('reason');
                $table->json('changes_before')->nullable();
                $table->json('changes_after')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_edit_audits');
    }

    private function backfillSortOrder(string $table, string $parentColumn, string $mysqlVariable): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("SET @rn := 0, @{$mysqlVariable} := 0");
            DB::statement(
                "UPDATE {$table} target
                 JOIN (
                    SELECT id,
                           (@rn := IF(@{$mysqlVariable} = {$parentColumn}, @rn + 1, 1)) AS rn,
                           (@{$mysqlVariable} := {$parentColumn})
                    FROM {$table}
                    ORDER BY {$parentColumn}, id
                 ) numbered ON numbered.id = target.id
                 SET target.sort_order = IF(target.sort_order = 0, numbered.rn, target.sort_order)"
            );

            return;
        }

        $nextByParent = [];

        DB::table($table)
            ->orderBy($parentColumn)
            ->orderBy('id')
            ->get(['id', $parentColumn, 'sort_order'])
            ->each(function (object $row) use ($table, $parentColumn, &$nextByParent): void {
                $parentId = (string) $row->{$parentColumn};
                $nextByParent[$parentId] = ($nextByParent[$parentId] ?? 0) + 1;

                if ((int) $row->sort_order === 0) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['sort_order' => $nextByParent[$parentId]]);
                }
            });
    }
};
