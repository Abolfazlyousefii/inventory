<?php

declare(strict_types=1);

/**
 * Safe, test-only repair for the specific October 28 inventory-suite failures.
 * php tools/apply-inventory-test-repair.php --dry-run
 * php tools/apply-inventory-test-repair.php --apply
 */
$root = dirname(__DIR__);
$mode = $argv[1] ?? '--dry-run';
if (! in_array($mode, ['--dry-run', '--apply'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php tools/apply-inventory-test-repair.php [--dry-run|--apply]\n");
    exit(2);
}

$files = [
    'purchase' => 'tests/Feature/PurchaseReturnTest.php',
    'reservation' => 'tests/Feature/ReservationLifecycleHardeningTest.php',
    'reason' => 'tests/Feature/WarehouseCollectionItemAdjustmentTest.php',
    'quantity' => 'tests/Feature/WarehouseCollectionLargeInvoiceTest.php',
];
$originals = [];
$revisions = [];
$details = [];
foreach ($files as $role => $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) {
        fwrite(STDERR, "Missing {$relative}. Do not apply an unverified repair; supply this source file.\n");
        exit(2);
    }
    $originals[$relative] = $contents;
}

try {
    $a = repairPurchaseFixture($originals[$files['purchase']]);
    $revisions[$files['purchase']] = $a[0]; $details[] = $a[1];
    $b = repairReservationFixture($originals[$files['reservation']]);
    $revisions[$files['reservation']] = $b[0]; $details[] = $b[1];
    $c = repairAssertion($originals[$files['reason']], 'Rule::in(', 'reason list');
    $revisions[$files['reason']] = $c[0]; $details[] = $c[1];
    $d = repairAssertion($originals[$files['quantity']], 'items.*.quantity', 'quantity validation');
    $revisions[$files['quantity']] = $d[0]; $details[] = $d[1];
} catch (RuntimeException $error) {
    fwrite(STDERR, "No changes made: {$error->getMessage()}\n");
    exit(1);
}

foreach ($details as $line) echo $line, "\n";
if ($mode === '--dry-run') {
    echo "DRY RUN: no files modified. To apply, run with --apply.\n";
    exit(0);
}
$changed = [];
foreach ($revisions as $rel => $content) {
    if ($content !== $originals[$rel]) $changed[$rel] = $content;
}
if (! $changed) { echo "Already applied. No changes.\n"; exit(0); }
$backupRoot = $root . '/tests/.inventory-test-repair-backup/' . date('Ymd-His') . '-' . getmypid();
foreach ($changed as $rel => $content) {
    $destination = $backupRoot . '/' . $rel;
    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0775, true) && ! is_dir(dirname($destination))) {
        throw new RuntimeException("Cannot create backup directory: {$destination}");
    }
    if (file_put_contents($destination, $originals[$rel]) === false) {
        throw new RuntimeException("Cannot back up {$rel}; no edits applied.");
    }
}
foreach ($changed as $rel => $content) {
    $path = $root . '/' . $rel;
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        fwrite(STDERR, "Could not save {$rel}; restore from {$backupRoot}.\n");
        exit(1);
    }
    echo "UPDATED {$rel}\n";
}
echo "Backups: {$backupRoot}\n";
echo "Run focused tests and then php tests/run-inventory.php on a dedicated test DB.\n";

function repairPurchaseFixture(string $source): array
{
    $pattern = '~\\bSchema::create\s*\(\s*([\'\"])product_variants\1\s*,\s*function\s*\(\s*(?:[\\\\\w]+\s+)?\$(\w+)\s*\)\s*(?::\s*void\s*)?\{~';
    if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        if (str_contains($source, "sales_enabled")) {
            throw new RuntimeException('Purchase-return test has sales_enabled, but its schema creation could not be verified. Upload the test source.');
        }
        throw new RuntimeException('Could not identify PurchaseReturnTest product_variants table definition. Upload the test source.');
    }
    $opening = $m[0][1] + strlen($m[0][0]);
    $end = strpos($source, '});', $opening);
    if ($end === false) throw new RuntimeException('Unterminated product_variants fixture schema.');
    $block = substr($source, $opening, $end - $opening);
    if (str_contains($block, 'sales_enabled')) {
        return [$source, 'Purchase return fixture: sales_enabled already defined'];
    }
    $indent = "\n            ";
    $addition = "{$indent}\$" . $m[2][0] . "->boolean('sales_enabled')->default(true);";
    return [substr_replace($source, $addition, $opening, 0), 'Purchase return fixture: added missing sales_enabled column (test only)'];
}

function repairReservationFixture(string $source): array
{
    $marker = '\\Tests\\Support\\InventorySqliteTestSchema::forReservationProductLookup();';
    if (str_contains($source, $marker)) return [$source, 'Reservation fixture: already integrated'];
    $pattern = '~\\bit\s*\(\s*([\'\"])never lets product lookup expire or expose an official reservation as temporary\1\s*,\s*function\s*\(\s*\)\s*(?::\s*void\s*)?\{~';
    if (! preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Could not identify Pest reservation product-lookup test closure. Upload the test source.');
    }
    $end = $m[0][1] + strlen($m[0][0]);
    $addition = "\n    " . $marker . "\n";
    return [substr_replace($source, $addition, $end, 0), 'Reservation fixture: added in-memory SQLite schema setup for product lookup'];
}

function repairAssertion(string $source, string $needle, string $title): array
{
    // Adjust only the targeted assertion's whitespace sensitivity.
    $offset = 0;
    while (preg_match('/assertStringContainsString\s*\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $m[0][1];
        $open = $start + strlen($m[0][0]) - 1;
        $parsed = parseCallArgs($source, $open);
        if ($parsed === null) throw new RuntimeException("Malformed {$title} assertion.");
        [$end, $args] = $parsed;
        if (count($args) >= 2 && str_contains($args[0], $needle)) {
            if (str_contains($args[0], 'preg_replace(')) return [$source, "{$title}: already normalized"];
            $first = trim($args[0]);
            $second = trim($args[1]);
            $others = array_slice($args, 2);
            // The reason-list source may have a trailing comma in its multiline array.
            $normalizer = $needle === 'Rule::in(' ? '/[\\s,]+/' : '/\\s+/';
            $replacement = "preg_replace('{$normalizer}', '', {$first}), preg_replace('{$normalizer}', '', {$second})";
            if ($others) $replacement .= ', ' . implode(', ', $others);
            $updated = substr_replace($source, $replacement, $open + 1, $end - $open - 1);
            return [$updated, "{$title}: source assertion now ignores formatting whitespace"];
        }
        $offset = $end + 1;
    }
    throw new RuntimeException("Could not identify exact {$title} source assertion. Upload the test source.");
}

/** @return array{int, array<int, string>}|null */
function parseCallArgs(string $source, int $open): ?array
{
    if (($source[$open] ?? '') !== '(') return null;
    $level = 0;
    $start = $open + 1;
    $args = [];
    $quote = null;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        $c = $source[$i];
        if ($quote !== null) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $quote) $quote = null;
            continue;
        }
        if ($c === "'" || $c === '"') { $quote = $c; continue; }
        if ($c === '(') $level++;
        if ($c === ')') {
            $level--;
            if ($level === 0) { $args[] = substr($source, $start, $i - $start); return [$i, $args]; }
        }
        if ($c === ',' && $level === 1) { $args[] = substr($source, $start, $i - $start); $start = $i + 1; }
    }
    return null;
}
