<?php

// 00 — Provision demo databases (run this FIRST, once)
//
//   php examples/00_setup_database.php            create account + SQL db + NoSQL db
//   php examples/00_setup_database.php --cleanup  delete both demo databases again
//
// What it does:
//   1. Registers a throwaway NxMirror account (or logs in again on re-runs)
//   2. Creates one database with engine "sqlite"  -> SQL + file storage examples
//   3. Creates one database with engine "nosql"   -> NoSQL document examples
//   4. Writes .env.local with everything the other examples need
//
// This script talks to the account REST API directly (the SDK connects to
// *existing* databases; creating accounts/databases is a dashboard-level job).

require __DIR__ . '/config.php';

if (!function_exists('nxdb_platform_json')) {
    /** Minimal JSON client for the account REST API (plain base, /api paths). */
    function nxdb_platform_json(string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $base = nxdb_api_base();
        $ch = curl_init($base . $path);
        $headers = ['content-type: application/json'];
        if ($token !== null && $token !== '') {
            $headers[] = 'authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }
}

$root = dirname(__DIR__);

/* ---------------- cleanup mode ---------------- */
if (in_array('--cleanup', $argv ?? [], true)) {
    $envPath = $root . '/.env.local';
    if (!is_file($envPath)) {
        echo "No .env.local found — nothing to clean up.\n";
        exit(0);
    }
    // config.php already loaded .env.local into getenv(); restore a session by
    // logging in again when NX_SESSION is missing or expired.
    $session = getenv('NX_SESSION') ?: '';
    if ($session === '' && getenv('NX_ACCOUNT') && getenv('NX_ACCOUNT_PASSWORD')) {
        $login = nxdb_platform_json('POST', '/api/auth/login', [
            'username' => getenv('NX_ACCOUNT'),
            'password' => getenv('NX_ACCOUNT_PASSWORD'),
        ]);
        $session = is_string($login['data']['token'] ?? null) ? $login['data']['token'] : '';
        echo $session !== '' ? "re-login OK\n" : "re-login failed ({$login['status']})\n";
    }
    if ($session === '') {
        echo "Could not restore a session — delete the two demo databases from the dashboard.\n";
        exit(1);
    }
    $failures = 0;
    $targets = [
        ['sql db  ', (string) getenv('NXDB_ID'), (string) getenv('NXDB_NAME')],
        ['nosql db', (string) getenv('NXDB_NOSQL_ID'), (string) getenv('NXDB_NOSQL_NAME')],
    ];
    foreach ($targets as [$label, $databaseId, $name]) {
        if ($databaseId === '') {
            continue;
        }
        $del = nxdb_platform_json('DELETE', '/api/db/delete', [
            'databaseId' => $databaseId,
            'confirm' => $name !== '' ? $name : $databaseId,
        ], $session);
        $ok = ($del['data']['success'] ?? null) === true;
        if (!$ok) {
            $failures++;
        }
        echo ($ok ? 'deleted' : 'FAILED') . " {$label} {$databaseId} (status {$del['status']})\n";
    }
    if ($failures === 0) {
        unlink($envPath);
    }
    echo $failures === 0
        ? "\nCleanup complete — .env.local removed.\n"
        : "\n{$failures} deletion(s) failed — .env.local kept.\n";
    exit($failures === 0 ? 0 : 1);
}

/* ---------------- setup mode ---------------- */
$ts = substr((string) (int) floor(microtime(true) * 1000), -8);
$account = 'dbex' . $ts;
$password = 'DbEx-' . $ts . '-x9!';
$sqlName = 'nxmirror-php sql ' . $ts;
$nosqlName = 'nxmirror-php nosql ' . $ts;

// 1. register (fall back to login if the name was taken by an earlier run)
$reg = nxdb_platform_json('POST', '/api/auth/register', ['username' => $account, 'password' => $password]);
$session = is_string($reg['data']['token'] ?? null) ? $reg['data']['token'] : '';
if ($session === '') {
    $login = nxdb_platform_json('POST', '/api/auth/login', ['username' => $account, 'password' => $password]);
    $session = is_string($login['data']['token'] ?? null) ? $login['data']['token'] : '';
    if ($session !== '') {
        echo "account {$account} already existed — logged in\n";
    }
}
if ($session === '') {
    fwrite(STDERR, 'register failed (' . $reg['status'] . '): '
        . ($reg['data']['error'] ?? $reg['data']['message'] ?? 'unknown error') . "\n");
    exit(1);
}
echo "account ready: {$account}\n";

// 2. create the two demo databases
$createDb = static function (string $name, string $engine) use ($session): array {
    $res = nxdb_platform_json('POST', '/api/db/create', [
        'name' => $name,
        'engine' => $engine,
        'description' => 'nxmirror-db PHP SDK demo database',
    ], $session);
    $database = is_array($res['data']['database'] ?? null) ? $res['data']['database'] : [];
    $databaseId = is_string($database['id'] ?? null) ? $database['id'] : '';
    $dbPassword = is_string($res['data']['credentials']['password'] ?? null)
        ? $res['data']['credentials']['password']
        : '';
    if ($databaseId === '' || $dbPassword === '') {
        fwrite(STDERR, 'create ' . $engine . ' failed (' . $res['status'] . '): '
            . ($res['data']['error'] ?? $res['data']['message'] ?? 'unknown error') . "\n");
        exit(1);
    }
    echo str_pad($engine, 6) . " database ready: {$databaseId}  (\"{$name}\")\n";
    return ['id' => $databaseId, 'password' => $dbPassword, 'name' => $name];
};

$sqlDb = $createDb($sqlName, 'sqlite');
$nosqlDb = $createDb($nosqlName, 'nosql');

// 3. write .env.local
$envLines = [
    'NX_SESSION=' . $session,
    'NX_ACCOUNT=' . $account,
    'NX_ACCOUNT_PASSWORD=' . $password,
    'NXDB_NAME=' . $sqlName,
    'NXDB_ID=' . $sqlDb['id'],
    'NXDB_PASSWORD=' . $sqlDb['password'],
    'NXDB_NOSQL_NAME=' . $nosqlName,
    'NXDB_NOSQL_ID=' . $nosqlDb['id'],
    'NXDB_NOSQL_PASSWORD=' . $nosqlDb['password'],
];
file_put_contents($root . '/.env.local', implode("\n", $envLines) . "\n");

echo <<<EOT

.env.local written. Now run any example:

  composer run connect   # 01 — connect + introspection
  composer run sql       # 02 — SQL + query builder
  composer run nosql     # 03 — NoSQL document collections
  composer run storage   # 04 — file storage upload/download
  composer run errors    # 05 — error handling + wrong_engine guard

Or directly: php examples/01_connect.php

When you are finished:  composer run setup:cleanup

EOT;
