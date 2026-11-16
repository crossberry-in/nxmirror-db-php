<?php

/**
 * NxMirror DB — official PHP SDK: hybrid file storage.
 *
 * Small files are stored as SQL BLOBs, larger ones in object storage — the
 * backend is chosen automatically. Uploads above 3.4 MB switch to resumable
 * part uploads (3 MB parts, 50 MB hard cap); overwrites create new versions;
 * every file gets a SHA-256 checksum.
 *
 * Part of the crossberry-in/nxmirror-db package (https://nxmirror.netlify.app).
 */

declare(strict_types=1);

namespace NxDb;

final class NxStorage
{
    private NxDatabase $client;

    public function __construct(NxDatabase $client)
    {
        $this->client = $client;
    }

    /**
     * Choose a folder scope: $db->storage->from('images')->list().
     *
     * @return object An anonymous scope object exposing list(array $query = []).
     */
    public function from(string $folder): object
    {
        $client = $this->client;
        return new class ($client, $folder) {
            private NxDatabase $client;
            private string $folder;

            public function __construct(NxDatabase $client, string $folder)
            {
                $this->client = $client;
                $this->folder = $folder;
            }

            /** List files in the scoped folder (extra query keys pass through). */
            public function list(array $query = []): array
            {
                $query['folder'] = $this->folder;
                return $this->client->storage->list($query);
            }
        };
    }

    /**
     * List files.
     *
     * @param array<string,mixed> $options { folder?, q? }
     * @return array<int,array<string,mixed>>
     */
    public function list(array $options = []): array
    {
        $params = ['db' => $this->client->databaseId];
        $folder = isset($options['folder']) && is_scalar($options['folder']) ? (string) $options['folder'] : '';
        if ($folder !== '') {
            $params['folder'] = $folder;
        }
        $q = isset($options['q']) && is_scalar($options['q']) ? (string) $options['q'] : '';
        if ($q !== '') {
            $params['q'] = $q;
        }
        $result = $this->client->sendRequest('storage/list?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        return isset($result['files']) && is_array($result['files']) ? $result['files'] : [];
    }

    /** Search files by name fragment. */
    public function search(string $q): array
    {
        return $this->list(['q' => $q]);
    }

    /**
     * Upload a file. Automatically picks the storage backend (SQL BLOB for
     * small files, object storage for large ones) and switches to resumable
     * part uploads for large payloads.
     *
     * @param array<string,mixed> $options {
     *     @var string                        $path       e.g. "images/logo.png"
     *     @var mixed                         $file       string (data URL, base64 or plain text),
     *                                                    SplFileInfo or stream resource — see toBytes()
     *     @var string|null                   $mime       e.g. "image/png" (server sniffs when omitted)
     *     @var bool|null                     $public     anonymous GET URL without a signature
     *     @var callable|null                 $onProgress fn(array{loaded,total,pct,part?,parts?}): void
     * }
     * @return array<string,mixed> The uploaded file record
     *         { id, filename, mime, size, backend, folder, checksum, public, versions, createdAt }.
     */
    public function upload(array $options): array
    {
        $path = isset($options['path']) && is_scalar($options['path']) ? trim((string) $options['path']) : '';
        if ($path === '') {
            throw new NxDbError("upload() needs a path like 'images/logo.png'.", 'invalid_input');
        }
        $parts = array_values(array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn ($part) => $part !== ''
        ));
        $filename = array_pop($parts);
        $folder = implode('/', $parts);
        $bytes = self::toBytes($options['file'] ?? null);
        $size = strlen($bytes);
        $mime = isset($options['mime']) && is_scalar($options['mime']) ? (string) $options['mime'] : null;
        $isPublic = array_key_exists('public', $options) && $options['public'] !== null
            ? (bool) $options['public']
            : null;
        $onProgress = $options['onProgress'] ?? null;

        if ($size > NxDatabase::SINGLE_UPLOAD_MAX) {
            if ($size > NxDatabase::RESUMABLE_MAX_TOTAL) {
                throw new NxDbError(
                    'Files are limited to ' . intdiv(NxDatabase::RESUMABLE_MAX_TOTAL, 1048576) . ' MB.',
                    'too_large',
                    413
                );
            }

            // (a) init — server decides the real part layout
            $initBody = [
                'databaseId' => $this->client->databaseId,
                'folder' => $folder,
                'size' => $size,
                'partSize' => NxDatabase::RESUMABLE_PART_SIZE,
            ];
            if ($filename !== null && $filename !== '') {
                $initBody['filename'] = $filename;
            }
            if ($mime !== null) {
                $initBody['mime'] = $mime;
            }
            $init = $this->client->sendRequest('storage/upload/init', 'POST', $initBody);
            $uploadId = isset($init['uploadId']) && is_string($init['uploadId']) ? $init['uploadId'] : '';
            $partCount = isset($init['partCount']) ? (int) $init['partCount'] : 0;

            // (b) one PUT per part
            for ($index = 0; $index < $partCount; $index++) {
                $chunk = substr($bytes, $index * NxDatabase::RESUMABLE_PART_SIZE, NxDatabase::RESUMABLE_PART_SIZE);
                $this->client->sendRequest('storage/upload/part', 'PUT', [
                    'databaseId' => $this->client->databaseId,
                    'uploadId' => $uploadId,
                    'index' => $index,
                    'content' => base64_encode($chunk),
                ]);
                if (is_callable($onProgress)) {
                    $onProgress([
                        'loaded' => min($size, ($index + 1) * NxDatabase::RESUMABLE_PART_SIZE),
                        'total' => $size,
                        'pct' => min(99, (int) round((($index + 1) / $partCount) * 99)),
                        'part' => $index + 1,
                        'parts' => $partCount,
                    ]);
                }
            }

            // (c) complete
            $completeBody = ['databaseId' => $this->client->databaseId, 'uploadId' => $uploadId];
            if ($isPublic !== null) {
                $completeBody['public'] = $isPublic;
            }
            $completed = $this->client->sendRequest('storage/upload/complete', 'POST', $completeBody);
            if (is_callable($onProgress)) {
                $onProgress(['loaded' => $size, 'total' => $size, 'pct' => 100]);
            }
            return isset($completed['file']) && is_array($completed['file']) ? $completed['file'] : [];
        }

        $body = ['databaseId' => $this->client->databaseId, 'folder' => $folder];
        if ($filename !== null && $filename !== '') {
            $body['filename'] = $filename;
        }
        if ($mime !== null) {
            $body['mime'] = $mime;
        }
        $body['content'] = base64_encode($bytes);
        if ($isPublic !== null) {
            $body['public'] = $isPublic;
        }
        $result = $this->client->sendRequest('storage/upload', 'POST', $body);
        if (is_callable($onProgress)) {
            $onProgress(['loaded' => $size, 'total' => $size, 'pct' => 100]);
        }
        return isset($result['file']) && is_array($result['file']) ? $result['file'] : [];
    }

    /**
     * Download a file — returns the raw bytes as a PHP string.
     */
    public function download(string $fileId): string
    {
        return (string) $this->client->sendRequest(
            'storage/download/' . rawurlencode($fileId) . '?db=' . rawurlencode($this->client->databaseId),
            'GET',
            null,
            true
        );
    }

    /**
     * Short-lived signed URL for private files (default 15 minutes, 5–60 min TTL).
     */
    public function signedUrl(string $fileId, int $ttlSeconds = 900): string
    {
        $result = $this->client->sendRequest('storage/signed', 'POST', [
            'databaseId' => $this->client->databaseId,
            'id' => $fileId,
            'ttlSeconds' => $ttlSeconds,
        ]);
        $url = isset($result['url']) && is_string($result['url']) ? $result['url'] : '';
        // The platform returns a root-relative URL — re-attach the plain base
        // (with the "/api" suffix stripped).
        $base = (string) preg_replace('/\/api$/', '', $this->client->getApiBase());
        return $base . $url;
    }

    /**
     * Rename and/or move a file.
     *
     * @param array{filename?: string, folder?: string} $options
     * @return array<string,mixed>
     */
    public function rename(string $fileId, array $options = []): array
    {
        $body = ['databaseId' => $this->client->databaseId, 'id' => $fileId];
        if (isset($options['filename']) && is_scalar($options['filename'])) {
            $body['filename'] = (string) $options['filename'];
        }
        if (isset($options['folder']) && is_scalar($options['folder'])) {
            $body['folder'] = (string) $options['folder'];
        }
        $result = $this->client->sendRequest('storage/update', 'POST', $body);
        return isset($result['file']) && is_array($result['file']) ? $result['file'] : [];
    }

    /** Move a file into another folder. */
    public function move(string $fileId, string $folder): array
    {
        return $this->rename($fileId, ['folder' => $folder]);
    }

    /**
     * Copy a file.
     *
     * @param array{filename?: string, folder?: string} $options
     * @return array<string,mixed>
     */
    public function copy(string $fileId, array $options = []): array
    {
        $body = ['databaseId' => $this->client->databaseId, 'id' => $fileId];
        if (isset($options['filename']) && is_scalar($options['filename'])) {
            $body['filename'] = (string) $options['filename'];
        }
        if (isset($options['folder']) && is_scalar($options['folder'])) {
            $body['folder'] = (string) $options['folder'];
        }
        $result = $this->client->sendRequest('storage/copy', 'POST', $body);
        return isset($result['file']) && is_array($result['file']) ? $result['file'] : [];
    }

    /** Create a folder. */
    public function mkdir(string $path): array
    {
        return $this->client->sendRequest('storage/folders', 'POST', [
            'databaseId' => $this->client->databaseId,
            'path' => $path,
        ]);
    }

    /** Delete a file — always returns true on success. */
    public function delete(string $fileId): bool
    {
        $this->client->sendRequest('storage/delete', 'POST', [
            'databaseId' => $this->client->databaseId,
            'id' => $fileId,
        ]);
        return true;
    }

    /**
     * Version history (overwrites create new versions).
     *
     * @return array{current: int|string|null, versions: array}
     */
    public function versions(string $fileId): array
    {
        $result = $this->client->sendRequest(
            'storage/versions/' . rawurlencode($fileId) . '?db=' . rawurlencode($this->client->databaseId)
        );
        return [
            'current' => $result['current'] ?? null,
            'versions' => isset($result['versions']) && is_array($result['versions']) ? $result['versions'] : [],
        ];
    }

    /**
     * Port of the JS SDK's toBytes(): normalize upload content to raw bytes.
     *
     * String handling matches the JS SDK exactly:
     *   - "data:<mime>;base64,…" data URLs → the base64 part is decoded;
     *   - pure base64 (whitespace allowed, length % 4 === 0) → decoded;
     *   - anything else → treated as plain text (PHP strings are already
     *     byte sequences, so UTF-8 text uploads work unchanged).
     *
     * On top of the JS behavior, SplFileInfo objects (file paths) and stream
     * resources are accepted and read into bytes.
     */
    public static function toBytes(mixed $input): string
    {
        if ($input === null || $input === '' || $input === false) {
            throw new NxDbError('No file content provided for upload.', 'invalid_input');
        }
        if (is_string($input)) {
            // data URL → strip the "data:<mime>;base64," prefix
            if (preg_match('/^data:[^;]+;base64,/', $input)) {
                $input = substr($input, (int) strpos($input, ',') + 1);
            }
            // pure base64 (optionally whitespace-wrapped) → decode
            $stripped = preg_replace('/\s+/', '', $input);
            if (is_string($stripped) && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $stripped) && strlen($stripped) % 4 === 0) {
                $decoded = base64_decode($stripped, true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }
            return $input; // plain text upload
        }
        if ($input instanceof \SplFileInfo) {
            $contents = @file_get_contents($input->getPathname());
            if ($contents === false) {
                throw new NxDbError('Could not read the file passed to upload(): ' . $input->getPathname(), 'invalid_input');
            }
            return $contents;
        }
        if (is_resource($input)) {
            $contents = stream_get_contents($input);
            if ($contents === false) {
                throw new NxDbError('Could not read the stream passed to upload().', 'invalid_input');
            }
            return $contents;
        }
        throw new NxDbError(
            'Unsupported file content type. Pass a string (data URL, base64 or plain text), an SplFileInfo or a stream resource.',
            'invalid_input'
        );
    }
}
