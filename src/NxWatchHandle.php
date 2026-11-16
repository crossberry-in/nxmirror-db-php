<?php

/**
 * NxMirror DB — official PHP SDK: watch() control handle.
 *
 * NxDatabase::watch() is a blocking polling loop; call unsubscribe() on the
 * returned handle (typically from inside the watch callback) to end the loop.
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

final class NxWatchHandle
{
    private bool $stopped = false;

    /** Stop the watch loop. Safe to call multiple times. */
    public function unsubscribe(): void
    {
        $this->stopped = true;
    }

    /** True once unsubscribe() has been called and the loop has (or will) exit. */
    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
