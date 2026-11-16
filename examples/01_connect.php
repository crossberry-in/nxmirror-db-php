<?php

// 01 — Connect and introspect
//
//   php examples/01_connect.php     (or: composer run connect)
//
// Shows the standard connection flow used by every other example:
//   require -> connect -> connectInfo -> details -> first query.
require __DIR__ . '/config.php';

use NxDb\NxDB;

// 1. connect — full-access database password (server-side code only!)
//    Alternatives: ['databaseId' => ..., 'token' => ...]  or  appId + appToken.
$db = NxDB::connect(nxdb_sql_creds());
echo 'connected: ', $db->databaseId, "\n";

// 2. connectInfo() — who am I, how fast, which engine, which SDK?
$info = $db->connectInfo();
nxdb_banner('connectInfo()');
echo json_encode([
    'connected' => $info['connected'] ?? null,
    'latencyMs' => $info['latencyMs'] ?? null,
    'authMethod' => $info['authMethod'] ?? null,
    'permissions' => $info['permissions'] ?? null,
    'engine' => $info['database']['engine'] ?? null,
    'database' => $info['database']['name'] ?? null,
    'sdk' => $info['sdk'] ?? null, // { version, apiBase }
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

// 3. details() — database metadata + current table list
$det = $db->details();
nxdb_banner('details()');
$tables = array_map(
    static fn ($t) => is_array($t) ? (string) ($t['name'] ?? '?') : (string) $t,
    is_array($det['tables'] ?? null) ? $det['tables'] : []
);
echo 'tables: ', $tables !== [] ? implode(', ', $tables) : '(none yet)', "\n";

// 4. first query — scalar expression, no tables needed
$r = $db->query('SELECT sqlite_version() AS sqlite_version, 40 + 2 AS answer');
nxdb_banner('first query');
nxdb_table($r['rows']);
// result keys: rows, columns, changes, executionMs, truncated, lastInsertRowid
echo 'result keys: ', implode(', ', array_keys($r)), "\n";

echo "\nOK — connect works. Next: php examples/02_sql_crud.php\n";
