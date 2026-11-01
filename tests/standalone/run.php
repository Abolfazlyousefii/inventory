<?php

// Each probe runs in a separate PHP process because some use isolated framework fakes.
$tests = [
    'stock_count_quantities.php',
    'bundle_stocktake.php',
    'stock_count_service_snapshots.php',
    'legacy_repair_safety.php',
    'site_retry_snapshot.php',
    'site_warehouse_scope.php',
];
foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$test);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, "FAIL: {$test} (exit {$status})\n");
        exit($status);
    }
}
echo "All standalone regression probes passed.\n";
