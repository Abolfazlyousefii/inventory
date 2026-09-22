<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VariantReferenceDiscoveryService
{
    /** @var array<string,array<int,string>> */
    public const KNOWN_REFERENCES = [
        'purchase_items' => ['product_variant_id'],
        'invoice_items' => ['variant_id'],
        'preinvoice_order_items' => ['variant_id'],
        'preinvoice_draft_reservations' => ['variant_id'],
        'warehouse_stocks' => ['product_variant_id'],
        'stock_movements' => ['product_variant_id'],
        'warehouse_transfer_items' => ['product_variant_id'],
        'stock_count_document_items' => ['product_variant_id'],
        'warehouse_location_stocks' => ['product_variant_id'],
        'warehouse_location_movements' => ['product_variant_id'],
        'warehouse_review_item_logs' => ['product_variant_id'],
        'price_change_document_items' => ['product_variant_id'],
        'product_deactivation_documents' => ['variant_id'],
        'product_deactivation_document_items' => ['variant_id'],
        'invoice_collection_revision_items' => ['product_variant_id'],
        'sales_return_document_items' => ['product_variant_id', 'created_variant_id'],
        'commission_campaign_targets' => ['product_variant_id'],
        'commission_rate_revisions' => ['product_variant_id'],
        'commission_ledger_entries' => ['product_variant_id'],
        'warehouse_inbound_receipt_items' => ['product_variant_id'],
        'seller_sales_document_items' => ['product_variant_id'],
    ];

    /**
     * @return Collection<int,array{table:string,column:string,known:bool}>
     */
    public function discover(): Collection
    {
        $driver = DB::connection()->getDriverName();
        $pairs = $driver === 'sqlite' ? $this->sqlitePairs() : $this->mysqlPairs();

        return collect($pairs)
            ->unique(fn (array $row) => $row['table'].'.'.$row['column'])
            ->map(function (array $row): array {
                $row['known'] = in_array($row['column'], self::KNOWN_REFERENCES[$row['table']] ?? [], true);

                return $row;
            })
            ->sortBy(fn (array $row) => $row['table'].'.'.$row['column'])
            ->values();
    }

    /** @return array<int,array{table:string,column:string}> */
    private function sqlitePairs(): array
    {
        $tables = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            ->pluck('name');
        $pairs = [];

        foreach ($tables as $table) {
            $quoted = str_replace('"', '""', (string) $table);
            $columns = collect(DB::select('PRAGMA table_info("'.$quoted.'")'))->pluck('name');
            foreach ($columns->intersect(['variant_id', 'product_variant_id', 'created_variant_id']) as $column) {
                $pairs[] = ['table' => (string) $table, 'column' => (string) $column];
            }
            foreach (DB::select('PRAGMA foreign_key_list("'.$quoted.'")') as $foreignKey) {
                if ((string) $foreignKey->table === 'product_variants' && (string) $foreignKey->to === 'id') {
                    $pairs[] = ['table' => (string) $table, 'column' => (string) $foreignKey->from];
                }
            }
        }

        return $pairs;
    }

    /** @return array<int,array{table:string,column:string}> */
    private function mysqlPairs(): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::select(
            "select distinct c.TABLE_NAME as table_name, c.COLUMN_NAME as column_name
             from information_schema.COLUMNS c
             left join information_schema.KEY_COLUMN_USAGE k
               on k.TABLE_SCHEMA = c.TABLE_SCHEMA and k.TABLE_NAME = c.TABLE_NAME and k.COLUMN_NAME = c.COLUMN_NAME
             where c.TABLE_SCHEMA = ?
               and (c.COLUMN_NAME in ('variant_id','product_variant_id','created_variant_id')
                    or (k.REFERENCED_TABLE_NAME = 'product_variants' and k.REFERENCED_COLUMN_NAME = 'id'))",
            [$database],
        );

        return collect($rows)->map(fn ($row) => [
            'table' => (string) $row->table_name,
            'column' => (string) $row->column_name,
        ])->all();
    }
}
