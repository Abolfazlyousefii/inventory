<?php

// Dedicated local audit database only; this runner replaces its own fixtures.
$database = getenv('INVENTORY_TEST_MYSQL_DATABASE') ?: '';
$port = (int) getenv('INVENTORY_TEST_MYSQL_PORT');
if (! preg_match('/^inventory_isolated_audit_[a-zA-Z0-9_]+$/', $database) || $port < 1024) {
    fwrite(STDERR, "A dedicated local inventory_isolated_audit_ database and port are required.\n");
    exit(2);
}
putenv('APP_ENV=testing');
require getenv('INVENTORY_TEST_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Event, Http};
config(['database.default' => 'isolated_audit', 'database.connections.isolated_audit' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => $database,
    'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
]]);
if (DB::connection()->getDatabaseName() !== $database) throw new RuntimeException('Unsafe database');
Event::fake();
Http::preventStrayRequests();

if (($argv[1] ?? '') === 'worker') {
    $prefix = $argv[2];
    $token = $argv[3];
    DB::connection()->beforeExecuting(function (string $sql) use ($prefix): void {
        if (str_contains($sql, 'from `users`')) file_put_contents($prefix.'.ready', '1');
    });
    $result = app(App\Services\PreinvoiceDraftReservationService::class)->syncReservationRows($token, 1, []);
    if (($result['skipped'] ?? false) !== true || $result['reason'] !== 'submitted_token') {
        throw new RuntimeException('Late sync did not observe the committed submission');
    }
    echo json_encode($result);
    exit;
}

DB::statement('CREATE TABLE IF NOT EXISTS users(id BIGINT PRIMARY KEY)');
DB::statement('CREATE TABLE IF NOT EXISTS preinvoice_orders(id BIGINT PRIMARY KEY,created_by BIGINT,submission_token CHAR(36) NULL)');
DB::table('preinvoice_orders')->delete();
DB::table('users')->delete();
DB::table('users')->insert(['id' => 1]);
$token = 'e340c2b3-d80b-4fca-8d70-d8fe09cc578b';
$prefix = sys_get_temp_dir().'/submitted-token-concurrency-'.bin2hex(random_bytes(6));
$worker = null;
try {
    DB::beginTransaction();
    DB::table('users')->where('id', 1)->lockForUpdate()->first();
    DB::table('preinvoice_orders')->insert(['id' => 1, 'created_by' => 1, 'submission_token' => $token]);
    $worker = proc_open([PHP_BINARY, __FILE__, 'worker', $prefix, $token], [
        0 => ['pipe', 'r'], 1 => ['file', $prefix.'.out', 'w'], 2 => ['file', $prefix.'.err', 'w'],
    ], $pipes);
    $deadline = microtime(true) + 12;
    while (! is_file($prefix.'.ready')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker did not start');
        usleep(20000);
    }
    usleep(150000);
    if (! proc_get_status($worker)['running']) throw new RuntimeException('Sync did not wait for the submission lock');
    DB::commit();
    $exit = proc_close($worker);
    $worker = null;
    if ($exit !== 0) throw new RuntimeException(file_get_contents($prefix.'.err'));
    echo file_get_contents($prefix.'.out');
} finally {
    if (DB::transactionLevel() > 0) DB::rollBack();
    if (is_resource($worker)) proc_terminate($worker);
    foreach (['.ready', '.out', '.err'] as $suffix) if (is_file($prefix.$suffix)) unlink($prefix.$suffix);
}
