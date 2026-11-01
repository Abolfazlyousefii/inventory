<?php
/**
 * INV-05 read-only SQL fixture verification, using PHP's PDO SQLite driver.
 * Does not open or mutate the application database.
 * Run: php tests/inv05_sql_test.php
 * The equivalent Python fixture is retained for environments without pdo_sqlite.
 */

declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function captured(string $pattern, string $source, string $description): string
{
    if (preg_match($pattern, $source, $matches) !== 1 || ! isset($matches[1])) {
        throw new RuntimeException('Cannot find ' . $description . ' in current command source.');
    }
    return $matches[1];
}

try {
    check(in_array('sqlite', PDO::getAvailableDrivers(), true), 'PHP pdo_sqlite extension is not enabled.');

    $source = file_get_contents(__DIR__.'/../app/Console/Commands/QuarantineZeroPriceVariants.php');
    check($source !== false, 'Cannot read quarantine command.');
    $candidateBlock = captured(
        '/(private function candidateRows\(\).*?)(?=private function priceVarianceRows\()/s',
        $source,
        'candidateRows() method'
    );
    foreach (["where('p.is_sellable', true)", "where('v.is_active', true)", "where('v.sales_enabled', true)"] as $clause) {
        check(str_contains($candidateBlock, $clause), 'Missing saleability filter: '.$clause);
    }
    $priceFilter = captured("/->whereRaw\\('([^']+)'\\)/", $candidateBlock, 'candidate price filter');
    $having = captured("/->havingRaw\\('([^']+)'\\)/", $candidateBlock, 'candidate stock HAVING clause');
    $select = captured('/->selectRaw\("(.+?)"\)/s', $candidateBlock, 'candidate aggregation');

    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(<<<'SQL'
CREATE TABLE products (id integer primary key, name text, is_sellable integer, uniform_sale_price integer);
CREATE TABLE product_variants (id integer primary key, product_id integer, variant_name text, variant_code text, is_active integer, sales_enabled integer, sell_price integer, stock integer);
CREATE TABLE warehouses (id integer primary key, type text);
CREATE TABLE warehouse_stocks (product_variant_id integer, warehouse_id integer, quantity integer);
INSERT INTO warehouses VALUES (1, 'central'),(2,'branch');
INSERT INTO products VALUES (100,'zero stock-price',1,5000),(101,'not for sale',0,NULL), (102,'positive priced',1,NULL), (103,'inactive',1,NULL),(104,'zero stock',1,NULL), (676,'mixed price A',1,NULL), (975,'mixed price B',1,NULL);
INSERT INTO product_variants VALUES
  (1001,100,'NULL', 'X',1,1,NULL,5),
  (1002,100,'zero','Y',1,1,0,9),
  (1003,100,'negative','Z',1,1,-2,4),
  (1010,101,'unsellable','U',1,1,0,2),
  (1020,102,'priced','P',1,1,20,4),
  (1030,103,'inactive','I',0,1,0,3),
  (1040,104,'empty','E',1,1,0,0),
  (6761,676,'color1','A',1,1,2000,2),(6762,676,'color2','B',1,1,3000,2),
  (9751,975,'length1','C',1,1,8000,1),(9752,975,'length2','D',1,1,12000,2);
INSERT INTO warehouse_stocks VALUES
  (1001,1,5),(1002,2,9),(1003,1,4),(1010,1,2),(1020,1,4),(1030,1,3),
  (6761,1,2),(6762,1,2),(9751,1,1),(9752,1,2);
SQL);

    $sql = "SELECT $select FROM product_variants v ".
           'JOIN products p ON p.id = v.product_id '.
           'LEFT JOIN warehouse_stocks ws ON ws.product_variant_id = v.id '.
           'LEFT JOIN warehouses w ON w.id = ws.warehouse_id '.
           "WHERE p.is_sellable = 1 AND v.is_active = 1 AND v.sales_enabled = 1 AND ($priceFilter) ".
           'GROUP BY p.id,p.name,v.id,v.variant_name,v.variant_code,v.sell_price,v.sales_enabled,p.uniform_sale_price '.
           "HAVING $having";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn (array $row): int => (int) $row['variant_id'], $rows);
    sort($ids);
    check($ids === [1001, 1002, 1003], 'Expected only 1001, 1002, 1003; observed '.json_encode($ids));
    $byId = array_column($rows, null, 'variant_id');
    check($byId[1001]['sell_price'] === null, 'Missing price was not NULL.');
    check((int) $byId[1001]['reference_price'] === 5000, 'Reference price not preserved.');
    check((int) $byId[1002]['non_central_stock'] === 9, 'Branch stock aggregation incorrect.');
    check((int) $byId[1003]['central_stock'] === 4, 'Central stock aggregation incorrect.');
    echo "PASS zero, NULL, negative, inactive, unsellable, no-stock, and valid-price variants are classified correctly\n";

    $varianceBlock = captured('/(private function priceVarianceRows\(\).*?)(?=private function priceVarianceVariants\()/s', $source, 'priceVarianceRows() method');
    check(str_contains($varianceBlock, "havingRaw('COUNT(DISTINCT v.sell_price) > 1')"), 'Missing positive-price divergence HAVING filter.');
    foreach (["where('p.is_sellable', true)", "where('v.is_active', true)", "where('v.sales_enabled', true)", "where('v.sell_price', '>', 0)"] as $clause) {
        check(str_contains($varianceBlock, $clause), 'Missing variance filter: '.$clause);
    }
    $varianceSelect = captured("/->selectRaw\\('([^']+)'\\)/", $varianceBlock, 'price variance aggregation');
    $varianceSql = "SELECT $varianceSelect FROM products p JOIN product_variants v ON v.product_id = p.id ".
                   'WHERE p.is_sellable = 1 AND v.is_active = 1 AND v.sales_enabled = 1 AND v.sell_price > 0 '.
                   'GROUP BY p.id,p.name,p.uniform_sale_price HAVING COUNT(DISTINCT v.sell_price) > 1';
    $varianceIds = array_map('intval', $db->query($varianceSql)->fetchAll(PDO::FETCH_COLUMN));
    sort($varianceIds);
    check($varianceIds === [676, 975], 'Expected price-divergence products 676 and 975, got '.json_encode($varianceIds));
    echo "PASS distinct positive variant prices on products 676 and 975 are reported for review, not overwritten\n";

    check((int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() === 7, 'Unexpected product count after read-only queries.');
    check((int) $db->query('SELECT SUM(quantity) FROM warehouse_stocks')->fetchColumn() === 34, 'Warehouse stock changed.');
    echo "PASS fixture data remained unchanged (read-only SQL)\n";
    echo "All INV-05 SQL fixture checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL INV-05 SQL fixture: {$error->getMessage()}\n");
    exit(1);
}
