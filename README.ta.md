# nxmirror-db — தமிழ் வழிகாட்டி (NxMirror DB PHP SDK)

NxMirror DB (NxDB) கிளவுட் தரவுத்தளத்தை அதிகாரப்பூர்வ PHP SDK
(`crossberry-in/nxmirror-db`) மூலம் இணைத்து பயன்படுத்துவதற்கான **முழுமையான,
நேரடியாக இயக்கக்கூடிய உதாரணங்கள்**. இது அதிகாரப்பூர்வ JavaScript SDK
(`nxmirror-db`)-ன் PHP ஆக்கம் (port) ஆகும்.

ஆங்கில முழு வழிகாட்டி: **[README.md](README.md)**

## 1. நிறுவுதல் (Install)

Composer உள்ள ப்ராஜெக்டில்:

```bash
composer require crossberry-in/nxmirror-db
```

அல்லது இந்த repo-வை clone செய்து உதாரணங்களை இயக்கவும்:

```bash
git clone https://github.com/crossberry-in/nxmirror-db-php.git
cd nxmirror-db-php
composer install
```

தேவைகள்: PHP 8.1+, `ext-json`, `ext-curl`. வேறு எந்த dependency-யும்
தேவையில்லை — பிரௌசர் socket-களைப் போல HTTPS மூலமே எல்லாத் தொடர்பும்
நடக்கும், எனவே shared hosting / serverless / containers எங்கும் வேலை
செய்யும்.

## 2. இரண்டு கட்டளைகளில் தொடங்குவது (Quick start)

```bash
composer run setup          # demo account + sqlite & nosql தரவுத்தளங்களை உருவாக்கி .env.local எழுதும்
composer run demo           # உதாரணங்கள் 01 → 05 வரிசையாக இயங்கும்
composer run setup:cleanup  # முடிந்ததும் demo தரவுத்தளங்களை நீக்கும்
```

Composer இல்லாமல்:

```bash
php examples/00_setup_database.php
php examples/01_connect.php
```

உங்கள் சொந்த தரவுத்தளம் ஏற்கனவே இருந்தால்: `.env.example`-ஐ `.env.local` என
நகலெடுத்து, dashboard-ல் கிடைக்கும் `NXDB_ID` மற்றும் `NXDB_PASSWORD`-ஐ
நிரப்பவும்.

## 3. இணைக்கும் முறை (Connect)

```php
<?php
require 'vendor/autoload.php';

use NxDb\NxDB;

$db = NxDB::connect([
    'databaseId' => getenv('NXDB_ID'),
    'password'   => getenv('NXDB_PASSWORD'),
]);

$rows = $db->query('SELECT * FROM users WHERE active = ?', [true]);
print_r($rows['rows']);
```

மாற்று வழிகள்: `['databaseId' => …, 'token' => …]` (scoped token) அல்லது
`['databaseId' => …, 'appId' => …, 'appToken' => …]` (origin-restricted app
credentials). `NxDB::connect()` இணைப்பை ஒருமுறை சோதிக்கும் — அந்தச் சோதனை
பிழை உடனே எறியப்படாது; அடுத்த உண்மையான அழைப்பில் வெளிப்படும். உடனே பிழை
வேண்டுமானால் `new NxDb\NxDatabase($options)` + `$db->connectInfo()`
பயன்படுத்துங்கள்.

## 4. SQL vs NoSQL — மிக முக்கியம்

| தரவுத்தள engine | பயன்படுத்துவது | உதாரணம் |
| --- | --- | --- |
| `sqlite` | `$db->query()`, `$db->exec()`, `$db->table()` | `02_sql_crud.php` |
| `nosql` | `$db->nosql->insert/find/get/update/…` | `03_nosql_crud.php` |

தவறான இரண்டை கலந்தால் SDK உடனேயே `wrong_engine` பிழையை தரும் (நெட்வொர்க்
அனுப்பாமலேயே) — இது JS SDK 1.1.1-ல் சேர்க்கப்பட்ட அதே client-side guard.
File storage (`$db->storage->*`) இரண்டு engine-லும் வேலை செய்யும்.

## 5. உதாரணங்களின் வரிசை

| கோப்பு | என்ன காட்டுகிறது |
| --- | --- |
| `00_setup_database.php` | demo account + இரண்டு தரவுத்தளங்கள் உருவாக்கம் (`--cleanup` நீக்கும்) |
| `01_connect.php` | இணைத்தல் + `connectInfo()` + `details()` + முதல் query |
| `02_sql_crud.php` | SQL: CREATE, INSERT, SELECT, JOIN, transaction, query builder |
| `03_nosql_crud.php` | NoSQL document API முழுவதும் |
| `04_file_storage.php` | கோப்பு upload/download, public URL, signed URL, versions |
| `05_error_handling.php` | பிழை கையாளுதல் + `NxDbError` codes + wrong_engine guard |

ஒவ்வொன்றையும் தனித்தனியே இயக்க:

```bash
composer run connect
composer run sql
composer run nosql
composer run storage
composer run errors
```

## 6. கோப்பு storage — சுருக்கம்

```php
$file = $db->storage->upload([
    'path'   => 'images/photo.jpg',
    'file'   => file_get_contents('photo.jpg'), // string (text/base64/data URL) | SplFileInfo | stream
    'mime'   => 'image/jpeg',
    'public' => true, // யாரும் பார்க்கக்கூடிய URL கிடைக்கும்
]);

echo 'file id: ', $file['id'], "\n";
// பொது URL: https://nxmirror.netlify.app/api/db/storage/download/<fileId>?db=<databaseId>

$back = $db->storage->download($file['id']);       // raw bytes (PHP string)
$url  = $db->storage->signedUrl($file['id'], 900); // private — 5–60 நிமிடம்
```

3.4 MB-க்கு மேல் உள்ள கோப்புகள் தானாகவே resumable part uploads-ஆக மாறும்
(3 MB parts, அதிகபட்சம் 50 MB). அதே path-ல் மீண்டும் upload செய்தால் புதிய
version உருவாகும் — `$db->storage->versions($fileId)`-ல் வரலாறு கிடைக்கும்.

## 7. பிழைகள் — errorCode வாங்கி கையாளுங்கள்

```php
use NxDb\NxDbError;

try {
    $db->query('SELECT * FROM nope');
} catch (NxDbError $e) {
    echo $e->errorCode, ' ', $e->status ?? 'null', ' ', $e->getMessage(), "\n";
    // unauthorized / wrong_database / wrong_engine / sql_error / ambiguous_match …
}
```

இரண்டு முக்கிய குறிப்புகள்:

- PHP-ன் சொந்த `$e->getCode()` எண் (int) — NxDB error code **`$e->errorCode`**
  (string) தான்.
- `wrong_engine` பிழை client-side-ல் தான் எறியப்படும் — அப்போது
  `$e->status === null` என்பதை வைத்து கண்டுபிடிக்கலாம் (நெட்வொர்க்
  request செல்லவே செல்லாது).

## 8. Realtime — changes() / watch()

```php
$feed = $db->changes('users', 0); // ஒரு முறை cursor read: { events, latestSeq }
foreach ($feed['events'] as $event) { /* seq, op, table … */ }

// watch() — blocking loop; CLI scripts / workers-க்கு மட்டும்:
$handle = $db->watch('users', function (array $event): void {
    echo $event['seq'], ' ', $event['op'], "\n";
}, 5000, 0); // intervalMs, since
// callback-ல் இருந்து $handle->unsubscribe() அழைத்தால் loop நிற்கும்.
```

Web apps (PHP-FPM request-கள்) `changes()` polling-ஐ பயன்படுத்த வேண்டும் —
`watch()` தற்போதைய process-ஐ block செய்யும்.

## 9. பாதுகாப்பு அடிப்படை விதிகள்

- Database password-ஐ **ஒருபோதும் பிரௌசரில்** வைக்காதீர்கள் — `appId` +
  `appToken` (origin-restricted) அல்லது குறுகிய கால scoped token
  பயன்படுத்துங்கள்.
- Token-களுக்கு தேவையான குறைந்த permission-ஐ மட்டும் கொடுங்கள் (read
  dashboards, schema இல்லாத write workers).
- Chat அல்லது code-ல் token ஒட்டினால் உடனே revoke செய்யவும்.
- Secrets-ஐ log-களில் எழுதாதீர்கள் — SDK auth headers-ல் மட்டும் அனுப்புகிறது.

## 10. இணைப்புகள்

- Platform: <https://nxmirror.netlify.app>
- Platform docs: <https://nxmirror.netlify.app/docs>
- Packagist: <https://packagist.org/packages/crossberry-in/nxmirror-db>
- JS SDK உதாரணங்கள்: <https://github.com/crossberry-in/db-example>
- முழு ஆங்கில வழிகாட்டி: [README.md](README.md)

## License

MIT — [LICENSE](LICENSE) பார்க்கவும்.
