<?php
// Opt-in only: this test deletes fixtures in a dedicated local audit database.
$testDatabase=getenv('INVENTORY_TEST_MYSQL_DATABASE') ?: '';
$testPort=(int)getenv('INVENTORY_TEST_MYSQL_PORT');
if(!preg_match('/^inventory_isolated_audit_[a-zA-Z0-9_]+$/',$testDatabase) || $testPort<1024){fwrite(STDERR,"Set a dedicated INVENTORY_TEST_MYSQL_DATABASE and INVENTORY_TEST_MYSQL_PORT; operational databases are refused.\n");exit(2);}
putenv('APP_ENV=testing');
require getenv('INVENTORY_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Event,Http};
config(['database.default'=>'isolated_audit','database.connections.isolated_audit'=>['driver'=>'mysql','host'=>'127.0.0.1','port'=>$testPort,'database'=>$testDatabase,'username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true]]);
if(DB::connection()->getDatabaseName()!==$testDatabase)throw new RuntimeException('Refusing non-isolated database');
Event::fake();Http::fake();
$wait=function(string $file){$until=microtime(true)+12;while(!is_file($file)){if(microtime(true)>$until)throw new RuntimeException('Barrier timeout');usleep(20000);}};
if(($argv[1]??'')==='parallel-worker'){
 $prefix=$argv[2];$id=(int)$argv[3];$seen=false;
 Event::fakeExcept([Illuminate\Database\Events\QueryExecuted::class]);
 DB::listen(function($event)use($prefix,$id,$wait,&$seen){if(!$seen && str_starts_with($event->sql,'update `warehouse_stocks`')){$seen=true;file_put_contents($prefix.'.'.$id,'1');$wait($prefix.'.'.(3-$id));}});
 try{App\Services\WarehouseStockService::change(1,1,1,$id);echo 'ok';}catch(Throwable $e){echo get_class($e).': '.$e->getMessage();exit(1);}exit;
}
if(($argv[1]??'')==='worker'){
 $prefix=$argv[2];$returnMode=($argv[3]??'')==='return';
 DB::transaction(function()use($prefix,$wait,$returnMode){DB::table('product_variants')->where('id',2)->first();file_put_contents($prefix.'.ready','1');$wait($prefix.'.commit');App\Services\WarehouseStockService::change($returnMode?2:1,1,1,$returnMode?1:2);});
 exit;
}
foreach([
 'CREATE TABLE IF NOT EXISTS products(id BIGINT PRIMARY KEY,name VARCHAR(80),stock BIGINT,reserved BIGINT DEFAULT 0,created_at DATETIME NULL,updated_at DATETIME NULL)',
 'CREATE TABLE IF NOT EXISTS product_variants(id BIGINT PRIMARY KEY,product_id BIGINT,variant_name VARCHAR(80),stock BIGINT,reserved BIGINT DEFAULT 0,created_at DATETIME NULL,updated_at DATETIME NULL)',
 'CREATE TABLE IF NOT EXISTS warehouses(id BIGINT PRIMARY KEY,name VARCHAR(80),type VARCHAR(20),is_active TINYINT,created_at DATETIME NULL,updated_at DATETIME NULL)',
 'CREATE TABLE IF NOT EXISTS warehouse_stocks(id BIGINT AUTO_INCREMENT PRIMARY KEY,warehouse_id BIGINT,product_id BIGINT,product_variant_id BIGINT NULL,quantity BIGINT,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE KEY stock_key(warehouse_id,product_variant_id))'
] as $sql)DB::statement($sql);
foreach(['warehouse_stocks','product_variants','products','warehouses'] as $table)DB::table($table)->delete();
DB::table('products')->insert(['id'=>1,'name'=>'Isolated concurrency test','stock'=>20]);
DB::table('product_variants')->insert([['id'=>1,'product_id'=>1,'variant_name'=>'A','stock'=>10],['id'=>2,'product_id'=>1,'variant_name'=>'B','stock'=>10]]);
DB::table('warehouses')->insert(['id'=>1,'name'=>'انبار مرکزی','type'=>'central','is_active'=>1]);
DB::table('warehouse_stocks')->insert([['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>1,'quantity'=>10],['warehouse_id'=>1,'product_id'=>1,'product_variant_id'=>2,'quantity'=>10]]);
DB::table('warehouses')->insert(['id'=>2,'name'=>'Returns','type'=>'return','is_active'=>1]);
$prefix=sys_get_temp_dir().'/inventory-concurrency-test-'.bin2hex(random_bytes(5));
if(($argv[1]??'')==='parallel'){
 $workers=[];
 foreach([1,2] as $id)$workers[$id]=proc_open([PHP_BINARY,__FILE__,'parallel-worker',$prefix,(string)$id],[0=>['pipe','r'],1=>['file',$prefix.'.'.$id.'.out','w'],2=>['file',$prefix.'.'.$id.'.err','w']],$pipes);
 $results=[];foreach($workers as $id=>$worker)$results[$id]=['exit'=>proc_close($worker),'out'=>file_get_contents($prefix.'.'.$id.'.out')];
 foreach($results as $result)if($result['exit']!==0)throw new RuntimeException($result['out']);
 foreach([DB::table('warehouse_stocks')->where('warehouse_id',1)->sum('quantity'),DB::table('products')->value('stock'),DB::table('product_variants')->sum('stock')] as $value)if((int)$value!==22)throw new RuntimeException('Concurrent stock projections diverged');
 echo json_encode($results,JSON_PRETTY_PRINT);exit;
}
$process=proc_open([PHP_BINARY,__FILE__,'worker',$prefix,$argv[1]??''],[0=>['pipe','r'],1=>['file',$prefix.'.out','w'],2=>['file',$prefix.'.err','w']],$pipes);
try{$wait($prefix.'.ready');App\Services\WarehouseStockService::change(1,1,1,1);file_put_contents($prefix.'.commit','1');$exit=proc_close($process);$process=null;if($exit!==0)throw new RuntimeException(file_get_contents($prefix.'.err'));$expected=($argv[1]??'')==='return'?21:22;foreach([DB::table('warehouse_stocks')->where('warehouse_id',1)->sum('quantity'),DB::table('products')->value('stock'),DB::table('product_variants')->sum('stock')] as $value)if((int)$value!==$expected)throw new RuntimeException('Stock projections diverged');echo json_encode(['isolated'=>true,'canonical_stock'=>(int)DB::table('warehouse_stocks')->where('warehouse_id',1)->sum('quantity'),'product_display'=>(int)DB::table('products')->value('stock'),'variant_display'=>(int)DB::table('product_variants')->sum('stock')],JSON_PRETTY_PRINT);
}finally{if(is_resource($process))proc_terminate($process);}
