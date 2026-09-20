<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FOREIGN_KEYS = [
        'preinvoice_orders' => 'fk_preinvoice_orders_seller_id',
        'invoices' => 'fk_invoices_seller_id',
    ];

    public function up(): void
    {
        foreach (array_keys(self::FOREIGN_KEYS) as $table) {
            if (! Schema::hasColumn($table, 'seller_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('seller_id')->nullable();
                });
            }
            if (! $this->hasIndex($table, 'seller_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('seller_id', $table.'_seller_id_index'));
            }
        }

        // MySQL can retain this exact FK after a failed, non-transactional DDL run.
        // It is removed only when metadata proves it belongs to seller_id on this table.
        foreach (array_keys(self::FOREIGN_KEYS) as $table) {
            if ($this->foreignKey($table, 'seller_id') === '1') {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign('1'));
            }
        }

        $report = $this->backfill();

        foreach (self::FOREIGN_KEYS as $table => $constraint) {
            if ($this->foreignKey($table, 'seller_id') === null) {
                Schema::table($table, function (Blueprint $blueprint) use ($constraint): void {
                    $blueprint->foreign('seller_id', $constraint)->references('id')->on('users')->nullOnDelete();
                });
            }
        }

        $report['mismatched_linked_documents'] = DB::table('invoices as i')
            ->join('preinvoice_orders as p', 'p.id', '=', 'i.preinvoice_order_id')
            ->whereNotNull('i.seller_id')->whereNotNull('p.seller_id')
            ->whereColumn('i.seller_id', '<>', 'p.seller_id')->count();
        $report['generated_at'] = now()->toIso8601String();
        File::ensureDirectoryExists(storage_path('logs'));
        File::put(storage_path('logs/seller-ownership-backfill.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put(storage_path('logs/seller-ownership-backfill.txt'), collect($report)->map(fn ($value, $key) => $key.': '.$value)->implode(PHP_EOL));
    }

    public function down(): void
    {
        foreach (array_reverse(self::FOREIGN_KEYS, true) as $table => $constraint) {
            if ($this->foreignKey($table, 'seller_id') === $constraint) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($constraint));
            }
            if ($this->hasNamedIndex($table, $table.'_seller_id_index')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($table.'_seller_id_index'));
            }
            if (Schema::hasColumn($table, 'seller_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('seller_id'));
            }
        }
    }

    private function backfill(): array
    {
        $invalidPreinvoice = DB::table('preinvoice_orders as p')->whereNotNull('p.seller_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users as u')->whereColumn('u.id', 'p.seller_id'))->count();
        $invalidInvoice = DB::table('invoices as i')->whereNotNull('i.seller_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('users as u')->whereColumn('u.id', 'i.seller_id'))->count();

        DB::table('preinvoice_orders')->whereNotNull('seller_id')->whereNotIn('seller_id', DB::table('users')->select('id'))->update(['seller_id' => null]);
        DB::table('invoices')->whereNotNull('seller_id')->whereNotIn('seller_id', DB::table('users')->select('id'))->update(['seller_id' => null]);

        // Legacy contract: creator was the displayed/filtered seller before seller_id existed.
        // Only an internal ERP user explicitly marked as an active seller is accepted.
        $eligiblePreinvoiceIds = DB::table('preinvoice_orders as p')->join('users as u', 'u.id', '=', 'p.created_by')
            ->whereNull('p.seller_id')->where('u.is_active', true)->where('u.can_access_erp', true)->where('u.is_seller', true)->pluck('p.id');
        $preinvoiceBackfilled = $eligiblePreinvoiceIds->count();
        foreach ($eligiblePreinvoiceIds->chunk(500) as $ids) {
            DB::table('preinvoice_orders')->whereIn('id', $ids)->update(['seller_id' => DB::raw('created_by')]);
        }

        $eligibleInvoiceIds = DB::table('invoices as i')->join('preinvoice_orders as p', 'p.id', '=', 'i.preinvoice_order_id')
            ->whereNull('i.seller_id')->whereNotNull('p.seller_id')->pluck('i.id');
        $invoiceBackfilled = $eligibleInvoiceIds->count();
        foreach ($eligibleInvoiceIds->chunk(500) as $ids) {
            DB::table('invoices')->whereIn('id', $ids)->update([
                'seller_id' => DB::raw('(select p.seller_id from preinvoice_orders p where p.id = invoices.preinvoice_order_id)'),
            ]);
        }

        return [
            'preinvoices_backfilled' => $preinvoiceBackfilled,
            'invoices_backfilled' => $invoiceBackfilled,
            'preinvoices_without_valid_seller' => DB::table('preinvoice_orders')->whereNull('seller_id')->count(),
            'invoices_without_valid_seller' => DB::table('invoices')->whereNull('seller_id')->count(),
            'invalid_preinvoice_seller_ids_cleared' => $invalidPreinvoice,
            'invalid_invoice_seller_ids_cleared' => $invalidInvoice,
        ];
    }

    private function foreignKey(string $table, string $column): ?string
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (($foreign['columns'] ?? []) === [$column]) return $foreign['name'] ?? self::FOREIGN_KEYS[$table];
            }
            return null;
        }
        return DB::table('information_schema.KEY_COLUMN_USAGE')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->whereNotNull('REFERENCED_TABLE_NAME')
            ->where('REFERENCED_TABLE_NAME', 'users')->where('REFERENCED_COLUMN_NAME', 'id')->value('CONSTRAINT_NAME');
    }

    private function hasIndex(string $table, string $column): bool
    { return collect(Schema::getIndexes($table))->contains(fn ($index) => in_array($column, $index['columns'] ?? [], true)); }
    private function hasNamedIndex(string $table, string $name): bool
    { return collect(Schema::getIndexes($table))->contains(fn ($index) => ($index['name'] ?? null) === $name); }
};
