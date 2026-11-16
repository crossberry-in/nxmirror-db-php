<?php

/**
 * NxMirror DB — official PHP SDK: the main database client.
 *
 * A faithful PHP port of the JavaScript SDK's NxDatabase class. Talks to the
 * NxMirror DB platform over HTTPS (cURL transport) with automatic retries for
 * rate limits, transient network failures and 5xx responses, a client-side
 * engine guard, SQL execution, the query builder entry point, introspection
 * and the change feed.
 *
 * Quick start:
 *   use NxDb\NxDB;
 *   $db = NxDB::connect(['databaseId' => 'nxdb-…', 'password' => '…']);
 *   $rows = $db->query('SELECT * FROM users WHERE active = ?', [true]);
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

class NxDatabase
{
    /** SDK version, reported by connectInfo() under the "sdk" key. */
    public const SDK_VERSION = '1.0.0';

    /** Default API base URL — every endpoint lives under {apiBase}/db/. */
    public const DEFAULT_API_BASE = 'https://nxmirror.netlify.app/api';

    /** Single-request upload ceiling in bytes (server hard limit is 3.5 MB). */
    public const SINGLE_UPLOAD_MAX = 3565158; // 3.4 MB

    /** Part size for resumable uploads (3 MB, at most 64 parts per upload). */
    public const RESUMABLE_PART_SIZE = 3145728;

    /** Hard ceiling for resumable uploads (50 MB). */
    public const RESUMABLE_MAX_TOTAL = 52428800;

    /** NxDB database ID (nxdb-...). */
    public string $databaseId;

    /** Engine learned from connectInfo(): "sqlite" | "nosql" | null (unknown). */
    public ?string $engine = null;

    /** Permission list learned from connectInfo(), or null when unknown. */
    public ?array $permissions = null;

    /** Hybrid file storage API (works on both engines). */
    public NxStorage $storage;

    /** NoSQL document API (engine "nosql" databases only). */
    public NxNoSql $nosql;

    private string $apiBase;
    private int $timeoutMs;
    private int $retries;
    private ?string $password;
    private ?string $token;
    private ?string $appId;
    private ?string $appToken;

    /**
     * @param array<string,mixed> $options {
     *     Required: one authentication method plus the database ID.
     *
     *     @var string      $databaseId  NxDB database ID (nxdb-...) — alias: database_id
     *     @var string|null $password    database password (trusted environments only)
     *     @var string|null $token       scoped API token (nxdb_...)
     *     @var string|null $appId       registered application ID — alias: app_id
     *     @var string|null $appToken    application token (nxdbapp_...) — alias: app_token
     *     @var string|null $apiBase     API base URL; "/api" is appended when missing
     *     @var int|null    $timeoutMs   per-request timeout in ms (default 30000) — alias: timeout_ms
     *     @var int|null    $retries     safe retry limit for idempotent calls (default 2, clamped 0..5)
     * }
     */
    public function __construct(array $options = [])
    {
        $databaseId = self::strOption($options, ['databaseId', 'database_id']);
        if ($databaseId === null) {
            throw new NxDbError('NxDB::connect() requires a databaseId.', 'invalid_input');
        }

        $password = self::strOption($options, ['password']);
        $token = self::strOption($options, ['token']);
        $appId = self::strOption($options, ['appId', 'app_id']);
        $appToken = self::strOption($options, ['appToken', 'app_token']);

        if ($password === null && $token === null && ($appId === null || $appToken === null)) {
            throw new NxDbError('Provide a token, a password, or an appId + appToken.', 'invalid_input');
        }

        $this->databaseId = $databaseId;
        $this->apiBase = self::detectApiBase(self::strOption($options, ['apiBase', 'api_base']));
        $this->timeoutMs = max(1, self::intOption($options, ['timeoutMs', 'timeout_ms'], 30000));
        $this->retries = max(0, min(5, self::intOption($options, ['retries'], 2)));
        $this->password = $password;
        $this->token = $token;
        $this->appId = $appId;
        $this->appToken = $appToken;

        if ($password !== null && PHP_SAPI !== 'cli') {
            error_log('[NxDB] A database password is being used in a web request. '
                . 'Passwords grant full access — prefer a scoped token or an application token.');
        }

        $this->storage = new NxStorage($this);
        $this->nosql = new NxNoSql($this);
    }

    /* ---------------- helpers ---------------- */

    /**
     * Normalize the API base exactly like the JS SDK: trim trailing slashes,
     * append "/api" when it is missing.
     */
    public static function detectApiBase(?string $base = null): string
    {
        if ($base === null || $base === '') {
            $base = self::DEFAULT_API_BASE;
        }
        $trimmed = rtrim($base, '/');
        return str_ends_with($trimmed, '/api') ? $trimmed : $trimmed . '/api';
    }

    /** First non-empty string option among the given keys (JS "||" semantics). */
    private static function strOption(array $options, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && is_scalar($options[$key])) {
                $value = (string) $options[$key];
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /** First integer option among the given keys, or the default. */
    private static function intOption(array $options, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && is_scalar($options[$key])) {
                return (int) $options[$key];
            }
        }
        return $default;
    }

    /**
     * Client-side engine guard: block calls that cannot succeed on this engine
     * with a clear error. The engine is learned from connectInfo(); when it is
     * still unknown, the server decides. Throws a wrong_engine NxDbError
     * WITHOUT any network request.
     */
    public function assertEngine(string $expected): void
    {
        if ($this->engine === null || $this->engine === $expected) {
            return;
        }
        $hint = $expected === 'nosql'
            ? 'NoSQL document collections (db.nosql.*) need a database created with engine "nosql".'
            : 'SQL calls (db.query / db.exec / db.table) need the "sqlite" engine — JSON documents live on engine "nosql" via db.nosql.*';
        throw new NxDbError(
            'This database runs the "' . $this->engine . '" engine — this call requires "' . $expected . '". ' . $hint,
            'wrong_engine'
        );
    }

    /** API base actually used for requests ("/api" suffix normalized). */
    public function getApiBase(): string
    {
        return $this->apiBase;
    }

    /* ---------------- transport ---------------- */

    /**
     * Internal transport gateway for the composed NxStorage / NxNoSql helpers.
     * (PHP private methods are per-class, so the helper objects need one public
     * door — all retry rules still live in the single private request().)
     *
     * @internal Do not call directly — use the typed API methods instead.
     *
     * @param array<string,mixed>|null $body
     * @return mixed Decoded JSON object as an associative array, or the raw
     *               byte string when $blob is true.
     */
    public function sendRequest(string $path, string $method = 'GET', ?array $body = null, bool $blob = false)
    {
        return $this->request($path, $method, $body, $blob);
    }

    /**
     * The one transport method implementing every retry rule:
     *   - HTTP 429 → sleep Retry-After seconds, retry (up to retries, max 5);
     *   - network errors and HTTP 5xx on GET → backoff ~0.4s * (attempt + 1);
     *   - binary GET (download) 404/5xx → backoff ~0.6s * (attempt + 1).
     *
     * @param array<string,mixed>|null $body JSON body; Content-Type is sent
     *                                       only when a body is present.
     * @return mixed Associative array (JSON) or raw bytes ($blob).
     */
    private function request(string $path, string $method = 'GET', ?array $body = null, bool $blob = false, int $attempt = 0)
    {
        $url = $this->apiBase . '/db/' . $path;

        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => $this->authHeaders($body !== null),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
                return $length;
            },
        ]);
        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                curl_close($ch);
                throw new NxDbError('Request payload is not JSON-serializable: ' . json_last_error_msg(), 'invalid_input');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $reason = (string) curl_error($ch);
            curl_close($ch);
            if ($method === 'GET' && $attempt < $this->retries) {
                usleep(400000 * ($attempt + 1)); // ~0.4 s, growing per attempt
                return $this->request($path, $method, $body, $blob, $attempt + 1);
            }
            throw new NxDbError('Network error contacting NxDB: ' . $reason, 'network_error');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 429 — honor Retry-After, then retry.
        if ($status === 429) {
            $retryAfter = $responseHeaders['retry-after'] ?? null;
            $seconds = is_string($retryAfter) && is_numeric($retryAfter) ? (float) $retryAfter : 1.0;
            $seconds = max(0.0, $seconds);
            if ($attempt < $this->retries) {
                usleep((int) round($seconds * 1000000));
                return $this->request($path, $method, $body, $blob, $attempt + 1);
            }
            throw new NxDbError('NxDB rate limit exceeded. Slow down and retry shortly.', 'rate_limited', 429);
        }

        // 5xx on GET — small growing backoff.
        if ($status >= 500 && $method === 'GET' && $attempt < $this->retries) {
            usleep(400000 * ($attempt + 1));
            return $this->request($path, $method, $body, $blob, $attempt + 1);
        }

        if ($blob) {
            if ($status < 200 || $status >= 300) {
                // Netlify Blobs replicas can serve a brief stale window right
                // after a write — retry idempotent blob GETs on 404/5xx before
                // giving up.
                if ($method === 'GET' && ($status === 404 || $status >= 500) && $attempt < $this->retries) {
                    usleep(600000 * ($attempt + 1));
                    return $this->request($path, $method, $body, $blob, $attempt + 1);
                }
                throw new NxDbError(self::errorText((string) $response, $status), 'request_failed', $status);
            }
            return (string) $response;
        }

        $text = (string) $response;
        $data = [];
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } elseif (json_last_error() === JSON_ERROR_NONE) {
                $data = []; // valid JSON but not an object — generic handling below
            } else {
                $data = ['error' => substr($text, 0, 300)];
            }
        }
        if ($status < 200 || $status >= 300) {
            $message = isset($data['error']) && is_scalar($data['error'])
                ? (string) $data['error']
                : 'Request failed (' . $status . ')';
            $code = isset($data['code']) && is_string($data['code']) && $data['code'] !== ''
                ? $data['code']
                : 'request_failed';
            throw new NxDbError($message, $code, $status);
        }
        return $data;
    }

    /** Auth headers sent on every request, mirroring the JS SDK exactly. */
    private function authHeaders(bool $withJsonBody): array
    {
        $headers = ['x-nxdb-id: ' . $this->databaseId];
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($this->password !== null) {
            $headers[] = 'x-nxdb-password: ' . $this->password;
        }
        if ($this->appId !== null) {
            $headers[] = 'x-nxdb-app: ' . $this->appId;
            $headers[] = 'x-nxdb-app-token: ' . ($this->appToken ?? '');
        }
        if ($withJsonBody) {
            $headers[] = 'Content-Type: application/json';
        }
        return $headers;
    }

    /** Extract a readable error message from a failed blob/JSON response. */
    private static function errorText(string $raw, int $status): string
    {
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['error']) && is_scalar($decoded['error'])) {
                return substr((string) $decoded['error'], 0, 300);
            }
            return substr($raw, 0, 300);
        }
        return 'Request failed (' . $status . ')';
    }

    /* ---------------- SQL ---------------- */

    /**
     * Execute a parameterized SQL statement.
     *
     * @param array<int,mixed> $params
     * @return array{rows: array, columns: array, changes: int, executionMs: int,
     *               truncated: bool, lastInsertRowid: int|string|null}
     */
    public function query(string $sql, array $params = []): array
    {
        $this->assertEngine('sqlite');
        $result = $this->sendRequest('query', 'POST', [
            'databaseId' => $this->databaseId,
            'sql' => $sql,
            'params' => $params,
        ]);
        return [
            'rows' => isset($result['rows']) && is_array($result['rows']) ? $result['rows'] : [],
            'columns' => isset($result['columns']) && is_array($result['columns']) ? $result['columns'] : [],
            'changes' => (int) ($result['changes'] ?? 0),
            'executionMs' => (int) ($result['executionMs'] ?? 0),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'lastInsertRowid' => $result['lastInsertRowid'] ?? null,
        ];
    }

    /**
     * Execute a multi-statement SQL batch (no parameters).
     *
     * @return array{changes: int, executionMs: int}
     */
    public function exec(string $sql): array
    {
        $this->assertEngine('sqlite');
        $result = $this->sendRequest('query', 'POST', [
            'databaseId' => $this->databaseId,
            'sql' => $sql,
        ]);
        return [
            'changes' => (int) ($result['changes'] ?? 0),
            'executionMs' => (int) ($result['executionMs'] ?? 0),
        ];
    }

    /** Query builder entry point (table name must match ^\w+$). */
    public function table(string $name): NxQueryBuilder
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new NxDbError('Invalid table name.', 'invalid_input');
        }
        return new NxQueryBuilder($this, $name);
    }

    /* ---------------- connection ---------------- */

    /**
     * Verify connectivity + permissions, and remember the engine for the
     * client-side guard.
     *
     * @return array<string,mixed> { connected, latencyMs, permissions,
     *                               authMethod, database, sdk: { version, apiBase } }
     */
    public function connectInfo(): array
    {
        $info = $this->sendRequest('connect', 'POST', ['databaseId' => $this->databaseId]);
        $this->permissions = isset($info['permissions']) && is_array($info['permissions'])
            ? $info['permissions']
            : null;
        $this->engine = isset($info['database']['engine']) && is_string($info['database']['engine'])
            ? $info['database']['engine']
            : null;
        return array_merge($info, ['sdk' => ['version' => self::SDK_VERSION, 'apiBase' => $this->apiBase]]);
    }

    /** Database metadata (database, tables[], caller). */
    public function details(): array
    {
        return $this->sendRequest('details', 'POST', ['databaseId' => $this->databaseId]);
    }

    /* ---------------- realtime (change polling; WebSocket push is planned) ---------------- */

    /**
     * Fetch change events newer than a sequence number.
     *
     * @return array{events: array, latestSeq: int}
     */
    public function changes(?string $table = null, int $since = 0): array
    {
        $query = ['db' => $this->databaseId, 'since' => (string) $since];
        if ($table !== null && $table !== '') {
            $query['table'] = $table;
        }
        $result = $this->sendRequest('events?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        return [
            'events' => isset($result['events']) && is_array($result['events']) ? $result['events'] : [],
            'latestSeq' => isset($result['latestSeq']) ? (int) $result['latestSeq'] : 0,
        ];
    }

    /**
     * Poll for changes until unsubscribe() is called from inside the callback.
     *
     * IMPORTANT: this is a BLOCKING loop — it runs the current process until
     * the returned handle is unsubscribed. It is meant for CLI scripts and
     * long-running workers; web apps (PHP-FPM requests) should poll changes()
     * instead.
     *
     * @param callable(array<string,mixed>):mixed $callback Called with every
     *        event; on polling errors receives ['op' => 'error', 'error' => Throwable].
     */
    public function watch(?string $table, callable $callback, int $intervalMs = 5000, ?int $since = null): NxWatchHandle
    {
        $handle = new NxWatchHandle();
        $cursor = $since ?? 0;
        $intervalUs = max(1000, $intervalMs) * 1000;

        while (!$handle->isStopped()) {
            try {
                $result = $this->changes($table, $cursor);
                $cursor = max($cursor, (int) $result['latestSeq']);
                foreach ($result['events'] as $event) {
                    $callback($event);
                }
            } catch (\Throwable $error) {
                if (!$handle->isStopped()) {
                    $callback(['op' => 'error', 'error' => $error]);
                }
            }
            if ($handle->isStopped()) {
                break;
            }
            usleep($intervalUs);
        }
        return $handle;
    }
}
