# Teleproto

**Author:** MeRezaRezaei  
**License:** MIT  

A modern, unified **Telegram Multi-Protocol Engine for PHP & Laravel**.

Teleproto solves the fragmentation problem in PHP by giving developers a single, cohesive client that handles **MTProto 2.0 User Sessions**, **Bot API & MTProto Bots**, **Mini App Security Verification**, **Telegram Passport KYC Decryption**, and **Storage Streaming**.

---

## 📦 Installation

```bash
composer require merezarezaei/teleproto
```

Publish configuration:
```bash
php artisan vendor:publish --tag="teleproto-config"
```

Add your credentials to `.env`:
```env
TELEGRAM_BOT_TOKEN="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"
TELEGRAM_API_ID=12345678
TELEGRAM_API_HASH="your_api_hash_here"
```

---

## 🚀 Quick Usage

### 1. Bot Client

Send messages, keyboards, and call any Telegram Bot API method:

```php
use MeRezaRezaei\Teleproto\Facades\Teleproto;

// Send a message via default bot
$bot = Teleproto::bot();
$bot->sendMessage(chatId: '@mychannel', text: 'Hello from Teleproto!');

// Or specify a custom bot token at runtime
$customBot = Teleproto::bot('custom_bot_token_here');
$customBot->sendMessage(chatId: 123456789, text: 'Direct notification');

// Call any generic Bot API method
$me = Teleproto::bot()->call('getMe');
```

---

### 2. User MTProto 2.0 Client

Connect to Telegram as a regular user account over high-speed MTProto 2.0:

```php
use MeRezaRezaei\Teleproto\Facades\Teleproto;

// Initialize client from an exported session string
$user = Teleproto::fromSession($sessionString);

// Send message
$user->sendMessage(peer: '@username', text: 'Hello from MTProto!');

// Call any MTProto RPC method (Layer 227+)
$chat = $user->call('channels.getFullChannel', [
    'channel' => ['_' => 'inputChannel', 'channel_id' => 123456, 'access_hash' => 0]
]);
```

---

### 3. Text Formatting (HTML & Markdown to MessageEntity)

Convert HTML or Markdown strings into plain text with exact `MessageEntity` offsets (with full emoji support):

```php
use MeRezaRezaei\Teleproto\Entities\EntityParser;

$html = '<b>Important Notice:</b> Check <a href="https://example.com">our website</a> 😀';
$parsed = EntityParser::htmlToEntities($html);

$user->sendMessage(
    peer: '@mychannel',
    text: $parsed['text'],
    options: ['entities' => $parsed['entities']]
);
```

---

### 4. Streaming Media from Laravel Storage

Stream large media files (up to 4GB) directly to/from Laravel Storage disks (`local`, `s3`, `minio`) with constant low RAM:

```php
use MeRezaRezaei\Teleproto\Media\StorageMedia;

// Read directly from S3 in 512KB MTProto chunks
foreach (StorageMedia::readFromDisk(path: 'videos/report.mp4', disk: 's3') as $part) {
    $user->call('upload.saveBigFilePart', [
        'file_id'          => $fileId,
        'file_part'        => $part['part_index'],
        'file_total_parts' => $part['total_parts'],
        'bytes'            => $part['bytes'],
    ]);
}
```

---

### 5. Telegram Mini App Authentication Middleware

Protect your Telegram Mini App / Web App backend routes with cryptographically verified HMAC signatures:

```php
// routes/api.php
Route::middleware('tg.miniapp')->group(function () {
    Route::post('/miniapp/me', function (Request $request) {
        $telegramUser = $request->attributes->get('telegram_user');
        return response()->json(['user' => $telegramUser]);
    });
});
```

---

### 6. Telegram Passport KYC Decryption

Decrypt end-to-end encrypted identity documents received from Telegram Passport:

```php
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

$decrypted = PassportDecryptor::decryptCredentials(
    encryptedData:   $passportCredentials['data'],
    encryptedSecret: $passportCredentials['secret'],
    privateKeyPem:   file_get_contents(storage_path('keys/passport_private.pem')),
    hash:            $passportCredentials['hash']
);

$firstName = $decrypted['personal_details']['first_name'];
$documentNo = $decrypted['passport']['document_no'] ?? null;
```

---

## 📚 Guides

- [User MTProto Client Guide](docs/user-client.md)
- [Bot API Client Guide](docs/bot-client.md)
- [Telegram Passport KYC Guide](docs/telegram-passport.md)

---

## 🛡️ License

Released under the **MIT License**. Copyright (c) 2026 MeRezaRezaei.
