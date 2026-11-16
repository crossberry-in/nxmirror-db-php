<?php

// 03 — NoSQL document collections (engine "nosql" database)
//
//   php examples/03_nosql_crud.php     (or: composer run nosql)
//
// IMPORTANT: $db->nosql->* needs a database created with engine "nosql"
// (dashboard, or `composer run setup` which makes one for you).
// Calling it on a sqlite database throws a client-side wrong_engine error —
// see 05_error_handling.php.
require __DIR__ . '/config.php';

use NxDb\NxDB;

$db = NxDB::connect(nxdb_nosql_creds());

/* ---------- 1. insert one / many ---------- */
nxdb_banner('nosql.insert()');
$one = $db->nosql->insert('users', [
    'username' => 'noxs',
    'score' => 9.5,
    'tags' => ['pro'],
    'profile' => ['city' => 'Chennai', 'verified' => true],
]);
echo 'inserted: ', $one['inserted'], ' id: ', $one['lastInsertId'], "\n";

$many = $db->nosql->insert('users', [
    ['username' => 'arun', 'score' => 7, 'profile' => ['city' => 'Madurai']],
    ['username' => 'bala', 'score' => 12, 'active' => false, 'profile' => ['city' => 'Chennai']],
]);
echo 'inserted many: ', $many['inserted'], ' ids: ', implode(', ', $many['ids']), "\n";

// Every document gets immutable metadata: _id, _createdAt, _updatedAt
$got = $db->nosql->get('users', $one['lastInsertId']);
nxdb_banner('nosql.get()');
echo json_encode($got, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

/* ---------- 2. find() with filters ---------- */
nxdb_banner('nosql.find() — dot-path into nested objects');
$byCity = $db->nosql->find('users', ['filter' => ['profile.city' => 'Chennai']]);
echo 'total: ', $byCity['total'], ' names: ',
    implode(', ', array_map(static fn ($d) => $d['username'] ?? '', $byCity['docs'])), "\n";

nxdb_banner('nosql.find() — $in + sort');
$ranked = $db->nosql->find('users', [
    'filter' => ['username' => ['$in' => ['noxs', 'arun', 'bala']]],
    'sort' => ['score' => -1], // descending; ['score' => 1] would be ascending
    'limit' => 10,
    'projection' => ['username', 'score'],
]);
nxdb_table($ranked['docs']);

nxdb_banner('nosql.find() — $or + operators');
// Operators: $eq $ne $gt $gte $lt $lte $in $nin $exists $contains $startsWith, top-level $or
$gold = $db->nosql->find('users', [
    'filter' => ['$or' => [['active' => false], ['score' => ['$gte' => 12]]]],
]);
echo 'matched: ', implode(', ', array_map(static fn ($d) => $d['username'] ?? '', $gold['docs'])), "\n";

nxdb_banner('nosql.count()');
echo 'all: ', $db->nosql->count('users'), ' | Chennai: ',
    $db->nosql->count('users', ['profile.city' => 'Chennai']), "\n";

/* ---------- 3. update / replace ---------- */
nxdb_banner('nosql.update()');
$db->nosql->update('users', $one['lastInsertId'], ['score' => 10, 'profile.city' => 'Coimbatore']);
$after = $db->nosql->get('users', $one['lastInsertId']);
echo 'patched: ', json_encode([
    'score' => $after['score'] ?? null,
    'city' => $after['profile']['city'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

// Multi-match updates REQUIRE many: true (otherwise 409 ambiguous_match)
$bulk = $db->nosql->update('users', ['score' => ['$gte' => 12]], ['tier' => 'gold'], true);
echo 'bulk update matched: ', $bulk['updated'], "\n";

nxdb_banner('nosql.replace()');
$rep = $db->nosql->replace('users', $many['ids'][0], ['username' => 'arun-v2', 'score' => 7]);
echo 'kept _id: ', ($rep['replaced']['_id'] ?? null) === $many['ids'][0] ? 'true' : 'false',
    ' name: ', $rep['replaced']['username'] ?? '', "\n";

/* ---------- 4. delete / list / drop ---------- */
nxdb_banner('nosql.delete() + list() + drop()');
$del = $db->nosql->delete('users', $many['ids'][1]);
echo 'deleted by id: ', $del['deleted'], "\n";

$left = $db->nosql->delete('users', ['score' => ['$lt' => 8]], true);
echo 'deleted by filter: ', $left['deleted'], "\n";

echo 'collections: ', json_encode($db->nosql->list(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

// collections are created on first insert — drop() removes the whole collection
$db->nosql->insert('scratch', ['a' => 1]);
$dropped = $db->nosql->drop('scratch');
echo 'dropped: ', $dropped['dropped'], ' (had ', $dropped['documents'], " documents)\n";

echo "\nOK — NoSQL demo finished.\n";
