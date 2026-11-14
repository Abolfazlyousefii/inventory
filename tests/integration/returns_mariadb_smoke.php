<?php

/**
 * Explicitly disposable, schema-only local clone. Never accepts inventory_local
 * or production names. No DROP, truncation, migration reset or business-data copy.
 * See docs/returns-inventory-audit.md for provisioning and execution.
 */
declare(strict_types=1);

$database = getenv('QA_RETURNS_DATABASE') ?: '';
if (! preg_match('/^inventory_returns_qa_[0-9]{8}_[a-z0-9]+$/D', $database)
    || getenv('QA_RETURNS_ALLOW_DISPOSABLE') !== 'yes') {
    fwrite(STDERR, "Refusing to use a non-disposable returns QA database.\n"); exit(2);
}
$values = ['APP_ENV'=>'testing', 'DB_CONNECTION'=>'mysql', 'DB_HOST'=>'127.0.0.1',
    'DB_PORT'=>getenv('QA_RETURNS_PORT') ?: '3306', 'DB_DATABASE'=>$database,
    'DB_USERNAME'=>getenv('QA_RETURNS_USER') ?: 'root', 'DB_PASSWORD'=>getenv('QA_RETURNS_PASSWORD') ?: '',
    'DB_URL'=>'', 'CACHE_STORE'=>'array', 'SESSION_DRIVER'=>'array', 'QUEUE_CONNECTION'=>'sync',
    'APP_CONFIG_CACHE'=>dirname(__DIR__,2).'/tmp/returns-qa-no-config-cache.php'];
foreach ($values as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value; }
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR,$e->getMessage()."\n");exit(1); });

use App\Models\{Category, Customer, CustomerLedger, Product, ProductVariant, Purchase, PurchaseReturn, SalesReturnDocument, StockMovement, Supplier, User, Warehouse, WarehouseInboundReceipt};
use App\Services\{PersonAccountStatementService, PurchaseReturnService, SalesReturnService, WarehouseInboundService, WarehouseStockService};
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

config(['database.connections.mysql.url'=>null, 'services.request_driven_site_stock'=>false]);
Http::fake();
function checkReturnQa(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); }
checkReturnQa(DB::getDatabaseName()===$database && DB::getDriverName()==='mysql', 'Unexpected database connection');
checkReturnQa(DB::selectOne('SELECT DATABASE() AS name')->name===$database, 'Physical database connection mismatch');
checkReturnQa(str_contains(strtolower((string)DB::selectOne('SELECT VERSION() AS version')->version),'mariadb'), 'This evidence runner expects MariaDB');

$worker = ($argv[1]??'') === '--worker';
if ($worker) {
    $input=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);
    echo "READY\n"; fflush(STDOUT); checkReturnQa(trim((string)fgets(STDIN))==='GO','Missing race barrier');
    try {
        $result=match($argv[2]) {
            'purchase'=>app(PurchaseReturnService::class)->create($input['data'],$input['actor']),
            'draft'=>app(SalesReturnService::class)->createDraft($input['data'],$input['actor']),
            'queue'=>app(WarehouseInboundService::class)->queueSalesReturn(SalesReturnDocument::findOrFail($input['document']),$input['actor']),
            'receive'=>app(WarehouseInboundService::class)->receive(WarehouseInboundReceipt::findOrFail($input['receipt']),$input['rows'],$input['actor']),
            'financial'=>app(App\Services\FinancialAccountDocumentService::class)->create(Supplier::findOrFail($input['supplier']),$input['data'],$input['actor']),
            'financial-revise'=>app(App\Services\FinancialAccountDocumentService::class)->revise(App\Models\FinancialAccountDocument::findOrFail($input['document']),$input['data'],$input['actor'],$input['cancel']??false),
            'showroom'=>app(App\Services\PreinvoiceDraftReservationService::class)->syncReservationRows($input['token'],$input['actor'],$input['data']),
            default=>throw new RuntimeException('Unknown worker operation'),
        };
        echo json_encode(['state'=>'ok','id'=>is_array($result)?null:$result?->id],JSON_THROW_ON_ERROR)."\n";
    } catch (ValidationException $e) { echo json_encode(['state'=>'rejected','fields'=>array_keys($e->errors())])."\n"; }
    exit;
}

if (($argv[1]??'')==='--schema') {
    checkReturnQa(Schema::getTableListing($database)===[], 'Schema import requires a new empty database');
    $sql=file_get_contents($argv[2]??'');
    checkReturnQa(is_string($sql) && !preg_match('/^\s*(DROP|USE|INSERT|CREATE\s+DATABASE)\b/im',$sql),'Only a non-destructive schema-only dump is allowed');
    DB::unprepared($sql);
    if (! Schema::hasTable('activity_logs')) {
        (require dirname(__DIR__).'/Support/LegacyMigrations/2026_02_10_000000_create_activity_logs_table.php')->up();
        (require dirname(__DIR__).'/Support/LegacyMigrations/2026_06_13_150700_widen_activity_logs_action_column.php')->up();
    }
    foreach (['2026_11_11_180000_create_supplier_account_transactions_table.php','2026_11_14_150000_add_explicit_sales_return_flow.php','2026_11_14_163000_create_financial_account_documents_table.php','2026_11_14_180000_add_financial_document_revision_state.php','2026_11_14_190000_add_sales_source_warehouse.php','2026_11_14_191000_scope_sales_reservations_by_source.php'] as $file) {
        (require dirname(__DIR__,2).'/database/migrations/'.$file)->up();
    }
    echo "Disposable schema provisioned; no business rows copied.\n"; exit;
}

foreach (['purchases','purchase_returns','sales_return_documents','users','customers','stock_movements','supplier_ledgers','warehouse_stocks'] as $table) {
    checkReturnQa(DB::table($table)->count()===0,'Fixture database must have no previous business rows: '.$table);
}

function raceReturns(string $operation, array $inputs): array {
    $workers=[];
    foreach ($inputs as $input) {
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',$operation,base64_encode(json_encode($input,JSON_THROW_ON_ERROR))],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        checkReturnQa(is_resource($process),'Could not start concurrent PHP worker');
        $workers[]=[$process,$pipes];
    }
    foreach ($workers as [$process,$pipes]) checkReturnQa(trim((string)fgets($pipes[1]))==='READY','Worker did not reach race barrier');
    foreach ($workers as [$process,$pipes]) { fwrite($pipes[0],"GO\n");fflush($pipes[0]);fclose($pipes[0]); }
    $results=[];
    foreach ($workers as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        checkReturnQa(proc_close($process)===0,'Worker failed: '.$error.' '.$out);
        $results[]=json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);
    }
    return $results;
}

DB::beginTransaction();
DB::table('purchase_return_sequences')->insert(['id'=>1,'last_number'=>0]);
$actor=User::factory()->create();$secondActor=User::factory()->create();$customer=Customer::factory()->create();
$owner=\Spatie\Permission\Models\Role::findOrCreate('Owner','web');$actor->assignRole($owner);$secondActor->assignRole($owner);
$category=Category::create(['name'=>'Disposable returns QA']);
$product=Product::create(['category_id'=>$category->id,'name'=>'QA only','sku'=>'QA-RETURNS','stock'=>0,'price'=>1000]);
$variant=ProductVariant::create(['product_id'=>$product->id,'variant_name'=>'QA','variant_code'=>'QA-RETURNS','stock'=>0,'reserved'=>4,'is_active'=>true,'sales_enabled'=>true,'sell_price'=>1000]);
$central=WarehouseStockService::centralWarehouseId();$returns=Warehouse::create(['name'=>'انبار مرجوعی','type'=>'return','is_active'=>true]);
$sources=[];
foreach (['A'=>50,'B'=>20] as $name=>$quantity) {
    $supplier=Supplier::create(['name'=>'QA supplier '.$name]);
    $purchase=Purchase::create(['supplier_id'=>$supplier->id,'user_id'=>$actor->id,'purchased_at'=>now(),'subtotal_amount'=>$quantity*1000,'total_amount'=>$quantity*1000]);
    $item=$purchase->items()->create(['product_id'=>$product->id,'product_variant_id'=>$variant->id,'product_name'=>$product->name,'product_code'=>$product->sku,'variant_name'=>'QA','quantity'=>$quantity,'buy_price'=>1000,'sell_price'=>1000,'line_subtotal'=>$quantity*1000,'line_total'=>$quantity*1000]);
    $sources[$name]=compact('supplier','purchase','item');
}
WarehouseStockService::set($central,$product->id,$variant->id,100); // A50 + adjustment30 + B20; free stock excludes the four reserved units.
$payload=fn(string $source,int $qty,string $token)=>['submission_token'=>$token,'supplier_id'=>$sources[$source]['supplier']->id,
    'purchase_id'=>$sources[$source]['purchase']->id,'warehouse_id'=>$central,'returned_at'=>'2026-11-14',
    'items'=>[['purchase_item_id'=>$sources[$source]['item']->id,'quantity'=>$qty]]];
$service=app(PurchaseReturnService::class);
$first=$service->create($payload('A',40,(string)Str::uuid()),$actor->id);
try {$service->create($payload('A',11,(string)Str::uuid()),$actor->id);throw new RuntimeException('A exceeded its purchase capacity');}catch(ValidationException){}
DB::commit();
$result=raceReturns('purchase',array_map(fn($token)=>['data'=>$payload('A',6,$token),'actor'=>$actor->id],[(string)Str::uuid(),(string)Str::uuid()]));
checkReturnQa(count(array_filter($result,fn($r)=>$r['state']==='ok'))===1,'Concurrent partial returns bypassed source purchase capacity');
checkReturnQa($service->returnedQuantity($sources['A']['item']->id)===46,'Incorrect serialized return quantity');
$same=['data'=>$payload('B',2,(string)Str::uuid()),'actor'=>$actor->id];
$result=raceReturns('purchase',[$same,$same]);
checkReturnQa($result[0]['state']==='ok'&&$result[1]['state']==='ok'&&$result[0]['id']===$result[1]['id'],'Idempotent concurrent submissions diverged');
checkReturnQa(WarehouseStockService::available($central,$product->id,$variant->id)===52,'Stock was deducted twice');

$doc=SalesReturnDocument::create(['document_number'=>'QA-1','source_type'=>'sazeh_hesab','status'=>'draft','return_flow'=>'physical',
    'customer_id'=>$customer->id,'external_invoice_number'=>'QA-ONLY','external_invoice_date'=>now()->toDateString(),
    'default_destination_warehouse_id'=>$returns->id,'return_reason'=>'damaged_product','total_quantity'=>3,'items_count'=>1,'total_refund_amount'=>3000,'created_by'=>$actor->id]);
$doc->items()->create(['product_id'=>$product->id,'product_variant_id'=>$variant->id,'item_source'=>'existing_product','item_condition'=>'damaged',
    'destination_warehouse_id'=>$returns->id,'return_quantity'=>3,'refund_unit_price'=>1000,'refund_amount'=>3000,'sell_price'=>1000]);
$same=['document'=>$doc->id,'actor'=>$actor->id];$result=raceReturns('queue',[$same,array_replace($same,['actor'=>$secondActor->id])]);
checkReturnQa($result[0]['id']===$result[1]['id'] && WarehouseInboundReceipt::count()===1,'Concurrent queue duplicated receipts');
$doc->update(['status'=>'pending_warehouse']);$receipt=WarehouseInboundReceipt::sole();
$same=['receipt'=>$receipt->id,'actor'=>$actor->id,'rows'=>[['id'=>$receipt->items->sole()->id,'accepted_quantity'=>3,'received_warehouse_id'=>$returns->id]]];
$result=raceReturns('receive',[$same,array_replace($same,['actor'=>$secondActor->id])]);
checkReturnQa(count(array_filter($result,fn($r)=>$r['state']==='ok'))===1,'Receipt accepted twice');
checkReturnQa(WarehouseStockService::available($returns->id,$product->id,$variant->id)===3,'Return warehouse quantity duplicated');
checkReturnQa(CustomerLedger::where('customer_id',$customer->id)->count()===1,'Customer credited more than once');
checkReturnQa((int)$variant->fresh()->reserved===4,'Reserved stock changed');

$same=['actor'=>$actor->id,'data'=>['submission_token'=>(string)Str::uuid(),'source_type'=>'sazeh_hesab',
    'customer_id'=>$customer->id,'external_invoice_number'=>'QA-REPLAY','external_invoice_date'=>now()->toDateString(),
    'default_destination_warehouse_id'=>$returns->id,'return_reason'=>'damaged_product',
    'items'=>[['item_source'=>'existing_product','product_variant_id'=>$variant->id,'destination_warehouse_id'=>$returns->id,
        'item_condition'=>'damaged','return_quantity'=>1,'refund_unit_price'=>1000,'sell_price'=>1000]]]];
$result=raceReturns('draft',[$same,$same]);
checkReturnQa($result[0]['state']==='ok'&&$result[1]['state']==='ok'&&$result[0]['id']===$result[1]['id']&&SalesReturnDocument::count()===2,'Concurrent sales-return submission duplicated its document');

$base=DB::table('supplier_ledgers')->where('reference_type',PurchaseReturn::class)->where('reference_id',$first->id)->first();
$edited=$service->update($first,$payload('A',39,(string)Str::uuid())+['version'=>1],$actor->id);
$service->delete($edited,2,$actor->id,'QA audited reversal');
checkReturnQa((array)DB::table('supplier_ledgers')->where('id',$base->id)->first()===(array)$base,'Historical supplier posting was rewritten');
$statement=app(PersonAccountStatementService::class)->statement('supplier-'.$sources['A']['supplier']->id);
checkReturnQa($statement['netBalance']===6000,'Unified supplier statement failed to include the audit reversal exactly once');

$financialStock=WarehouseStockService::available($central,$product->id,$variant->id);
$financialMovements=StockMovement::count();
$same=['supplier'=>$sources['A']['supplier']->id,'actor'=>$actor->id,'data'=>[
    'title'=>'QA financial document','description'=>'Disposable concurrent financial document test',
    'type'=>'debit','document_amount'=>700,'document_date'=>now()->toDateString(),'submission_token'=>(string)Str::uuid()]];
$result=raceReturns('financial',[$same,$same]);
checkReturnQa($result[0]['state']==='ok'&&$result[1]['state']==='ok'&&$result[0]['id']===$result[1]['id'],'Concurrent financial submission diverged');
checkReturnQa(App\Models\FinancialAccountDocument::count()===1 && DB::table('supplier_ledgers')->where('reference_type',App\Models\FinancialAccountDocument::class)->count()===1,'Financial document posted twice');
$financialStatement=app(PersonAccountStatementService::class)->statement('supplier-only-'.$sources['A']['supplier']->id);
checkReturnQa($financialStatement['netBalance']===6700,'Financial document did not update the supplier balance exactly once');
checkReturnQa(WarehouseStockService::available($central,$product->id,$variant->id)===$financialStock && StockMovement::count()===$financialMovements,'Financial document changed inventory');
checkReturnQa((int)DB::table('activity_logs')->where('action','financial_account_document_created')->value('user_id')===$actor->id,'Financial document audit actor was lost');

$revision=['document'=>$result[0]['id'],'actor'=>$actor->id,'data'=>array_merge($same['data'],['version'=>1,'document_amount'=>900])];
$revisions=raceReturns('financial-revise',[$revision,$revision]);
checkReturnQa(count(array_filter($revisions,fn($r)=>$r['state']==='ok'))===1,'Concurrent financial editing bypassed version guard');
checkReturnQa(app(PersonAccountStatementService::class)->statement('supplier-only-'.$sources['A']['supplier']->id)['netBalance']===6900,'Financial editing changed more than the document difference');
$revision['cancel']=true;$revision['data']=['version'=>2,'reason'=>'Disposable cancellation'];
$cancellations=raceReturns('financial-revise',[$revision,$revision]);
checkReturnQa(count(array_filter($cancellations,fn($r)=>$r['state']==='ok'))===1,'Concurrent cancellation applied more than once');
checkReturnQa(app(PersonAccountStatementService::class)->statement('supplier-only-'.$sources['A']['supplier']->id)['netBalance']===6000,'Cancellation did not restore the previous balance');
checkReturnQa(WarehouseStockService::available($central,$product->id,$variant->id)===$financialStock && StockMovement::count()===$financialMovements,'Financial revision changed inventory');

$before=WarehouseStockService::available($central,$product->id,$variant->id);
$beforeLedgers=DB::table('supplier_ledgers')->count();
App\Models\PurchaseReturnRevision::creating(fn()=>throw new RuntimeException('QA injected audit failure'));
try {$service->create($payload('B',1,(string)Str::uuid()),$actor->id);throw new LogicException('Rollback injection failed');}
catch(RuntimeException $e){checkReturnQa($e->getMessage()==='QA injected audit failure','Unexpected failure');}
checkReturnQa(WarehouseStockService::available($central,$product->id,$variant->id)===$before && DB::table('supplier_ledgers')->count()===$beforeLedgers,'Transaction rollback changed stock or finance');
$showroom=Warehouse::firstOrCreate(['type'=>'showroom','name'=>'انبار شوروم'],['is_active'=>true]);
$showProduct=Product::create(['category_id'=>$category->id,'name'=>'QA showroom sale','sku'=>'QA-SHOWROOM-SALE','stock'=>0,'reserved'=>0,'price'=>1000,'is_sellable'=>true]);
$showVariant=ProductVariant::create(['product_id'=>$showProduct->id,'variant_name'=>'QA showroom','variant_code'=>'QA-SHOWSALE','stock'=>0,'reserved'=>0,'sell_price'=>1000,'is_active'=>true,'sales_enabled'=>true]);
WarehouseStockService::set($central,$showProduct->id,$showVariant->id,0);
WarehouseStockService::set($showroom->id,$showProduct->id,$showVariant->id,5);
$salesData=[['product_id'=>$showProduct->id,'variant_id'=>$showVariant->id,'quantity'=>3,'source_warehouse_id'=>$showroom->id]];
$showResults=raceReturns('showroom',[['actor'=>$actor->id,'token'=>(string)Str::uuid(),'data'=>$salesData],['actor'=>$secondActor->id,'token'=>(string)Str::uuid(),'data'=>$salesData]]);
checkReturnQa(count(array_filter($showResults,fn($r)=>$r['state']==='ok'))===1,'Concurrent showroom sales oversold stock');
checkReturnQa(WarehouseStockService::available($showroom->id,$showProduct->id,$showVariant->id)===2 && (int)$showVariant->fresh()->stock===0,'Showroom sale modified central stock');
$held=App\Models\PreinvoiceDraftReservation::where('product_id',$showProduct->id)->whereNull('released_at')->sole();
app(App\Services\PreinvoiceDraftReservationService::class)->releaseTokenReservations($held->token,$held->user_id,'manual_release');
checkReturnQa(WarehouseStockService::available($showroom->id,$showProduct->id,$showVariant->id)===5 && (int)$showVariant->fresh()->reserved===0,'Showroom release corrupted stock or cache');
echo json_encode(['result'=>'PASS','showroom_concurrency'=>'PASS','engine'=>DB::selectOne('SELECT VERSION() AS version')->version,
    'checks'=>['source_capacity_A50_adjustment30_B20','concurrent_source_capacity','concurrent_idempotency','unique_inbound_operation','concurrent_receive_once_two_users','customer_credit_once','reserved_preserved','concurrent_sales_return_creation','immutable_supplier_posting_and_reversal','unified_statement_SQL','transaction_rollback','concurrent_financial_document_once','financial_document_preserves_inventory_and_actor','concurrent_financial_edit_and_cancel_once'],
    'fixture_database'=>$database,'production_touched'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
