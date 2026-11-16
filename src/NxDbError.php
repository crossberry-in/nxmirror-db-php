<?php

/**
 * NxMirror DB — official PHP SDK: the SDK error type.
 *
 * Every failure the SDK raises is an NxDbError with a stable string error
 * code (errorCode) and, for server errors, the HTTP status. PHP's built-in
 * \Exception already exposes an int-typed $code, so the string code lives in
 * the dedicated readonly $errorCode property instead — catch blocks should
 * always branch on ->errorCode, never on ->getCode().
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

class NxDbError extends \Exception
{
    /**
     * Stable machine-readable error code, e.g. "invalid_credentials",
     * "wrong_engine", "rate_limited", "invalid_input", "request_failed".
     */
    public readonly string $errorCode;

    /**
     * HTTP status of the failing response, or null when the error never
     * touched the wire (client-side guards like wrong_engine, network errors,
     * bad SDK usage).
     */
    public readonly ?int $status;

    public function __construct(string $message, string $errorCode = 'error', ?int $status = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }
}
