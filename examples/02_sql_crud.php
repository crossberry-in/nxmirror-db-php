<?php

// 02 — SQL in full: exec, query, and the query builder
//
//   php examples/02_sql_crud.php     (or: composer run sql)
//
// Every NxDB SQL database is a real, isolated SQLite engine behind the API:
// genuine DDL, prepared statements, JOINs, transactions, indexes.
require __DIR__ . '/config.php';

use NxDb\NxDB;

$db = NxDB::connect(nxdb_sql_creds());

/* ---------- 1. DDL with exec() (multi-statement batch, no parameters) ---------- */
nxdb_banner('exec() — CREATE TABLE + INDEX');
$db->exec('
  CREATE TABLE IF NOT EXISTS products (
    id       INTEGER PRIMARY KEY,
    name     TEXT NOT NULL,
    price    REAL NOT NULL,
    stock    INTEGER NOT NULL DEFAULT 0,
    active   INTEGER NOT NULL DEFAULT 1
  );
  CREATE INDEX IF NOT EXISTS products_price ON products(price);
');

/* ---------- 2. INSERT with query() + prepared parameters ---------- */
nxdb_banner('query() — parameterized INSERT');
$ins = $db->query(
    'INSERT INTO products (name, price, stock) VALUES (?, ?, ?)',
    ['Coffee 250g', 7.5, 120]
);
echo 'changes: ', $ins['changes'], ' lastInsertRowid: ', $ins['lastInsertRowid'] ?? 'null', "\n";

$ids = [(int) $ins['lastInsertRowid']];
foreach ([['Tea 100g', 3.25, 80], ['Sugar 1kg', 1.99, 200], ['Milk 1L', 1.49, 0]] as [$name, $price, $stock]) {
    $r = $db->query(
        'INSERT INTO products (name, price, stock) VALUES (?, ?, ?)',
        [$name, $price, $stock]
    );
    $ids[] = (int) $r['lastInsertRowid'];
}
echo 'inserted ids: ', implode(', ', $ids), "\n";

/* ---------- 3. SELECT: WHERE / ORDER BY / aggregate ---------- */
nxdb_banner('query() — SELECT with parameters');
$cheap = $db->query(
    'SELECT id, name, price FROM products WHERE price < ? AND stock > ? ORDER BY price ASC',
    [5, 0]
);
nxdb_table($cheap['rows']);

$stats = $db->query(
    'SELECT COUNT(*) AS products, ROUND(SUM(price * stock), 2) AS stock_value, MIN(price) AS cheapest FROM products'
);
nxdb_table($stats['rows']);

/* ---------- 4. JOIN ---------- */
nxdb_banner('query() — JOIN demo');
$db->exec("
  CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(id),
    qty        INTEGER NOT NULL
  );
  INSERT INTO orders (product_id, qty) VALUES ({$ids[0]}, 2), ({$ids[1]}, 5);
");
$joined = $db->query('
  SELECT o.id AS order_id, p.name, p.price, o.qty, ROUND(p.price * o.qty, 2) AS total
  FROM orders o JOIN products p ON p.id = o.product_id
  ORDER BY total DESC
');
nxdb_table($joined['rows']);

/* ---------- 5. Transaction as an exec() batch ---------- */
nxdb_banner('exec() — BEGIN … COMMIT transaction');
$db->exec("
  BEGIN;
  UPDATE products SET stock = stock - 2 WHERE id = {$ids[0]};
  UPDATE products SET stock = stock - 5 WHERE id = {$ids[1]};
  INSERT INTO orders (product_id, qty) VALUES ({$ids[0]}, 2);
  COMMIT;
");
$after = $db->query('SELECT id, name, stock FROM products WHERE id IN (?, ?)', [$ids[0], $ids[1]]);
nxdb_table($after['rows']);

/* ---------- 6. Query builder — same thing, no SQL strings ---------- */
nxdb_banner('db.table() — query builder');
$db->table('products')->insert(['name' => 'Honey 500g', 'price' => 4.75, 'stock' => 30]);
$db->table('products')->insert([
    ['name' => 'Salt 500g', 'price' => 0.99, 'stock' => 500],
    ['name' => 'Pepper 50g', 'price' => 2.5, 'stock' => 45],
]);

$all = $db->table('products')->select();
echo 'all rows: ', count($all['rows']), "\n"; // select() returns the full result array — rows live in ['rows']

$cols = $db->table('products')->select(['name', 'price']);
echo 'column pick: ', json_encode($cols['rows'][0] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

$filtered = $db->table('products')
    ->where('price', '>', 2)       // eq | ne | gt | lt | gte | lte | like | in
    ->where(['active' => true])    // object form (AND-combined)
    ->orderBy('price', 'DESC')
    ->limit(3)
    ->offset(0)
    ->select();
nxdb_table($filtered['rows']);

$n = $db->table('products')->where('stock', '>', 10)->count();
echo 'count(stock > 10): ', $n, "\n";

$db->table('products')->update(['price' => 7.99], ['id' => $ids[0]]); // SET … WHERE
$db->table('products')->delete(['name' => 'Salt 500g']);

$finalCount = $db->table('products')->count();
echo 'final row count: ', $finalCount, "\n";

/* ---------- 7. Clean up this demo's tables ---------- */
$db->exec('DROP TABLE IF EXISTS orders; DROP TABLE IF EXISTS products;');
echo "\nOK — SQL demo finished (demo tables dropped).\n";
