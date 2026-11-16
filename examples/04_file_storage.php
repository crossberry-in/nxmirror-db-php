<?php

// 04 — Hybrid file storage: upload, download, folders, URLs, versions
//
//   php examples/04_file_storage.php     (or: composer run storage)
//
// Small files are stored as SQL BLOBs, larger ones in object storage — the
// SDK picks the backend automatically and switches to resumable part uploads
// above 3.4 MB (up to 50 MB). Overwrites create new versions; every file gets
// a SHA-256 checksum.
//
// This example generates its own PNG in-memory (pack() + gzcompress — both
// core PHP), so it needs no image extensions or downloads.
require __DIR__ . '/config.php';

use NxDb\NxDB;

/* ---------- tiny self-contained PNG generator ---------- */
function nxdb_png_crc32(string $data): int
{
    static $table = null;
    if ($table === null) {
        $table = [];
        for ($n = 0; $n < 256; $n++) {
            $c = $n;
            for ($k = 0; $k < 8; $k++) {
                $c = ($c & 1) ? (0xedb88320 ^ ($c >> 1)) : ($c >> 1);
            }
            $table[$n] = $c;
        }
    }
    $crc = 0xffffffff;
    $length = strlen($data);
    for ($i = 0; $i < $length; $i++) {
        $crc = $table[($crc ^ ord($data[$i])) & 0xff] ^ ($crc >> 8);
    }
    return ($crc ^ 0xffffffff) & 0xffffffff;
}

function nxdb_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', nxdb_png_crc32($type . $data));
}

function nxdb_make_png(int $width, int $height, array $rgb): string
{
    [$r, $g, $b] = $rgb;
    // IHDR: width, height, bit depth 8, color type 2 (truecolor RGB)
    $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x02\x00\x00\x00";
    $row = "\x00"; // one filter byte (0) per row
    for ($x = 0; $x < $width; $x++) {
        $row .= pack('C3', $r, $g, $b);
    }
    $raw = str_repeat($row, $height);
    return "\x89PNG\r\n\x1a\n"
        . nxdb_png_chunk('IHDR', $ihdr)
        . nxdb_png_chunk('IDAT', gzcompress($raw))
        . nxdb_png_chunk('IEND', '');
}

function nxdb_fetch_headers(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['status' => $status, 'contentType' => $type];
}

$db = NxDB::connect(nxdb_sql_creds());
$png = nxdb_make_png(64, 64, [245, 130, 32]); // NxMirror orange square
echo 'generated png: ', strlen($png), " bytes\n";

/* ---------- 1. folders + upload ---------- */
nxdb_banner('storage.mkdir + storage.upload');
try {
    $db->storage->mkdir('images'); // already exists → fine
} catch (Throwable) {
}

$file = $db->storage->upload([
    'path' => 'images/nxdb-square.png',
    'file' => $png,          // string (text / base64 / data URL) | SplFileInfo | stream resource
    'mime' => 'image/png',   // optional — server sniffs it; explicit is safest
    'public' => true,        // anonymous GET URL without a signature
    // 'onProgress' => static function (array $p): void {
    //     echo "{$p['pct']}% ({$p['part']}/{$p['parts']})\n";  // resumable uploads > 3.4 MB
    // },
]);
echo 'uploaded file record: ', json_encode([
    'id' => $file['id'] ?? null,
    'filename' => $file['filename'] ?? null,
    'mime' => $file['mime'] ?? null,
    'size' => $file['size'] ?? null,
    'backend' => $file['backend'] ?? null,   // blob/sql — chosen automatically
    'folder' => $file['folder'] ?? null,
    'checksum' => isset($file['checksum']) ? substr((string) $file['checksum'], 0, 16) . '…' : null,
    'public' => $file['public'] ?? null,
    'versions' => $file['versions'] ?? null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

/* ---------- 2. download back and byte-compare ---------- */
nxdb_banner('storage.download()');
$back = $db->storage->download($file['id']);
echo 'byte-identical: ', $back === $png ? 'true' : 'false', ' (', strlen($back), " bytes)\n";

/* ---------- 3. list / folder views / search ---------- */
nxdb_banner('storage.list / from / search');
$all = $db->storage->list();
echo 'total files: ', count($all), "\n";
echo 'in images/: ', implode(', ', array_map(
    static fn ($f) => $f['filename'] ?? '',
    $db->storage->from('images')->list()
)), "\n";

// a plain text upload via string content
$txt = $db->storage->upload([
    'path' => 'notes/hello.txt',
    'file' => 'Hello from the nxmirror-db PHP SDK!',
    'mime' => 'text/plain',
]);
echo "search('square'): ", implode(', ', array_map(
    static fn ($f) => $f['filename'] ?? '',
    $db->storage->search('square')
)), "\n";

/* ---------- 4. public URL (no auth) + signed URL (private files) ---------- */
nxdb_banner('URLs');
$publicUrl = nxdb_api_base() . '/api/db/storage/download/' . rawurlencode($file['id'])
    . '?db=' . rawurlencode($db->databaseId);
$head = nxdb_fetch_headers($publicUrl);
echo 'public URL : ', $publicUrl, "\n";
echo '  status: ', $head['status'], ' | content-type: ', $head['contentType'], "\n";
// private files: $url = $db->storage->signedUrl($fileId, 900); // 5–60 min TTL
$signed = $db->storage->signedUrl($file['id'], 900);
echo 'signed URL : ', substr($signed, 0, 72), "…\n";

/* ---------- 5. rename / copy / versions / delete ---------- */
nxdb_banner('rename / copy / versions / delete');
$renamed = $db->storage->rename($file['id'], ['filename' => 'nxdb-square-v2.png']);
echo 'renamed to: ', $renamed['filename'] ?? 'nxdb-square-v2.png', "\n";

$copy = $db->storage->copy($file['id'], ['folder' => 'backup']);
echo 'copied to backup/: ', $copy['filename'] ?? '', ' (id ', $copy['id'] ?? '', ")\n";

$overwritten = $db->storage->upload([
    'path' => 'images/nxdb-square-v2.png',
    'file' => nxdb_make_png(64, 64, [32, 130, 245]), // same path, new content → new version
    'mime' => 'image/png',
    'public' => true,
]);
$hist = $db->storage->versions($overwritten['id']); // ['current' => ..., 'versions' => [...]]
echo 'current version: ', $hist['current'], ' | history entries: ', count($hist['versions']), "\n";

$db->storage->delete($copy['id']);
echo "backup copy deleted\n";

$db->storage->delete($txt['id']);
$db->storage->delete($overwritten['id']);
echo "\nOK — storage demo finished (demo files removed).\n";
