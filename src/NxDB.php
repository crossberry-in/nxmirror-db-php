<?php

/**
 * NxMirror DB — official PHP SDK: connect facade.
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

final class NxDB
{
    /**
     * Connect to an NxDB database and verify the connection once (advisory).
     *
     * The verification failure is remembered, not thrown — the next real call
     * surfaces the auth error. Use `new NxDatabase($options)` plus an explicit
     * connectInfo() when you want the failure immediately.
     *
     * @param array<string,mixed> $options See NxDatabase::__construct().
     */
    public static function connect(array $options): NxDatabase
    {
        $client = new NxDatabase($options);
        try {
            $client->connectInfo();
        } catch (\Throwable) {
            // advisory only — later calls surface auth errors
        }
        return $client;
    }
}
