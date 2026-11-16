<?php

// Shared helper for every example — zero dependencies.
//
// Loads .env.local (created by 00_setup_database.php) or .env from the repo
// root, wires up the SDK classes (composer autoload when available, plain
// src/ requires otherwise) and exposes the small helpers used by all examples.

if (!function_exists('nxdb_bootstrap')) {
    /** Load environment files and make the SDK classes available. */
    function nxdb_bootstrap(): void
    {
        $root = dirname(__DIR__);

        // 1. environment — .env.local first, then .env; real env vars win over both
        foreach (['.env.local', '.env'] as $name) {
            $path = $root . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $m) && getenv($m[1]) === false) {
                    $value = preg_replace(['/^["\']/', '/["\']$/'], '', trim($m[2]));
                    putenv($m[1] . '=' . $value);
                }
            }
        }

        // 2. SDK classes — composer autoload if installed, otherwise src/ directly
        $autoload = $root . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            return;
        }
        foreach (['NxDbError', 'NxDatabase', 'NxQueryBuilder', 'NxNoSql', 'NxStorage', 'NxWatchHandle', 'NxDB'] as $class) {
            require_once $root . '/src/' . $class . '.php';
        }
    }
}

nxdb_bootstrap();

if (!function_exists('nxdb_api_base')) {
    /** Plain platform base (no /api suffix) — used for dashboard-level REST and public URLs. */
    function nxdb_api_base(): string
    {
        return getenv('NX_API_BASE') ?: 'https://nxmirror.netlify.app';
    }
}

if (!function_exists('nxdb_fail')) {
    function nxdb_fail(string $what): void
    {
        fwrite(STDERR, "\nMissing credentials: {$what}\n\nPick ONE way to provide them:\n\n"
            . "  1. Easy:   composer run setup\n"
            . "             (creates a demo account + 2 databases, writes .env.local)\n\n"
            . "  2. Manual: copy .env.example to .env.local and fill in your\n"
            . "             database ID + password from the NxMirror dashboard\n"
            . "             (https://nxmirror.netlify.app/dashboard/database)\n\n");
        exit(1);
    }
}

if (!function_exists('nxdb_sql_creds')) {
    /** Connection settings for the SQL (engine "sqlite") demo database. */
    function nxdb_sql_creds(): array
    {
        $databaseId = getenv('NXDB_ID') ?: '';
        $password = getenv('NXDB_PASSWORD') ?: '';
        if ($databaseId === '' || $password === '') {
            nxdb_fail('NXDB_ID and NXDB_PASSWORD');
        }
        return ['databaseId' => $databaseId, 'password' => $password, 'apiBase' => nxdb_api_base()];
    }
}

if (!function_exists('nxdb_nosql_creds')) {
    /** Connection settings for the NoSQL (engine "nosql") demo database. */
    function nxdb_nosql_creds(): array
    {
        $databaseId = getenv('NXDB_NOSQL_ID') ?: '';
        $password = getenv('NXDB_NOSQL_PASSWORD') ?: '';
        if ($databaseId === '' || $password === '') {
            nxdb_fail('NXDB_NOSQL_ID and NXDB_NOSQL_PASSWORD');
        }
        return ['databaseId' => $databaseId, 'password' => $password, 'apiBase' => nxdb_api_base()];
    }
}

if (!function_exists('nxdb_banner')) {
    /** Pretty banner so example output is easy to read. */
    function nxdb_banner(string $title): void
    {
        echo "\n== {$title} ==\n";
    }
}

if (!function_exists('nxdb_cell')) {
    /** Render one table cell value for nxdb_table(). */
    function nxdb_cell(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        return (string) $value;
    }
}

if (!function_exists('nxdb_table')) {
    /**
     * Minimal console.table() stand-in: prints rows as an ASCII table.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    function nxdb_table(array $rows): void
    {
        if ($rows === []) {
            echo "(no rows)\n";
            return;
        }
        $headers = array_keys((array) reset($rows));
        $lines = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $line = [];
            foreach ($headers as $header) {
                $line[$header] = nxdb_cell($row[$header] ?? null);
            }
            $lines[] = $line;
        }
        $widths = [];
        foreach ($headers as $header) {
            $width = strlen((string) $header);
            foreach ($lines as $line) {
                $width = max($width, strlen($line[$header]));
            }
            $widths[$header] = $width;
        }
        $border = '+-' . implode('-+-', array_map(static fn ($h) => str_repeat('-', $widths[$h]), $headers)) . '-+';
        echo $border, "\n";
        echo '| ' . implode(' | ', array_map(static fn ($h) => str_pad((string) $h, $widths[$h]), $headers)) . " |\n";
        echo $border, "\n";
        foreach ($lines as $line) {
            echo '| ' . implode(' | ', array_map(static fn ($h) => str_pad($line[$h], $widths[$h]), $headers)) . " |\n";
        }
        echo $border, "\n";
    }
}
