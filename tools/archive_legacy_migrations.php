<?php
/**
 * Local-only migration file manager, no Laravel bootstrap or database queries.
 * Usage: php tools/archive_legacy_migrations.php [--group=bavix|seller-commission] [--apply|--restore]
 * Default is a strictly read-only dry run.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/artisan') || !is_file($root . '/composer.json') || !is_dir($root . '/database/migrations')) {
    fwrite(STDERR, "ERROR: Install this file under the tools/ directory of a Laravel project.\n");
    exit(2);
}

$groups = [
    'bavix' => [
        '2018_11_06_222923_create_transactions_table.php',
        '2018_11_07_192923_create_transfers_table.php',
        '2018_11_15_124230_create_wallets_table.php',
        '2021_11_02_202021_update_wallets_uuid_table.php',
        '2023_12_30_113122_extra_columns_removed.php',
        '2023_12_30_204610_soft_delete.php',
        '2024_01_24_185401_add_extra_column_in_transfer.php',
    ],
    // Historical seller and commission schema already exists in the reviewed Inventory DB.
    // These files are preserved byte-for-byte for reference, not executed.
    'seller-commission' => [
        '2026_08_01_200000_add_seller_ownership_to_sales_documents.php',
        '2026_08_01_200001_create_seller_reassignment_audits_table.php',
        '2026_08_01_210000_create_seller_sales_documents_tables.php',
        '2026_08_13_120000_add_reassignment_history_to_seller_sales_document_items.php',
        '2026_08_14_000001_create_commercial_commission_foundation_tables.php',
        '2026_08_14_000002_create_commission_ledger_entries_table.php',
        '2026_08_14_000003_create_commission_documents_tables.php',
        '2026_08_14_000004_create_commission_corrections.php',
        '2026_08_14_000005_create_commission_settlements.php',
        '2026_08_14_000006_create_commission_targets_table.php',
        '2026_08_14_000007_add_pilot_flags_to_commission_settings.php',
        '2026_08_14_000008_reopen_invalid_empty_active_commission_periods.php',
        '2026_08_31_000001_create_commission_invoice_sync_outboxes_table.php',
        '2026_08_31_010000_create_commission_return_sync_outboxes_table.php',
    ],
];
$group = 'bavix';
$mode = 'dry-run';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--group=')) {
        $group = substr($arg, 8);
    } elseif ($arg === '--apply') {
        if ($mode !== 'dry-run') { fwrite(STDERR, "ERROR: Conflicting modes.\n"); exit(2); }
        $mode = 'apply';
    } elseif ($arg === '--restore') {
        if ($mode !== 'dry-run') { fwrite(STDERR, "ERROR: Conflicting modes.\n"); exit(2); }
        $mode = 'restore';
    } else {
        fwrite(STDERR, "ERROR: Unknown option: {$arg}\n");
        exit(2);
    }
}
if (!isset($groups[$group])) {
    fwrite(STDERR, "ERROR: Unknown migration group. Supported: bavix, seller-commission.\n");
    exit(2);
}

$activeDir = $root . '/database/migrations';
$archiveDir = $root . '/database/retired-migrations/' . $group;
$actions = [];
foreach ($groups[$group] as $file) {
    $active = $activeDir . '/' . $file;
    $archive = $archiveDir . '/' . $file;
    $hasActive = is_file($active);
    $hasArchive = is_file($archive);
    if (!$hasActive && !$hasArchive) {
        fwrite(STDERR, "ERROR: Missing both active and archived copy: {$file}\nNo changes made.\n");
        exit(1);
    }
    if ($hasActive && $hasArchive && hash_file('sha256', $active) !== hash_file('sha256', $archive)) {
        fwrite(STDERR, "CONFLICT: Active and archived copies differ: {$file}\nNo changes made. Resolve manually; nothing will be overwritten.\n");
        exit(1);
    }
    $action = match ($mode) {
        'restore' => $hasActive ? 'already active' : 'restore archive to active',
        default => !$hasActive ? 'already archived' : ($hasArchive ? 'remove byte-identical active duplicate' : 'move active to archive'),
    };
    $actions[] = [$file, $active, $archive, $action];
}

echo "Mode: " . ($mode === 'dry-run' ? 'DRY RUN (no files changed)' : strtoupper($mode)) . "\n";
echo "Group: {$group}, files: " . count($actions) . "\n";
foreach ($actions as [$file, , , $action]) {
    echo "- {$file}: {$action}\n";
}
if ($mode === 'dry-run') {
    echo "No files or database records changed. Run with --apply to archive files.\n";
    exit(0);
}

$destinationDir = $mode === 'restore' ? $activeDir : $archiveDir;
if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
    fwrite(STDERR, "ERROR: Unable to create destination directory.\n");
    exit(1);
}

foreach ($actions as [$file, $active, $archive, $action]) {
    if ($mode === 'apply') {
        if ($action === 'move active to archive' && !rename($active, $archive)) {
            fwrite(STDERR, "ERROR: Failed to archive {$file}; earlier files may have moved. Use --restore to revert.\n");
            exit(1);
        }
        if ($action === 'remove byte-identical active duplicate' && !unlink($active)) {
            fwrite(STDERR, "ERROR: Failed to remove duplicate {$file}.\n");
            exit(1);
        }
    } elseif ($action === 'restore archive to active' && !rename($archive, $active)) {
        fwrite(STDERR, "ERROR: Failed to restore {$file}; earlier files may have moved.\n");
        exit(1);
    }
}

echo "SUCCESS: Files reconciled. No database connection was opened and no migration was executed.\n";
