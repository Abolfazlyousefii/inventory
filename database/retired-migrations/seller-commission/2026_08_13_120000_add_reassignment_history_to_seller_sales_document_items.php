<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'seller_sales_document_items';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->addColumnsIfMissing();
        $this->addForeignKeysIfMissing();

        // Preserve valid historical rows from a previous partial/manual repair.
        // Every other pre-existing row must become the one active row for its invoice.
        DB::table(self::TABLE)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '<>', 'reassigned')
                    ->orWhereNotNull('active_invoice_id');
            })
            ->update([
                'status' => 'active',
                'active_invoice_id' => DB::raw('invoice_id'),
            ]);

        // MySQL may use the old UNIQUE(invoice_id) as the supporting index for
        // the existing invoice_id FK. Give that FK an independent left-most
        // index before dropping the unique index (avoids MySQL error 1553).
        if (! $this->hasIndex('ssd_items_invoice_id_index')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('invoice_id', 'ssd_items_invoice_id_index');
            });
        }

        if ($this->hasIndex('seller_sales_document_items_invoice_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique('seller_sales_document_items_invoice_unique');
            });
        }

        if (! $this->hasIndex('ssd_items_active_invoice_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('active_invoice_id', 'ssd_items_active_invoice_unique');
            });
        }

        if (! $this->hasIndex('ssd_items_status_idx')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('status', 'ssd_items_status_idx');
            });
        }

        if (! $this->hasForeignKey('active_invoice_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('active_invoice_id', 'ssd_items_active_invoice_fk')
                    ->references('id')->on('invoices')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Forward-only: historical rows can share invoice_id after deployment.
    }

    private function addColumnsIfMissing(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'status')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('status', 30)->default('active')->after('invoice_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'active_invoice_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('active_invoice_id')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'reassigned_to_seller_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('reassigned_to_seller_id')->nullable()->after('invoice_total_snapshot');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'reassigned_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->timestamp('reassigned_at')->nullable()->after('reassigned_to_seller_id');
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'reassignment_audit_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('reassignment_audit_id')->nullable()->after('reassigned_at');
            });
        }
    }

    private function addForeignKeysIfMissing(): void
    {
        if (! $this->hasForeignKey('reassigned_to_seller_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('reassigned_to_seller_id')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! $this->hasForeignKey('reassignment_audit_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('reassignment_audit_id')
                    ->references('id')->on('seller_reassignment_audits')->nullOnDelete();
            });
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
