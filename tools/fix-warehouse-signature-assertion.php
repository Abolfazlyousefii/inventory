<?php

declare(strict_types=1);

/**
 * Repairs exactly one formatting-sensitive PHPUnit assertion in a test file.
 * Does not change application code, database schema, or operational data.
 *
 * php tools/fix-warehouse-signature-assertion.php --dry-run
 * php tools/fix-warehouse-signature-assertion.php --apply
 */
$mode = $argv[1] ?? '--dry-run';
if (! in_array($mode, ['--dry-run', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php tools/fix-warehouse-signature-assertion.php [--dry-run|--apply]\n");
    exit(2);
}

$root = dirname(__DIR__);
$relative = 'tests/Feature/WarehouseCollectionItemAdjustmentTest.php';
$path = $root . '/' . $relative;
$controller = $root . '/app/Http/Controllers/InvoiceController.php';
$source = is_file($path) ? file_get_contents($path) : false;
$controllerSource = is_file($controller) ? file_get_contents($controller) : false;
if ($source === false || $controllerSource === false) {
    fwrite(STDERR, "Missing test or InvoiceController.php; no changes made.\n");
    exit(2);
}

// Verify that the actual production method still has the expected parameters.
$methodPattern = '~\bfunction\s+salesVoucherUpdate\s*\(\s*string\s+\$uuid\s*,\s*Request\s+\$request\s*\)~';
if (! preg_match($methodPattern, $controllerSource)) {
    fwrite(STDERR, "Current controller method signature differs; no changes made.\n");
    exit(1);
}

// Accept the one original assertion. Its expected string ignores PHP-legal formatting.
$oldPattern = '~(?P<receiver>\$this->|self::|static::)?assertStringContainsString\s*\(\s*\'salesVoucherUpdate\(string \$uuid, Request \$request\)\'\s*,~';
// Use the same parameter-sensitive regex, only ignoring formatting differences.
$newArgument = <<<'REGEX'
'~\bfunction\s+salesVoucherUpdate\s*\(\s*string\s+\$uuid\s*,\s*Request\s+\$request\s*\)~'
REGEX;
$matches = [];
$count = preg_match_all($oldPattern, $source, $matches);
if ($count === false) {
    fwrite(STDERR, "Could not parse target assertion; no changes made.\n");
    exit(1);
}
if ($count === 0 && str_contains($source, 'assertMatchesRegularExpression(' . $newArgument)) {
    echo "Already repaired. No changes.\n";
    exit(0);
}
if ($count !== 1) {
    fwrite(STDERR, "Expected exactly one original signature assertion, found {$count}; no changes made.\n");
    exit(1);
}
$updated = preg_replace_callback($oldPattern, static function (array $match) use ($newArgument): string {
    return ($match['receiver'] ?? '') . 'assertMatchesRegularExpression(' . $newArgument . ',';
}, $source, 1);
if ($updated === null || $updated === $source) {
    fwrite(STDERR, "Replacement failed; no changes made.\n");
    exit(1);
}

echo "Target: {$relative}\n";
echo "Change: assert controller method signature with parameter-aware, whitespace-tolerant regex.\n";
if ($mode === '--dry-run') {
    echo "DRY RUN: no files changed.\n";
    exit(0);
}

$backupDir = $root . '/tests/.inventory-test-repair-backup/' . date('Ymd-His') . '-' . getmypid();
$backupPath = $backupDir . '/' . $relative;
if (! is_dir(dirname($backupPath)) && ! mkdir(dirname($backupPath), 0775, true) && ! is_dir(dirname($backupPath))) {
    fwrite(STDERR, "Could not create backup folder; no changes made.\n");
    exit(1);
}
if (file_put_contents($backupPath, $source, LOCK_EX) === false) {
    fwrite(STDERR, "Could not back up test file; no changes made.\n");
    exit(1);
}
if (file_put_contents($path, $updated, LOCK_EX) === false) {
    fwrite(STDERR, "Could not update test file; restore from {$backupPath}.\n");
    exit(1);
}
echo "UPDATED {$relative}\n";
echo "Backup: {$backupPath}\n";
echo "Run the focused test and then the inventory suite on your isolated test database.\n";
