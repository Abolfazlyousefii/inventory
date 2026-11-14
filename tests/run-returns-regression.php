<?php

// Same suite on Windows and CI; TestCase enforces SQLite :memory: before boot.
$root=dirname(__DIR__);
$files=[];
foreach (['Feature','Unit'] as $directory) {
    foreach (glob(__DIR__.'/'.$directory.'/*.php') as $file) {
        if (preg_match('/(SalesReturn|WarehouseInbound|PurchaseReturn|PersonAccount|ReturnsIntegrity|AccountStatementReadOnlyAccess|StocktakeSnapshotSchemaMigration|SupplierTransactionMigrationSafety)/',basename($file))) {
            $files[]=$file;
        }
    }
}
sort($files);
$arguments=array_merge([PHP_BINARY,__DIR__.'/run.php','--configuration',$root.'/phpunit.xml'],$files);
$process=proc_open($arguments,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root);
if (!is_resource($process)) exit(2);
exit(proc_close($process));
