<?php

// Reproduce overlapping requests on a dedicated local MySQL database only.
$database = getenv('INVENTORY_TEST_MYSQL_DATABASE') ?: '';
$port = (int) getenv('INVENTORY_TEST_MYSQL_PORT');
if ($database !== 'inventory_isolated_audit_numbering_20261020' || $port < 1024) {
    throw new RuntimeException('The dedicated numbering audit database and local port are required.');
}
putenv('APP_ENV=testing');
require getenv('INVENTORY_TEST_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Laravel's console renderer must not turn a failed standalone check into exit 0.
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
use Illuminate\Support\Facades\{DB, Http};
use App\Support\DocumentCodeGenerator;
Http::preventStrayRequests();
$connection = ['driver'=>'mysql','host'=>'127.0.0.1','port'=>$port,'database'=>$database,'username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true];
config(['database.default'=>'numbering_a', 'database.connections.numbering_a'=>$connection, 'database.connections.numbering_b'=>$connection]);
if (DB::connection()->getDatabaseName() !== $database) throw new RuntimeException('Unsafe database');
DB::statement('CREATE TABLE IF NOT EXISTS invoices(id BIGINT AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(5) UNIQUE) ENGINE=InnoDB');
DB::statement('CREATE TABLE IF NOT EXISTS preinvoice_orders(id BIGINT AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(5) UNIQUE) ENGINE=InnoDB');
DB::statement('CREATE TABLE IF NOT EXISTS document_sequences(id BIGINT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(40) UNIQUE,last_number INT UNSIGNED NOT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
foreach (['shared_allocator', 'legacy_direct_writer'] as $scenario) {
    DB::table('invoices')->delete(); DB::table('preinvoice_orders')->delete(); DB::table('document_sequences')->delete();
    DB::table('invoices')->insert(['uuid'=>'02052']);
    DB::table('document_sequences')->insert(['type'=>'invoice','last_number'=>2052,'created_at'=>now(),'updated_at'=>now()]);
    $a = DB::connection('numbering_a');
    $a->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $a->beginTransaction();
    try {
        // A establishes its snapshot before B reserves a preinvoice number.
        $a->table('invoices')->get();
        DB::setDefaultConnection('numbering_b');
        DB::transaction(function () use ($scenario) {
            $code = $scenario === 'shared_allocator' ? DocumentCodeGenerator::nextInvoiceCode() : '02053';
            if ($code !== '02053') throw new RuntimeException('Unexpected first allocation');
            DB::table('preinvoice_orders')->insert(['uuid'=>$code]);
        });
        DB::setDefaultConnection('numbering_a');
        $code = DocumentCodeGenerator::nextInvoiceCode();
        if ($code !== '02054') throw new RuntimeException("$scenario reused a reserved number: $code");
        DB::table('invoices')->insert(['uuid'=>$code]);
        $a->commit();
        echo "$scenario: old-snapshot request received $code, preinvoice retained 02053\n";
    } catch (Throwable $error) {
        if ($a->transactionLevel() > 0) $a->rollBack();
        throw $error;
    } finally {
        DB::setDefaultConnection('numbering_a');
    }
}
