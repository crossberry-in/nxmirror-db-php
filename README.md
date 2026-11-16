# nxmirror-db — official PHP SDK for NxMirror DB

[![Latest Version on Packagist](https://img.shields.io/packagist/v/crossberry-in/nxmirror-db.svg)](https://packagist.org/packages/crossberry-in/nxmirror-db)
[![License](https://img.shields.io/packagist/l/crossberry-in/nxmirror-db.svg)](https://packagist.org/packages/crossberry-in/nxmirror-db)
[![PHP Version](https://img.shields.io/packagist/php-v/crossberry-in/nxmirror-db.svg)](https://packagist.org/packages/crossberry-in/nxmirror-db)

The PHP client for **[NxMirror DB (NxDB)](https://nxmirror.netlify.app/docs)** — the cloud SQL
database + hybrid file storage service — a faithful port of the official
[`nxmirror-db`](https://www.npmjs.com/package/nxmirror-db) JavaScript/TypeScript SDK.

> தமிழ் வழிகாட்டி: **[README.ta.md](README.ta.md)**

Zero runtime dependencies beyond PHP's `ext-json` and `ext-curl`. PSR-4
(`NxDb\`, `src/`), no framework coupling — drop it into plain PHP, WordPress,
Laravel, Symfony or a CLI script.

Every example is standalone, heavily commented, prints what it does, and cleans
up after itself.

| # | Example | What it shows |
| --- | --- | --- |
| 00 | [`examples/00_setup_database.php`](examples/00_setup_database.php) | Create a demo account + one `sqlite` + one `nosql` database, write `.env.local` (`--cleanup` deletes them again) |
| 01 | [`examples/01_connect.php`](examples/01_connect.php) | Connect, `connectInfo()`, `details()`, first query |
| 02 | [`examples/02_sql_crud.php`](examples/02_sql_crud.php) | Full SQL: DDL, prepared INSERT, SELECT, JOIN, transaction, query builder |
| 03 | [`examples/03_nosql_crud.php`](examples/03_nosql_crud.php) | NoSQL documents: insert/find/get/update/replace/delete/count/list/drop |
| 04 | [`examples/04_file_storage.php`](examples/04_file_storage.php) | File storage: upload, download, folders, search, public + signed URLs, versions |
| 05 | [`examples/05_error_handling.php`](examples/05_error_handling.php) | `NxDbError` codes, the `wrong_engine` client-side guard, catch patterns |

## Requirements

- PHP **8.1+** (CLI, FPM, mod_php — anything)
- PHP extensions `ext-json` and `ext-curl` (both enabled by default in most distributions)
- An NxMirror account: <https://nxmirror.netlify.app> (free)
- One **SQL** database (engine `sqlite`) and optionally one **NoSQL** database (engine `nosql`)

## Install

Using Composer in your own project:

```bash
composer require crossberry-in/nxmirror-db
```

Or straight from a git checkout:

```bash
git clone https://github.com/crossberry-in/nxmirror-db-php.git
cd nxmirror-db-php
composer install    # only needed to run the bundled examples
```

No Composer in the project? The SDK has no dependencies, so loading `src/`
directly works too:

```php
foreach (glob(__DIR__ . '/src/*.php') as $file) {
    require_once $file;
}
```

## Quick start (2 commands)

```bash
composer run setup   # creates a demo account + 2 databases, writes .env.local
composer run demo    # runs examples 01 → 05 in order
```

Or without Composer:

```bash
php examples/00_setup_database.php
php examples/01_connect.php
```

Then, when you are done experimenting:

```bash
composer run setup:cleanup   # deletes both demo databases
```

Prefer your own database? Create one in the dashboard
(<https://nxmirror.netlify.app/dashboard/database>), copy `.env.example` to
`.env.local`, and fill in `NXDB_ID` + `NXDB_PASSWORD` (plus the `NXDB_NOSQL_*`
pair for the NoSQL example).

## Why not mysqli / PDO?

NxMirror DB is a **hosted, multi-tenant cloud database**, not a MySQL server on
a socket — so there is no `mysqli://` or `sqlite://` DSN to connect to:

| | mysqli / PDO | nxmirror-db (PHP SDK) |
| --- | --- | --- |
| Transport | TCP socket to a server you operate | HTTPS + JSON (`https://nxmirror.netlify.app/api`) |
| Works from | servers that can open DB sockets | shared hosting, containers, serverless, LANs without DB ports |
| Hosting | you patch, back up and scale the server | fully managed; isolated database per ID |
| Auth | one DB user + password | passwords, scoped tokens, origin-restricted app credentials |
| Extras | none | hybrid file storage, resumable uploads, signed URLs, change feed |

All calls go over the same HTTP API the dashboard uses — one port (443), no
persistent connection to babysit, no socket errors when the network blips.

## Connecting — the complete picture

### Connect options

```php
use NxDb\NxDB;

$db = NxDB::connect([
    'databaseId' => 'nxdb-…',    // required
    'password'   => '…',         // full access — trusted servers only
    // 'token'    => 'nxdb_…',   // scoped API token (read/write/sql/schema/files/custom:*)
    // 'appId' + 'appToken',      // origin-restricted app credentials
    'apiBase'    => 'https://nxmirror.netlify.app/api', // override for self-hosted gateways
    'timeoutMs'  => 30000,       // per-request timeout
    'retries'    => 2,           // safe retries for idempotent calls (clamped 0..5)
]);
```

`NxDB::connect()` verifies the connection once — advisory only: the failure is
not thrown, the next real call surfaces it. Prefer the explicit form when you
want the error immediately:

```php
$db = new NxDb\NxDatabase(['databaseId' => 'nxdb-…', 'password' => '…']);
$info = $db->connectInfo(); // throws NxDbError on bad credentials
```

snake_case aliases (`database_id`, `app_id`, `app_token`, `api_base`,
`timeout_ms`) are accepted everywhere.

| Method | Options | Access | Use where |
| --- | --- | --- | --- |
| Database password | `password` | full access to that database | trusted servers only |
| Scoped API token | `token` | permission set + optional expiry | servers & scripts |
| Application credentials | `appId` + `appToken` | permission set + allowed-origin restriction | browsers (via your backend) |

Tokens and app secrets are created in the dashboard (`/dashboard/database`) or
the account API and returned exactly once. Cross-database access fails with 403
`wrong_database`; cross-account access returns 404 (existence hidden).

### Introspection

```php
$info = $db->connectInfo(); // { connected, latencyMs, permissions, authMethod, database, sdk: { version, apiBase } }
$det  = $db->details();     // { database, tables[], caller }

$db->engine;      // "sqlite" | "nosql" — learned from connectInfo()
$db->permissions; // e.g. ["read", "write"]
```

## SQL (engine `sqlite`)

```php
// Parameterized statements — the ONLY safe way to pass user input
$res = $db->query(
    'INSERT INTO users (username, email) VALUES (?, ?)',
    ['noxs', 'dev@example.com']
);
$res['rows'];            // array of row objects
$res['changes'];         // rows changed
$res['lastInsertRowid']; // rowid of the last INSERT
$res['executionMs']; $res['truncated']; $res['columns'];

// Multi-statement DDL / transactions (no parameters in exec())
$db->exec('
  CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY, body TEXT);
  CREATE INDEX IF NOT EXISTS notes_body ON notes(body);
');
$db->exec('BEGIN; UPDATE … ; INSERT … ; COMMIT;');
```

Engine limits: 200 parameters per statement, 64 KB SQL, 1000 rows returned,
`ATTACH`/`DETACH` rejected.

### Query builder

```php
$db->table('users')->insert(['username' => 'developer']);
$db->table('users')->insert([['username' => 'a'], ['username' => 'b']]); // batch

$rows = $db->table('users')
    ->where('score', '>', 10)     // eq ne gt lt gte lte like in
    ->where(['active' => true])   // object form, AND-combined
    ->whereNull('deleted_at')
    ->orderBy('id', 'DESC')
    ->limit(20)->offset(40)
    ->select();                   // executes — limit() BEFORE select()
$rows['rows'];                    // select() returns the full result array — data lives in ['rows']

$total = $db->table('users')->where(['active' => true])->count();

$db->table('users')->update(['username' => 'noxs'], ['id' => 1]); // SET, WHERE
$db->table('users')->delete(['id' => 1]);                        // refuses to run without a WHERE
```

> `select()` returns the same shape as `query()`: `{ rows, columns, changes,
> executionMs, truncated, lastInsertRowid }` — the data lives in **`rows`**.

## NoSQL (engine `nosql`)

Create the database with **engine `nosql`** (dashboard or `POST /api/db/create`)
— same auth, tokens, storage and change feed as SQL, but a document API:

```php
$res = $db->nosql->insert('users', ['username' => 'noxs', 'score' => 9.5, 'profile' => ['city' => 'Chennai']]);
$res['lastInsertId'];                       // "d-…"; every doc gets _id/_createdAt/_updatedAt
$db->nosql->insert('users', [['…'], ['…']]); // batch (200 docs max)

$page = $db->nosql->find('users', [
    'filter' => ['profile.city' => 'Chennai'],       // dot-paths into nested objects
    'sort' => ['score' => -1],
    'limit' => 20, 'offset' => 0,
    'projection' => ['username', 'score'],
]);
$page['docs']; $page['total']; $page['truncated'];

// operators: $eq $ne $gt $gte $lt $lte $in $nin $exists $contains $startsWith + top-level $or
$db->nosql->find('users', ['filter' => ['$or' => [['tier' => 'gold'], ['score' => ['$gte' => 12]]]]]);

$db->nosql->get('users', $id);                                  // doc or null (404 → null)
$db->nosql->count('users', ['tier' => 'gold']);
$db->nosql->update('users', $id, ['score' => 10]);              // by id
$db->nosql->update('users', ['score' => ['$gte' => 12]], ['tier' => 'gold'], true); // many: true
$db->nosql->replace('users', $id, ['username' => 'noxs-v2']);   // keeps _id/_createdAt
$db->nosql->delete('users', ['score' => ['$lt' => 5]], true);
$db->nosql->list();                                             // collections
$db->nosql->drop('scratch');
```

Limits: 256 KB per document, 100 collections / database, 10,000 docs /
collection, 200 docs per batch. Multi-match update/delete **requires**
`many: true` — otherwise 409 `ambiguous_match`.

## File storage

```php
$file = $db->storage->upload([
    'path' => 'images/logo.png',   // folder/filename
    'file' => $pngBytes,           // string (text / base64 / data URL) | SplFileInfo | stream resource
    'mime' => 'image/png',         // optional (server sniffs) — explicit is safest
    'public' => true,              // anonymous GET URL without signature
    'onProgress' => function (array $p): void { echo "{$p['pct']}% ({$p['part']}/{$p['parts']})\n"; },
]);
$file['id']; $file['filename']; $file['mime']; $file['size']; $file['backend']; $file['checksum']; $file['versions'];

$db->storage->list(['folder' => 'images']);
$db->storage->from('images')->list();
$db->storage->search('logo');

$data = $db->storage->download($file['id']); // raw bytes as a PHP string

$db->storage->rename($file['id'], ['filename' => 'logo-v2.png']);
$db->storage->move($file['id'], 'archive');
$db->storage->copy($file['id'], ['folder' => 'backup']);
$db->storage->mkdir('exports/2026');
$db->storage->versions($file['id']);         // overwrites create new versions
$db->storage->signedUrl($file['id'], 900);   // private link, 5–60 min TTL
$db->storage->delete($file['id']);
```

Uploads above 3.4 MB automatically switch to resumable part uploads
(3 MB parts, up to 50 MB); `upload()` throws 413 `too_large` beyond that.
Public files are also reachable anonymously:
`https://nxmirror.netlify.app/api/db/storage/download/<fileId>?db=<databaseId>`

## Realtime (change polling)

```php
$feed = $db->changes('users', 0); // one-shot cursor read: { events, latestSeq }
foreach ($feed['events'] as $event) { /* seq, op, table, detail … */ }

// watch() keeps polling until unsubscribe() is called FROM INSIDE the callback.
// It is a BLOCKING loop — CLI scripts and workers only; web apps should poll
// changes() on their own schedule instead.
$handle = $db->watch('users', function (array $event): void {
    echo $event['seq'], ' ', $event['op'], ' ', $event['table'], "\n";
}, 5000, 0); // intervalMs, since
$handle->unsubscribe();
$handle->isStopped();
```

## Errors — catch by code

All SDK errors are `NxDbError` instances (extends `\Exception`) with a stable
string `errorCode` and, for server errors, an HTTP `status`. PHP's own
`$e->getCode()` is NOT the NxDB error code — always read `$e->errorCode`:

```php
use NxDb\NxDbError;

try {
    $db->query('SELECT * FROM nope');
} catch (NxDbError $e) {
    echo $e->errorCode, ' ', $e->status ?? 'null', ' ', $e->getMessage(), "\n";
}
```

Server codes below were verified against the live API (see
`05_error_handling.php`):

| `errorCode` | HTTP | Meaning |
| --- | --- | --- |
| `unauthorized` | 401 | missing credentials (no auth header) |
| `invalid_credentials` | 401 | wrong password/token for that database |
| `wrong_database` | 403 | credentials belong to another database |
| `insufficient_permission` | 403 | token lacks the required permission |
| `forbidden` | 403 | owner-only action from a non-owner |
| `confirm_required` | 400 | destructive action without `confirm` |
| `rate_limited` | 429 | retried automatically per `Retry-After` |
| `sql_error` | 400 | SQLite rejected the statement |
| `wrong_engine` | client-side | SQL vs NoSQL mismatch — thrown **client-side** (no `status`) when the engine is already known |
| `ambiguous_match` | 409 | NoSQL filter matched several docs without `many: true` |
| `invalid_collection` | 400 | collection name failed validation |
| `too_large` | 413 | file over the 50 MB resumable limit |
| `request_failed` | — | non-OK response with no structured body |
| `network_error` | — | cURL failed / timeout |
| `invalid_input` | — | bad SDK usage |

## Platform limits

| Limit | Value |
| --- | --- |
| Parameters per statement | 200 |
| SQL size | 64 KB |
| Rows returned | 1000 |
| Single-request upload | 3.5 MB (SDK switches to resumable at 3.4 MB) |
| Resumable upload | 50 MB · 64 parts · 3 MB parts |
| Signed URL TTL | 5–60 minutes |
| Documents | 256 KB · 100 collections · 10,000 docs · 200/batch |
| Backups | last 7 daily backups per database |

## Security best practices

1. **Never** ship the database password to a browser — use `appId` + `appToken`
   (origin-restricted) or short-lived scoped tokens.
2. Give every client the least permission (`read` dashboards, `write`-without-
   `schema` workers).
3. Rotate tokens from the dashboard; credentials are stored hashed server-side.
4. Secrets only ever appear in NxDB auth headers — keep them out of logs.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Parse error on `public readonly` properties | you are on PHP < 8.1 — the SDK needs 8.1+ for readonly properties |
| `Call to undefined function curl_init()` | `ext-curl` missing — `apt install php-curl` / enable `extension=curl` in php.ini |
| `Call to undefined function json_encode()` | `ext-json` missing (rare on modern PHP) |
| `wrong_engine` on `$db->query()` | database was created with engine `nosql` — SQL calls need a `sqlite` database |
| `wrong_engine` on `$db->nosql->*` | database was created with engine `sqlite` — document calls need a `nosql` database |
| `wrong_database` (403) | credentials are for another database — check `NXDB_ID` |
| `ambiguous_match` (409) | add `many: true` to the NoSQL update/delete |
| `Network error contacting NxDB: Operation timed out` (cURL 28) | slow network or gateway — raise `timeoutMs` (default 30000) |
| 429 | the SDK already honors `Retry-After`; reduce request frequency |
| `watch()` never returns | it is a blocking loop by design — call `$handle->unsubscribe()` from the callback; web apps should use `changes()` |

## Project structure

```
nxmirror-db-php/
├── composer.json               # package metadata + run scripts (composer run …)
├── src/
│   ├── NxDbError.php           # SDK error: string errorCode + HTTP status
│   ├── NxDatabase.php          # client: cURL transport, SQL, changes feed, engine guard
│   ├── NxQueryBuilder.php      # fluent where/orderBy/limit builder
│   ├── NxNoSql.php             # document collections API (engine "nosql")
│   ├── NxStorage.php           # hybrid file storage + resumable uploads
│   ├── NxWatchHandle.php       # unsubscribe() handle for watch()
│   └── NxDB.php                # NxDB::connect() facade
├── examples/
│   ├── config.php              # shared .env loader + credential helpers
│   ├── 00_setup_database.php   # provision demo account + 2 databases
│   ├── 01_connect.php          # connect + introspection
│   ├── 02_sql_crud.php         # SQL + query builder + transactions
│   ├── 03_nosql_crud.php       # NoSQL document API
│   ├── 04_file_storage.php     # upload/download/URLs/versions
│   └── 05_error_handling.php   # NxDbError codes + wrong_engine guard
├── scripts/
│   └── check_syntax.py         # tree-sitter PHP syntax check (no PHP CLI needed)
├── .env.example                # credential template
├── README.ta.md                # Tamil quick-start guide
└── LICENSE                     # MIT
```

## Links

- Platform: <https://nxmirror.netlify.app>
- Platform docs: <https://nxmirror.netlify.app/docs>
- SDK on Packagist: <https://packagist.org/packages/crossberry-in/nxmirror-db>
- JS SDK examples repo: <https://github.com/crossberry-in/db-example>
- SDK source: <https://github.com/crossberry-in/Demoapi> (`packages/nxmirror-db`)
- Tamil guide: [README.ta.md](README.ta.md)

## License

MIT — see [LICENSE](LICENSE).
