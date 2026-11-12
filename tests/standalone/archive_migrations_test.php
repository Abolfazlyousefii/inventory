<?php
// Standalone test: php tests/standalone/archive_migrations_test.php
// No Laravel bootstrap or database access.
$script = dirname(__DIR__, 2) . '/tools/archive_legacy_migrations.php';
if (!is_file($script)) {
    fwrite(STDERR, "RED: archive script not present yet\n");
    exit(1);
}
$root = sys_get_temp_dir() . '/migration-archive-test-' . bin2hex(random_bytes(6));
@mkdir($root . '/tools', 0777, true);
@mkdir($root . '/database/migrations', 0777, true);
file_put_contents($root . '/artisan', "<?php\n");
file_put_contents($root . '/composer.json', '{}');
copy($script, $root . '/tools/archive_legacy_migrations.php');
$names = [
    '2018_11_06_222923_create_transactions_table.php',
    '2018_11_07_192923_create_transfers_table.php',
    '2018_11_15_124230_create_wallets_table.php',
    '2021_11_02_202021_update_wallets_uuid_table.php',
    '2023_12_30_113122_extra_columns_removed.php',
    '2023_12_30_204610_soft_delete.php',
    '2024_01_24_185401_add_extra_column_in_transfer.php',
];
foreach ($names as $name) file_put_contents($root . '/database/migrations/' . $name, "<?php // {$name}\n");
$run = static function (string $arg) use ($root): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/archive_legacy_migrations.php') . ' ' . $arg . ' 2>&1';
    exec($cmd, $output, $status);
    return [$status, implode("\n", $output)];
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
try {
    [$status, $output] = $run('--group=bavix');
    $assert($status === 0 && str_contains($output, 'DRY RUN'), 'Dry run failed');
    $assert(is_file($root . '/database/migrations/' . $names[0]), 'Dry run modified source');
    [$status, $output] = $run('--group=bavix --apply');
    $assert($status === 0, 'Apply failed: ' . $output);
    foreach ($names as $name) {
        $assert(!file_exists($root . '/database/migrations/' . $name), 'Source still active: ' . $name);
        $assert(is_file($root . '/database/retired-migrations/bavix/' . $name), 'Archive missing: ' . $name);
    }
    [$status, $output] = $run('--group=bavix --apply');
    $assert($status === 0, 'Idempotent reapply failed: ' . $output);
    [$status, $output] = $run('--group=bavix --restore');
    $assert($status === 0, 'Restore failed: ' . $output);
    foreach ($names as $name) $assert(is_file($root . '/database/migrations/' . $name), 'Restore missing: ' . $name);
    // Collision must abort before touching files.
    @mkdir($root . '/database/retired-migrations/bavix', 0777, true);
    file_put_contents($root . '/database/retired-migrations/bavix/' . $names[0], 'DIFFERENT');
    [$status, $output] = $run('--group=bavix --apply');
    $assert($status !== 0 && str_contains($output, 'CONFLICT'), 'Collision not rejected');
    $assert(is_file($root . '/database/migrations/' . $names[1]), 'Partial modifications on conflict');
    echo "PASS: dry-run, apply, idempotency, restore, collision safety\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    @rmdir($root);
}
