<?php
/**
 * INV-05 test-only fixture repair.
 * Adds the missing sales_enabled column solely to PurchaseReturnTest's
 * explicitly created, in-memory SQLite product_variants table.
 * No Laravel bootstrap, no migrations, no database writes.
 */
declare(strict_types=1);

$target = $argv[1] ?? (getcwd() . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'PurchaseReturnTest.php');
if (!is_file($target)) {
    fwrite(STDERR, "Test file not found: {$target}\nNo changes made.\n");
    exit(1);
}
$original = file_get_contents($target);
if ($original === false) {
    fwrite(STDERR, "Cannot read {$target}. No changes made.\n");
    exit(1);
}

// Deliberately match only a schema fixture, not production migrations or services.
$pattern = '~(?<start>Schema::create\s*\(\s*[\'\"]product_variants[\'\"]\s*,\s*function\s*\((?<args>[^)]*)\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*void\s*)?\{)(?<body>.*?)(?<end>^\h*\}\s*\)\s*;)~ms';
$count = preg_match_all($pattern, $original, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
if ($count !== 1) {
    fwrite(STDERR, "Expected exactly one Schema::create('product_variants', function (...) {...}) fixture; found " . ($count === false ? 'error' : $count) . ".\nNo changes made. Upload tests/Feature/PurchaseReturnTest.php for a manual, exact patch.\n");
    exit(1);
}
$match = $matches[0];
$body = $match['body'][0];
if (preg_match('/\bsales_enabled\b/', $body)) {
    echo "The test fixture already declares sales_enabled. No changes needed.\n";
    exit(0);
}
if (!preg_match('/\$([A-Za-z_]\w*)/', $match['args'][0], $arg)) {
    fwrite(STDERR, "Could not identify schema table variable. No changes made.\n");
    exit(1);
}
$var = $arg[1];
$eol = str_contains($original, "\r\n") ? "\r\n" : "\n";

// Prefer inserting just after the primary-key declaration.
$idPattern = '~(?m)^([ \t]*)\$' . preg_quote($var, '~') . '->(?:id\(\)|bigIncrements\([\'\"]id[\'\"]\)|increments\([\'\"]id[\'\"]\))\s*;[ \t]*(?:\r?\n|$)~';
if (preg_match($idPattern, $body, $idMatch, PREG_OFFSET_CAPTURE)) {
    $indent = $idMatch[1][0];
    $pos = $idMatch[0][1] + strlen($idMatch[0][0]);
} else {
    $colPattern = '~(?m)^([ \t]*)\$' . preg_quote($var, '~') . '->~';
    if (!preg_match($colPattern, $body, $colMatch, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "No safe insertion point found. No changes made.\n");
        exit(1);
    }
    $indent = $colMatch[1][0];
    $pos = $colMatch[0][1];
}
$line = $indent . '$' . $var . "->boolean('sales_enabled')->default(true);" . $eol;
$newBody = substr($body, 0, $pos) . $line . substr($body, $pos);
$start = $match['body'][1];
$new = substr($original, 0, $start) . $newBody . substr($original, $start + strlen($body));
if ($new === $original || substr_count($new, "->boolean('sales_enabled')->default(true)") !== 1) {
    fwrite(STDERR, "Safety check failed. No changes made.\n");
    exit(1);
}
$backup = $target . '.inv05.bak';
if (file_exists($backup)) {
    fwrite(STDERR, "Backup already exists: {$backup}. No changes made to avoid overwriting a prior backup.\n");
    exit(1);
}
if (file_put_contents($backup, $original, LOCK_EX) !== strlen($original)) {
    fwrite(STDERR, "Could not save backup. No changes made.\n");
    exit(1);
}
if (file_put_contents($target, $new, LOCK_EX) !== strlen($new)) {
    fwrite(STDERR, "Cannot write updated test file; backup available at {$backup}.\n");
    exit(1);
}

echo "Updated TEST FIXTURE ONLY: {$target}\n";
echo "Added: \$" . $var . "->boolean('sales_enabled')->default(true);\n";
echo "Backup: {$backup}\n";
echo "No application code, migrations or database records changed.\n";
