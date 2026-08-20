<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recoveryOutputRoot = storage_path('framework/testing/recovery-'.Str::uuid());
});

afterEach(function (): void {
    if (isset($this->recoveryOutputRoot) && File::isDirectory($this->recoveryOutputRoot)) {
        File::deleteDirectory($this->recoveryOutputRoot);
    }
});

function recoveryExportDirectory(string $root): string
{
    $directories = File::directories($root);
    expect($directories)->toHaveCount(1);

    return $directories[0];
}

function recoveryJson(string $directory, string $file): array
{
    $path = $directory.DIRECTORY_SEPARATOR.$file;
    expect(File::exists($path))->toBeTrue();

    return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
}

function createRecoveryCustomer(string $mobile, string $updatedAt, array $overrides = []): int
{
    return DB::table('customers')->insertGetId(array_merge([
        'first_name' => 'Recovery',
        'last_name' => 'Customer',
        'mobile' => $mobile,
        'opening_balance' => 0,
        'is_active' => true,
        'password' => 'must-never-be-exported',
        'created_at' => '2026-08-01 10:00:00',
        'updated_at' => $updatedAt,
    ], $overrides));
}

function createRecoveryProduct(string $sku, string $updatedAt = '2026-08-01 10:00:00'): int
{
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Recovery '.$sku,
        'created_at' => '2026-08-01 10:00:00',
        'updated_at' => '2026-08-01 10:00:00',
    ]);

    return DB::table('products')->insertGetId([
        'category_id' => $categoryId,
        'name' => 'Recovery product '.$sku,
        'sku' => $sku,
        'stock' => 12,
        'price' => 1000,
        'created_at' => '2026-08-01 10:00:00',
        'updated_at' => $updatedAt,
    ]);
}

function createRecoveryInvoice(int $customerId, string $uuid, string $createdAt, string $updatedAt): int
{
    return DB::table('invoices')->insertGetId([
        'uuid' => $uuid,
        'customer_id' => $customerId,
        'customer_name' => 'Recovery Customer',
        'customer_mobile' => '09120000000',
        'shipping_price' => 0,
        'discount_amount' => 0,
        'subtotal' => 2000,
        'total' => 2000,
        'status' => 'pending_warehouse_approval',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);
}

function createRecoveryInvoiceItem(int $invoiceId, int $productId, string $createdAt, string $updatedAt, int $quantity = 1): int
{
    return DB::table('invoice_items')->insertGetId([
        'invoice_id' => $invoiceId,
        'product_id' => $productId,
        'quantity' => $quantity,
        'price' => 1000,
        'line_total' => $quantity * 1000,
        'sort_order' => 1,
        'line_discount_amount' => 0,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);
}

it('exports new and updated invoices and expands a changed child to the complete invoice snapshot', function (): void {
    $cutoff = '2026-08-19 00:00:00';
    $old = '2026-08-01 10:00:00';
    $recent = '2026-08-19 12:00:00';
    $productId = createRecoveryProduct('REC-1');

    $newCustomer = createRecoveryCustomer('09120000001', $old);
    $newInvoice = createRecoveryInvoice($newCustomer, 'REC-NEW', $recent, $recent);
    createRecoveryInvoiceItem($newInvoice, $productId, $recent, $recent);

    $updatedCustomer = createRecoveryCustomer('09120000002', $old);
    $updatedInvoice = createRecoveryInvoice($updatedCustomer, 'REC-UPDATED', $old, $recent);
    createRecoveryInvoiceItem($updatedInvoice, $productId, $old, $old);

    $childCustomer = createRecoveryCustomer('09120000003', $old);
    $childInvoice = createRecoveryInvoice($childCustomer, 'REC-CHILD', $old, $old);
    createRecoveryInvoiceItem($childInvoice, $productId, $old, $recent);
    createRecoveryInvoiceItem($childInvoice, $productId, $old, $old, 2);

    $stableCustomer = createRecoveryCustomer('09120000004', $old);
    $stableInvoice = createRecoveryInvoice($stableCustomer, 'REC-STABLE', $old, $old);
    createRecoveryInvoiceItem($stableInvoice, $productId, $old, $old);

    $this->artisan('recovery:export-delta', [
        '--since' => $cutoff,
        '--output' => $this->recoveryOutputRoot,
        '--label' => 'local',
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    $invoices = recoveryJson($directory, 'invoices.json');
    $invoiceIds = collect($invoices)->pluck('id')->all();

    expect($invoiceIds)
        ->toContain($newInvoice, $updatedInvoice, $childInvoice)
        ->not->toContain($stableInvoice);

    $child = collect($invoices)->firstWhere('id', $childInvoice);
    expect($child['uuid'])->toBe('REC-CHILD')
        ->and($child['customer_id'])->toBe($childCustomer)
        ->and($child['item_count'])->toBe(2)
        ->and($child['items_snapshot'])->toHaveCount(2);

    $itemRows = recoveryJson($directory, 'invoice_items.json');
    expect(collect($itemRows)->where('invoice_id', $childInvoice))->toHaveCount(2);

    $customerRows = recoveryJson($directory, 'customers.json');
    expect(collect($customerRows)->pluck('id')->all())
        ->toContain($newCustomer, $updatedCustomer, $childCustomer)
        ->not->toContain($stableCustomer);
});

it('exports related stock, inbound queue data, and marks current stock tables snapshot only', function (): void {
    $old = '2026-08-01 10:00:00';
    $recent = '2026-08-19 12:00:00';
    $customerId = createRecoveryCustomer('09120000005', $old);
    $productId = createRecoveryProduct('REC-STOCK');
    $invoiceId = createRecoveryInvoice($customerId, 'REC-STOCK-INVOICE', $old, $old);
    createRecoveryInvoiceItem($invoiceId, $productId, $old, $old);
    $user = User::factory()->create();

    $warehouseId = DB::table('warehouses')->insertGetId([
        'name' => 'Recovery warehouse',
        'type' => 'central',
        'is_active' => true,
        'created_at' => $old,
        'updated_at' => $old,
    ]);
    DB::table('warehouse_stocks')->insert([
        'warehouse_id' => $warehouseId,
        'product_id' => $productId,
        'quantity' => 11,
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    $movementId = DB::table('stock_movements')->insertGetId([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'user_id' => $user->id,
        'type' => 'in',
        'reason' => 'adjustment',
        'quantity' => 1,
        'reference_type' => Invoice::class,
        'reference_id' => $invoiceId,
        'created_at' => $recent,
        'updated_at' => $recent,
    ]);

    $receiptId = DB::table('warehouse_inbound_receipts')->insertGetId([
        'receipt_number' => 'WIR-REC-1',
        'source_type' => 'invoice_adjustment',
        'source_id' => $invoiceId,
        'operation_key' => 'test',
        'status' => 'pending',
        'expected_quantity' => 2,
        'accepted_quantity' => 0,
        'created_at' => $recent,
        'updated_at' => $recent,
    ]);
    DB::table('warehouse_inbound_receipt_items')->insert([
        'receipt_id' => $receiptId,
        'product_id' => $productId,
        'product_name_snapshot' => 'Recovery product',
        'expected_quantity' => 2,
        'accepted_quantity' => 0,
        'suggested_warehouse_id' => $warehouseId,
        'stock_movement_id' => $movementId,
        'created_at' => $old,
        'updated_at' => $old,
    ]);

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    expect(recoveryJson($directory, 'stock_movements.json'))->toHaveCount(1)
        ->and(recoveryJson($directory, 'warehouse_inbound_receipts.json'))->toHaveCount(1)
        ->and(recoveryJson($directory, 'warehouse_inbound_receipt_items.json'))->toHaveCount(1)
        ->and(recoveryJson($directory, 'warehouse_stocks_snapshot.json'))->toHaveCount(1);

    $manifest = recoveryJson($directory, 'manifest.json');
    expect($manifest['snapshot_only_tables'])->toContain('warehouse_stocks', 'products')
        ->and($manifest['table_details']['warehouse_stocks']['snapshot_only'])->toBeTrue()
        ->and($manifest['read_only_database_access'])->toBeTrue();
});

it('removes sensitive fields, creates a valid zip, and executes no database write statement', function (): void {
    $customerId = createRecoveryCustomer('09120000006', '2026-08-19 12:00:00', [
        'last_crm_payload' => json_encode([
            'safe' => 'kept',
            'api_key' => 'remove-me',
            'nested' => ['token' => 'remove-me-too'],
        ]),
    ]);

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ltrim($query->sql);
    });

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--hours' => 1,
        '--output' => $this->recoveryOutputRoot,
        '--label' => 'ONLINE unsafe label',
    ])->assertSuccessful();

    $forbidden = collect($queries)->filter(fn (string $sql): bool => (bool) preg_match(
        '/^(insert|update|delete|truncate|alter|create|drop|replace|merge)\b/i',
        $sql,
    ));
    expect($forbidden)->toBeEmpty();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    $customer = collect(recoveryJson($directory, 'customers.json'))->firstWhere('id', $customerId);
    expect($customer)->not->toHaveKey('password')
        ->and($customer['last_crm_payload'])->toHaveKey('safe', 'kept')
        ->and($customer['last_crm_payload'])->not->toHaveKey('api_key')
        ->and($customer['last_crm_payload']['nested'])->not->toHaveKey('token');

    $manifest = recoveryJson($directory, 'manifest.json');
    expect($manifest['requested_cutoff']['since'])->toBe('2026-08-19 00:00:00')
        ->and($manifest['source_label'])->toBe('online-unsafe-label');

    $zipFiles = File::glob($this->recoveryOutputRoot.DIRECTORY_SEPARATOR.'*.zip');
    expect($zipFiles)->toHaveCount(1);

    $zip = new ZipArchive;
    expect($zip->open($zipFiles[0]))->toBeTrue()
        ->and($zip->locateName('manifest.json'))->not->toBeFalse()
        ->and($zip->locateName('customers.json'))->not->toBeFalse();
    $zip->close();
});

it('requires hours or since and validates positive hours', function (): void {
    $this->artisan('recovery:export-delta', [
        '--output' => $this->recoveryOutputRoot,
    ])->assertFailed();

    $this->artisan('recovery:export-delta', [
        '--hours' => 0,
        '--output' => $this->recoveryOutputRoot,
    ])->assertFailed();
});

it('does not export an unchanged old invoice without a changed relation', function (): void {
    $old = '2026-08-01 10:00:00';
    $customerId = createRecoveryCustomer('09120000007', $old);
    $invoiceId = createRecoveryInvoice($customerId, 'REC-UNCHANGED', $old, $old);

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $invoices = recoveryJson(recoveryExportDirectory($this->recoveryOutputRoot), 'invoices.json');
    expect(collect($invoices)->pluck('id')->all())->not->toContain($invoiceId);
});

it('exports every old item belonging to a changed invoice', function (): void {
    $old = '2026-08-01 10:00:00';
    $recent = '2026-08-19 12:00:00';
    $customerId = createRecoveryCustomer('09120000008', $old);
    $productId = createRecoveryProduct('REC-ALL-ITEMS');
    $invoiceId = createRecoveryInvoice($customerId, 'REC-ALL-ITEMS', $old, $recent);
    createRecoveryInvoiceItem($invoiceId, $productId, $old, $old);
    createRecoveryInvoiceItem($invoiceId, $productId, $old, $old, 2);

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    expect(recoveryJson($directory, 'invoice_items.json'))->toHaveCount(2)
        ->and(recoveryJson($directory, 'invoices.json')[0]['items_snapshot'])->toHaveCount(2);
});

it('exports the customer related to a changed invoice even when the customer is old', function (): void {
    $old = '2026-08-01 10:00:00';
    $customerId = createRecoveryCustomer('09120000009', $old);
    createRecoveryInvoice($customerId, 'REC-CUSTOMER', $old, '2026-08-19 12:00:00');

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $customers = recoveryJson(recoveryExportDirectory($this->recoveryOutputRoot), 'customers.json');
    expect(collect($customers)->pluck('id')->all())->toContain($customerId);
});

it('exports a changed stock movement and its otherwise old invoice', function (): void {
    $old = '2026-08-01 10:00:00';
    $customerId = createRecoveryCustomer('09120000010', $old);
    $productId = createRecoveryProduct('REC-MOVEMENT');
    $invoiceId = createRecoveryInvoice($customerId, 'REC-MOVEMENT', $old, $old);
    $user = User::factory()->create();

    DB::table('stock_movements')->insert([
        'product_id' => $productId,
        'user_id' => $user->id,
        'type' => 'out',
        'reason' => 'sale',
        'quantity' => 1,
        'reference_type' => Invoice::class,
        'reference_id' => $invoiceId,
        'created_at' => '2026-08-19 12:00:00',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    expect(recoveryJson($directory, 'stock_movements.json'))->toHaveCount(1)
        ->and(collect(recoveryJson($directory, 'invoices.json'))->pluck('id')->all())->toContain($invoiceId);
});

it('classifies product and warehouse stock exports as snapshot only', function (): void {
    $productId = createRecoveryProduct('REC-SNAPSHOT', '2026-08-19 12:00:00');

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    $products = recoveryJson($directory, 'products_snapshot.json');
    $manifest = recoveryJson($directory, 'manifest.json');

    expect(collect($products)->pluck('id')->all())->toContain($productId)
        ->and($manifest['snapshot_only_tables'])->toContain('products', 'warehouse_stocks')
        ->and($manifest['table_details']['products']['snapshot_only'])->toBeTrue();
});

it('never creates a users authentication export file', function (): void {
    User::factory()->create([
        'password' => 'secret-password',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    $this->artisan('recovery:export-delta', [
        '--since' => '2026-08-19 00:00:00',
        '--output' => $this->recoveryOutputRoot,
    ])->assertSuccessful();

    $directory = recoveryExportDirectory($this->recoveryOutputRoot);
    $manifest = recoveryJson($directory, 'manifest.json');

    expect(File::exists($directory.DIRECTORY_SEPARATOR.'users.json'))->toBeFalse()
        ->and($manifest['tables'])->not->toContain('users', 'customer_login_codes');
});
