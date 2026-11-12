<?php
/**
 * Standalone regression test for the seller-commission archive group.
 * Runs exclusively in a throwaway temp directory: no Laravel bootstrap/database access.
 * Usage: php tests/standalone/archive_seller_commission_migrations_test.php
 */
declare(strict_types=1);
$script = dirname(__DIR__, 2) . '/tools/archive_legacy_migrations.php';
if (!is_file($script)) { fwrite(STDERR, "Missing archive tool\n"); exit(1); }

$names = [
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
];
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seller-commission-archive-' . bin2hex(random_bytes(6));
$dir = $root . '/database/migrations';
$archive = $root . '/database/retired-migrations/seller-commission';
$assert = static function (bool $test, string $message): void {
    if (!$test) { throw new RuntimeException($message); }
};
$run = static function (string $args) use ($root): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/archive_legacy_migrations.php') . ' ' . $args . ' 2>&1';
    $lines = [];
    exec($command, $lines, $status);
    return [$status, implode("\n", $lines)];
};
try {
    mkdir($root . '/tools', 0775, true);
    mkdir($dir, 0775, true);
    file_put_contents($root . '/artisan', "<?php\n");
    file_put_contents($root . '/composer.json', "{}\n");
    copy($script, $root . '/tools/archive_legacy_migrations.php');
    foreach ($names as $name) { file_put_contents($dir . '/' . $name, "<?php /* ORIGINAL $name */\n"); }
    $hashes = array_combine($names, array_map(static fn($name) => hash_file('sha256', $dir . '/' . $name), $names));

    [$status, $output] = $run('--group=seller-commission');
    $assert($status === 0 && str_contains($output, 'DRY RUN') && str_contains($output, 'files: 14'), 'Dry run failed: ' . $output);
    $assert(count(glob($dir . '/*.php')) === 14, 'Dry run changed source files');

    [$status, $output] = $run('--group=seller-commission --apply');
    $assert($status === 0 && str_contains($output, 'SUCCESS'), 'Apply failed: ' . $output);
    foreach ($names as $name) {
        $assert(!is_file($dir . '/' . $name), 'Active migration remained: ' . $name);
        $assert(is_file($archive . '/' . $name), 'Missing archived migration: ' . $name);
        $assert(hash_file('sha256', $archive . '/' . $name) === $hashes[$name], 'Archive bytes changed: ' . $name);
    }

    [$status, $output] = $run('--group=seller-commission --apply');
    $assert($status === 0, 'Idempotent reapply failed: ' . $output);

    [$status, $output] = $run('--group=seller-commission --restore');
    $assert($status === 0, 'Restore failed: ' . $output);
    foreach ($names as $name) {
        $assert(is_file($dir . '/' . $name), 'Restore missing: ' . $name);
        $assert(hash_file('sha256', $dir . '/' . $name) === $hashes[$name], 'Restore bytes changed: ' . $name);
    }

    if (!is_dir($archive)) { mkdir($archive, 0775, true); }
    file_put_contents($archive . '/' . $names[0], 'different contents');
    [$status, $output] = $run('--group=seller-commission --apply');
    $assert($status !== 0 && str_contains($output, 'CONFLICT'), 'Conflict not rejected');
    $assert(is_file($dir . '/' . $names[1]), 'Partial archive occurred after conflict');
    echo "PASS: seller/commission 14-file dry run, byte preservation, apply, idempotency, restore, conflict safety\n";
} finally {
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
