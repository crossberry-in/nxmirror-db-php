<?php

// 05 — Error handling: NxDbError, error codes, the wrong_engine guard
//
//   php examples/05_error_handling.php     (or: composer run errors)
//
// Every SDK failure throws an NxDbError with a stable string ->errorCode and,
// for server errors, an HTTP ->status. Catch them by code — never by parsing
// messages.
require __DIR__ . '/config.php';

use NxDb\NxDbError;
use NxDb\NxDB;

/** runs $fn, returns the caught Throwable (or null) */
function nxdb_catch_error(callable $fn): ?Throwable
{
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/* ---------- 1. unauthorized (401) — wrong password ---------- */
nxdb_banner('401 unauthorized');
$creds = nxdb_sql_creds();
$e1 = nxdb_catch_error(static function () use ($creds) {
    $bad = NxDB::connect(['databaseId' => $creds['databaseId'], 'password' => 'definitely-wrong']);
    $bad->connectInfo(); // first real request → server rejects
});
echo json_encode([
    'class' => $e1 === null ? null : get_class($e1), // NxDbError
    'code' => $e1?->errorCode,                       // "invalid_credentials"
    'status' => $e1?->status,                        // 401
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

/* ---------- 2. sql_error (400) — SQLite rejected the statement ---------- */
nxdb_banner('400 sql_error');
$db = NxDB::connect(nxdb_sql_creds());
$e2 = nxdb_catch_error(static fn () => $db->query('SELECT * FROM table_that_does_not_exist'));
echo json_encode([
    'code' => $e2?->errorCode,
    'status' => $e2?->status,
    'message' => $e2 === null ? null : substr($e2->getMessage(), 0, 60) . '…',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

/* ---------- 3. wrong_engine — CLIENT-SIDE guard, no network round-trip ---------- */
nxdb_banner('wrong_engine guard (sqlite db, db.nosql.* call)');
// The SDK knows each database's engine after connect() and fails fast BEFORE
// sending anything. Note $e3->status === null:
$e3 = nxdb_catch_error(static fn () => $db->nosql->insert('docs', ['hello' => 'world']));
echo json_encode([
    'code' => $e3?->errorCode,
    'status' => $e3?->status,
    'clientSide' => $e3 !== null && $e3->status === null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
echo 'hint: ', $e3 === null ? '' : implode(' ', array_slice(explode(' ', $e3->getMessage()), -14)), "\n";

/* ---------- 4. ambiguous_match (409) — NoSQL multi-match without many: true ---------- */
nxdb_banner('409 ambiguous_match (nosql db)');
$ndb = NxDB::connect(nxdb_nosql_creds());
$ndb->nosql->insert('demo', [['n' => 1, 'tier' => 'bronze'], ['n' => 2, 'tier' => 'bronze']]);
$e4 = nxdb_catch_error(static fn () => $ndb->nosql->update('demo', ['tier' => 'bronze'], ['tier' => 'gold']));
echo json_encode(['code' => $e4?->errorCode, 'status' => $e4?->status], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
// many: true would fix this:
$ndb->nosql->update('demo', ['tier' => 'bronze'], ['tier' => 'gold'], true);
$ndb->nosql->drop('demo');

/* ---------- 5. the universal catch pattern ---------- */
nxdb_banner('recommended pattern');
try {
    $db->query('SELECT * FROM products');
} catch (NxDbError $e) {
    switch ($e->errorCode) {
        case 'unauthorized':
        case 'invalid_credentials':     echo "check credentials\n"; break;
        case 'wrong_database':          echo "credentials belong to another database\n"; break;
        case 'insufficient_permission': echo "token lacks this permission\n"; break;
        case 'wrong_engine':            echo "SQL vs NoSQL mismatch — see README\n"; break;
        case 'rate_limited':            echo "slow down (retries already honored)\n"; break;
        case 'sql_error':               echo 'fix the SQL: ', $e->getMessage(), "\n"; break;
        default:                        echo 'unhandled: ', $e->errorCode, ' ', $e->getMessage(), "\n";
    }
}

echo "\nFull code table: README.md → Errors.\n";
echo "OK — error handling demo finished.\n";
