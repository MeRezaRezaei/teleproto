# Quickstart — 5 Recipes

Copy-paste recipes against real Teleproto APIs. Every snippet assumes `use MeRezaRezaei\Teleproto\Facades\TP;` unless shown.

---

## (a) Send a message as a Bot (HTTP Bot API)

**When:** fire-and-forget notifications from any Laravel code — zero setup beyond the token.

```php
use MeRezaRezaei\Teleproto\Facades\TP;

TP::bot()->sendMessage(chatId: '@mychannel', text: 'Deploy finished ✅');

// custom token at runtime (multi-bot):
TP::bot('123456:ABC-DEF...')->sendMessage(chatId: 987654321, text: 'Direct ping');
```

**.env:** `TELEGRAM_BOT_TOKEN=...`

---

## (b) Log in as a User, then send via MTProto

**When:** act *as the user's own account* (channels, DMs, history) over native MTProto 2.0.

```bash
# 1. One-time wizard: phone → code → (2FA if set) → session string
php artisan teleproto:login --phone=+1234567890
#    ...confirm saving the session to .env as TELEGRAM_USER_SESSION
```

```php
// 2. Anywhere in Laravel — reads TELEGRAM_USER_SESSION + API creds from .env
$user = TP::user();

$user->sendMessage(peer: '@mychannel', text: 'Hello from my own account!');

// any raw schema method (Layer 227+):
$history = $user->call('messages.getHistory', [
    'peer' => ['_' => 'inputPeerChannel', 'channel_id' => 123456, 'access_hash' => 0],
    'limit' => 10,
]);
```

**.env:** `TELEGRAM_API_ID=`, `TELEGRAM_API_HASH=`, `TELEGRAM_USER_SESSION=` (wizard writes the last one for you). QR login: `php artisan teleproto:login --qr`.

---

## (c) Protect a Mini App route

**When:** serve your Telegram Mini App backend; reject forged requests before they reach you.

```php
use Illuminate\Support\Facades\Route;

// HMAC-SHA256-validated; user exposed as plain array:
Route::get('/miniapp/me', fn (\Illuminate\Http\Request $r) => [
    'hello' => $r->attributes->get('telegram_user')['first_name'],
])->middleware('tg.miniapp');
```

The middleware reads the `X-Telegram-Init-Data` header, verifies Telegram's HMAC signature, and stores the decoded user in the `telegram_user` request attribute. Invalid signature → 403.

**.env:** `TELEGRAM_BOT_TOKEN=` (the bot that owns the Mini App).

---

## (d) Decrypt Telegram Passport credentials (KYC)

**When:** a user submits identity documents via Passport to your bot.

```php
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

$cred = $update['message']['passport_data']['credentials'];

$decrypted = PassportDecryptor::decryptCredentials(
    encryptedData:   $cred['data'],     // base64
    encryptedSecret: $cred['secret'],   // RSA-encrypted to your public key
    privateKeyPem:   file_get_contents(storage_path('app/keys/passport_private.pem')),
    hash:            $cred['hash'],
);

$name = $decrypted['personal_details']['first_name']; // verified identity data
```

**.env:** none — needs the RSA keypair registered with @BotFather (see [telegram-passport.md](telegram-passport.md)).

---

## (e) Stream a Storage file to MTProto (upload parts)

**When:** upload files to Telegram straight from S3/MinIO/local disk — never fully in memory (512 KB parts, big-file path automatic > 10 MB).

```php
$user = TP::user();
$fileId = random_int(1, PHP_INT_MAX);
$md5 = hash_init('md5');
foreach (\MeRezaRezaei\Teleproto\Media\StorageMedia::readFromDisk('exports/report.pdf', disk: 's3') as $part) {
    hash_update($md5, $part['bytes']);
    $user->call($part['is_big'] ? 'upload.saveBigFilePart' : 'upload.saveFilePart', [
        'file_id' => $fileId, 'file_part' => $part['part_index'],
        'file_total_parts' => $part['total_parts'], 'bytes' => $part['bytes'],
    ]);
}
$file = ['_' => 'inputFileBig', 'id' => $fileId, 'parts' => $part['total_parts'], 'name' => 'report.pdf'];
if (!$part['is_big']) {
    $file = ['_' => 'inputFile', 'md5_checksum' => hash_final($md5)] + $file;
}
$user->sendMedia('@mychannel', [
    '_' => 'inputMediaUploadedDocument', 'file' => $file, 'mime_type' => 'application/pdf',
    'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'report.pdf']],
], 'Monthly report');
```

**.env:** `TELEGRAM_API_ID=`, `TELEGRAM_API_HASH=`, `TELEGRAM_USER_SESSION=`

---

Next: [index](index.md) · [Bot API](bot-client.md) · [User MTProto](user-client.md) · [Passport](telegram-passport.md) · [Scaling](scaling.md)
