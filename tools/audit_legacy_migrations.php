<?php

declare(strict_types=1);

/**
 * Read-only migration/schema audit for the Inventory application's default MySQL database.
 * - Does not run migrations or write to either database.
 * - Reports schema metadata, migration history, and aggregate preflight counts.
 * - Never includes customer names, passwords, tokens, or individual financial records.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connectionName = 'mysql';
$conn = DB::connection($connectionName);

if ($conn->getDriverName() !== 'mysql') {
    fwrite(STDERR, "This audit requires the MySQL/MariaDB 'mysql' connection. No queries were run.\n");
    exit(1);
}

$database = $conn->getDatabaseName();
if (!is_string($database) || $database === '') {
    fwrite(STDERR, "Cannot determine the MySQL database name.\n");
    exit(1);
}

// Historic pending migration names according to the supplied baseline report.
$pendingMigrations = [
    'seller' => [
        '2026_08_01_200000_add_seller_ownership_to_sales_documents',
        '2026_08_01_200001_create_seller_reassignment_audits_table',
        '2026_08_01_210000_create_seller_sales_documents_tables',
        '2026_08_13_120000_add_reassignment_history_to_seller_sales_document_items',
    ],
    'commission' => [
        '2026_08_14_000001_create_commercial_commission_foundation_tables',
        '2026_08_14_000002_create_commission_ledger_entries_table',
        '2026_08_14_000003_create_commission_documents_tables',
        '2026_08_14_000004_create_commission_corrections',
        '2026_08_14_000005_create_commission_settlements',
        '2026_08_14_000006_create_commission_targets_table',
        '2026_08_14_000007_add_pilot_flags_to_commission_settings',
        '2026_08_14_000008_reopen_invalid_empty_active_commission_periods',
        '2026_08_31_000001_create_commission_invoice_sync_outboxes_table',
        '2026_08_31_010000_create_commission_return_sync_outboxes_table',
    ],
    'retired_bavix' => [
        '2018_11_06_222923_create_transactions_table',
        '2018_11_07_192923_create_transfers_table',
        '2018_11_15_124230_create_wallets_table',
        '2021_11_02_202021_update_wallets_uuid_table',
        '2023_12_30_113122_extra_columns_removed',
        '2023_12_30_204610_soft_delete',
        '2024_01_24_185401_add_extra_column_in_transfer',
    ],
];

// Table list is derived from the 14 pending migration files, the related later
// migrations, and the few referenced parent tables required for foreign keys.
$tablesByGroup = [
    'seller' => [
        'users', 'preinvoice_orders', 'invoices',
        'seller_reassignment_audits', 'seller_sales_documents',
        'seller_sales_document_items', 'seller_sales_document_adjustments',
    ],
    'commission' => [
        'permissions', 'sales_return_documents',
        'commission_rate_revisions', 'commission_campaigns', 'commission_campaign_targets',
        'commission_settings', 'commission_periods', 'commission_ledger_entries',
        'commission_calculation_warnings', 'commission_documents',
        'commission_document_items', 'commission_document_events',
        'commission_correction_entries', 'commission_reconciliation_warnings',
        'commission_document_corrections', 'commission_adjustments',
        'commission_document_adjustments', 'commission_settlements',
        'commission_payments', 'commission_period_events', 'commission_targets',
        'commission_invoice_sync_outboxes', 'commission_return_sync_outboxes',
        // Follow-on migrations that may already be recorded as Ran.
        'org_commission_departments', 'org_commission_department_members',
        'org_commission_documents', 'org_commission_allocations',
        'org_commission_member_shares',
    ],
    'other_followup' => ['suppliers'],
];

$tables = array_values(array_unique(array_merge(...array_values($tablesByGroup))));
sort($tables);
$placeholders = implode(', ', array_fill(0, count($tables), '?'));

$hasTable = [];
$hasColumn = [];

try {
    $foundTables = $conn->select(
        'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, TABLE_COLLATION '
        .'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.$placeholders.') ORDER BY TABLE_NAME',
        array_merge([$database], $tables),
    );
    foreach ($foundTables as $table) {
        $hasTable[$table->TABLE_NAME] = true;
    }

    $columns = $conn->select(
        'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY '
        .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.$placeholders.') '
        .'ORDER BY TABLE_NAME, ORDINAL_POSITION',
        array_merge([$database], $tables),
    );
    foreach ($columns as $column) {
        $hasColumn[$column->TABLE_NAME][$column->COLUMN_NAME] = true;
    }

    $indexes = $conn->select(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE '
        .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.$placeholders.') '
        .'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        array_merge([$database], $tables),
    );

    $foreignKeys = $conn->select(
        'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, '
        .'k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
        .'FROM information_schema.KEY_COLUMN_USAGE AS k '
        .'LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r '
        .'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME '
        .'AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
        .'WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME IN ('.$placeholders.') '
        .'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
        .'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
        array_merge([$database], $tables),
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Schema metadata query failed: ".$e->getMessage()."\n");
    exit(1);
}

$allMigrations = [];
$migrationError = null;
try {
    // A missing migrations table is possible in an uninitialized local DB.
    $records = $conn->select('SELECT migration, batch FROM migrations ORDER BY migration');
    foreach ($records as $record) {
        $allMigrations[$record->migration] = (int) $record->batch;
    }
} catch (Throwable $e) {
    $migrationError = $e->getMessage();
}

$statuses = [];
foreach ($pendingMigrations as $group => $names) {
    foreach ($names as $name) {
        $statuses[$group][$name] = array_key_exists($name, $allMigrations)
            ? ['status' => 'recorded', 'batch' => $allMigrations[$name]]
            : ['status' => 'not_recorded'];
    }
}
$statuses['additional'] = [
    '2026_11_11_120000_add_adjustment_source_flag_to_suppliers' =>
        array_key_exists('2026_11_11_120000_add_adjustment_source_flag_to_suppliers', $allMigrations)
            ? ['status' => 'recorded', 'batch' => $allMigrations['2026_11_11_120000_add_adjustment_source_flag_to_suppliers']]
            : ['status' => 'not_recorded'],
];

$preview = [];

/** Run a read-only aggregate query only if all relevant tables and columns exist. */
$runPreview = static function (string $label, array $requirements, string $sql) use (&$preview, $hasTable, $hasColumn, $conn): void {
    foreach ($requirements as $table => $columns) {
        if (empty($hasTable[$table])) {
            $preview[$label] = ['status' => 'skipped', 'reason' => "missing table: {$table}"];
            return;
        }
        foreach ($columns as $column) {
            if (empty($hasColumn[$table][$column])) {
                $preview[$label] = ['status' => 'skipped', 'reason' => "missing column: {$table}.{$column}"];
                return;
            }
        }
    }
    try {
        $preview[$label] = ['status' => 'ok', 'rows' => $conn->select($sql)];
    } catch (Throwable $e) {
        $preview[$label] = ['status' => 'query_failed', 'error' => $e->getMessage()];
    }
};

$runPreview('seller_invalid_assignments', [
    'preinvoice_orders' => ['seller_id'], 'invoices' => ['seller_id'], 'users' => ['id'],
], <<<'SQL'
SELECT 'preinvoice_orders' AS source, COUNT(*) AS invalid_count
  FROM preinvoice_orders p LEFT JOIN users u ON u.id = p.seller_id
  WHERE p.seller_id IS NOT NULL AND u.id IS NULL
UNION ALL
SELECT 'invoices' AS source, COUNT(*) AS invalid_count
  FROM invoices i LEFT JOIN users u ON u.id = i.seller_id
  WHERE i.seller_id IS NOT NULL AND u.id IS NULL
SQL);

$runPreview('seller_ownership_backfill_candidates', [
    'preinvoice_orders' => ['seller_id', 'created_by'],
    'invoices' => ['seller_id', 'preinvoice_order_id'],
    'users' => ['id', 'is_active', 'can_access_erp', 'is_seller'],
], <<<'SQL'
SELECT 'preinvoice_orders' AS source, COUNT(*) AS candidate_count
 FROM preinvoice_orders p JOIN users u ON u.id = p.created_by
 WHERE p.seller_id IS NULL AND u.is_active = 1 AND u.can_access_erp = 1 AND u.is_seller = 1
UNION ALL
SELECT 'invoices' AS source, COUNT(*) AS candidate_count
 FROM invoices i JOIN preinvoice_orders p ON p.id = i.preinvoice_order_id
 WHERE i.seller_id IS NULL AND p.seller_id IS NOT NULL
SQL);

$runPreview('seller_invoice_preinvoice_mismatches', [
    'preinvoice_orders' => ['id', 'seller_id'],
    'invoices' => ['preinvoice_order_id', 'seller_id'],
], <<<'SQL'
SELECT COUNT(*) AS mismatched_count FROM invoices i
 JOIN preinvoice_orders p ON p.id = i.preinvoice_order_id
 WHERE i.seller_id IS NOT NULL AND p.seller_id IS NOT NULL AND i.seller_id <> p.seller_id
SQL);

$runPreview('seller_reassignment_rows_that_would_be_rewritten', [
    'seller_sales_document_items' => ['status', 'active_invoice_id'],
], <<<'SQL'
SELECT COUNT(*) AS candidate_count FROM seller_sales_document_items
 WHERE status IS NULL OR status <> 'reassigned' OR active_invoice_id IS NOT NULL
SQL);

$runPreview('commission_reopen_candidates_by_status', [
    'commission_periods' => ['id', 'end_at', 'status'],
    'commission_ledger_entries' => ['commission_period_id'],
    'commission_correction_entries' => ['commission_period_id'],
    'commission_adjustments' => ['commission_period_id'],
    'commission_documents' => ['commission_period_id'],
    'commission_settlements' => ['commission_period_id'],
], <<<'SQL'
SELECT cp.status, COUNT(*) AS candidate_count FROM commission_periods cp
 WHERE cp.end_at > CURRENT_TIMESTAMP AND cp.status IN ('review', 'closed', 'paid')
   AND NOT EXISTS (SELECT 1 FROM commission_ledger_entries l WHERE l.commission_period_id = cp.id)
   AND NOT EXISTS (SELECT 1 FROM commission_correction_entries ce WHERE ce.commission_period_id = cp.id)
   AND NOT EXISTS (SELECT 1 FROM commission_adjustments a WHERE a.commission_period_id = cp.id)
   AND NOT EXISTS (SELECT 1 FROM commission_documents d WHERE d.commission_period_id = cp.id)
   AND NOT EXISTS (SELECT 1 FROM commission_settlements s WHERE s.commission_period_id = cp.id)
 GROUP BY cp.status ORDER BY cp.status
SQL);

$runPreview('commission_reopen_event_count', [
    'commission_period_events' => ['event_type'],
], <<<'SQL'
SELECT COUNT(*) AS recorded_events FROM commission_period_events
 WHERE event_type = 'invalid_empty_period_reopened'
SQL);

$runPreview('supplier_adjustment_column_presence', [
    'suppliers' => ['is_inventory_adjustment_source'],
], <<<'SQL'
SELECT COUNT(*) AS enabled_suppliers FROM suppliers WHERE is_inventory_adjustment_source = 1
SQL);

$expectedTables = [
    'seller_reassignment_audits', 'seller_sales_documents', 'seller_sales_document_items',
    'commission_rate_revisions', 'commission_campaigns', 'commission_campaign_targets',
    'commission_settings', 'commission_periods', 'commission_ledger_entries',
    'commission_calculation_warnings', 'commission_documents',
    'commission_document_items', 'commission_document_events',
    'commission_correction_entries', 'commission_reconciliation_warnings',
    'commission_document_corrections', 'commission_adjustments',
    'commission_document_adjustments', 'commission_settlements',
    'commission_payments', 'commission_period_events', 'commission_targets',
    'commission_invoice_sync_outboxes', 'commission_return_sync_outboxes',
];

// Fast diagnostic flags. These do not substitute for the full type, index and
// foreign-key comparison in the metadata sections of this report.
$criticalColumns = [
    'preinvoice_orders' => ['seller_id'],
    'invoices' => ['seller_id'],
    'seller_sales_document_items' => [
        'status', 'active_invoice_id', 'reassigned_to_seller_id',
        'reassigned_at', 'reassignment_audit_id',
    ],
    'commission_settings' => ['pilot_mode', 'seller_visibility_enabled', 'targets_enabled'],
    'sales_return_documents' => ['commission_effect_type'],
    'commission_periods' => [
        'review_started_by', 'review_started_at', 'total_net_sales_snapshot',
        'base_commission_snapshot', 'campaign_commission_snapshot',
        'return_reversal_snapshot', 'seller_correction_snapshot',
        'manual_adjustment_snapshot', 'approved_commission_snapshot',
        'seller_count_snapshot', 'document_count_snapshot', 'close_fingerprint',
        'closed_by', 'closed_at', 'paid_by', 'paid_at',
    ],
    'commission_documents' => [
        'final_net_sales', 'final_base_commission', 'final_campaign_commission',
        'final_correction_amount', 'final_adjustment_amount',
        'final_commission_total', 'final_fingerprint', 'finalized_by', 'finalized_at',
    ],
    'commission_invoice_sync_outboxes' => ['invoice_id', 'attempts', 'available_at'],
    'commission_return_sync_outboxes' => ['sales_return_document_id', 'attempts', 'available_at'],
];
$missingCriticalColumns = [];
foreach ($criticalColumns as $table => $names) {
    foreach ($names as $column) {
        if (empty($hasColumn[$table][$column])) {
            $missingCriticalColumns[] = $table.'.'.$column;
        }
    }
}

$criticalIndexes = [
    'seller_sales_document_items' => [
        'ssd_items_active_invoice_unique', 'ssd_items_status_idx',
        'ssd_items_invoice_id_index',
    ],
    'commission_ledger_entries' => ['commission_ledger_one_active'],
    'commission_invoice_sync_outboxes' => [
        'commission_sync_outbox_invoice', 'commission_sync_outbox_available',
    ],
    'commission_return_sync_outboxes' => [
        'commission_return_outbox_document', 'commission_return_outbox_available',
    ],
];
$indexLookup = [];
foreach ($indexes as $index) {
    $indexLookup[$index->TABLE_NAME][$index->INDEX_NAME] = true;
}
$missingCriticalNamedIndexes = [];
foreach ($criticalIndexes as $table => $names) {
    foreach ($names as $name) {
        if (empty($indexLookup[$table][$name])) {
            $missingCriticalNamedIndexes[] = $table.'.'.$name;
        }
    }
}

$permissionKeys = [
    'page.commercial.commissions', 'commissions.manage_rates',
    'commissions.manage_campaigns', 'commissions.manage_periods',
    'commissions.recalculate', 'commissions.view_seller_details',
    'commissions.manage_documents', 'commissions.review_documents',
    'commissions.print_documents', 'commissions.finalize_documents',
    'commissions.close_periods', 'commissions.manage_adjustments',
    'commissions.review_adjustments', 'commissions.record_payments',
    'commissions.void_payments', 'commissions.mark_period_paid',
    'commissions.view_settlements', 'commissions.manage_targets',
];
$permissionsPresent = null;
if (!empty($hasTable['permissions']) && !empty($hasColumn['permissions']['key'])) {
    $permissionPlaceholders = implode(', ', array_fill(0, count($permissionKeys), '?'));
    try {
        $permissionRows = $conn->select(
            'SELECT `key` FROM permissions WHERE `key` IN ('.$permissionPlaceholders.') ORDER BY `key`',
            $permissionKeys,
        );
        $permissionsPresent = array_map(static fn ($row) => $row->key, $permissionRows);
    } catch (Throwable $e) {
        $permissionsPresent = ['query_error' => $e->getMessage()];
    }
}

$report = [
    'purpose' => 'READ_ONLY pending-migration reconciliation (no migrations executed)',
    'connection' => $connectionName,
    'database' => $database,
    'db_server_clock' => $conn->selectOne('SELECT CURRENT_TIMESTAMP AS server_time')->server_time,
    'migration_history_error' => $migrationError,
    'historic_migration_status' => $statuses,
    'known_later_migrations' => array_filter(
        $allMigrations,
        static fn ($batch, $name) => str_starts_with($name, '2026_09_09_')
            || str_starts_with($name, '2026_09_14_')
            || str_starts_with($name, '2026_10_12_'),
        ARRAY_FILTER_USE_BOTH,
    ),
    'missing_expected_tables' => array_values(array_diff($expectedTables, array_keys($hasTable))),
    'missing_critical_columns' => $missingCriticalColumns,
    'missing_critical_named_indexes' => $missingCriticalNamedIndexes,
    'commission_permission_keys_present' => $permissionsPresent,
    'tables_checked' => $tablesByGroup,
    'table_metadata' => $foundTables,
    'column_metadata' => $columns,
    'index_metadata' => $indexes,
    'foreign_key_metadata' => $foreignKeys,
    'data_effect_previews' => $preview,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
if ($json === false) {
    fwrite(STDERR, "Unable to encode report.\n");
    exit(1);
}
echo $json, "\n";
