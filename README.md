<div align="center">

# Teleproto ⚡

**Unified Telegram Multi-Protocol Engine for PHP & Laravel**

[![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/MeRezaRezaei/teleproto/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/MeRezaRezaei/teleproto/actions)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-8892BF.svg?style=flat-square)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Latest Version](https://img.shields.io/packagist/v/merezarezaei/teleproto.svg?style=flat-square)](https://packagist.org/packages/merezarezaei/teleproto)

</div>

---

**Teleproto** is a high-performance, stateless Telegram protocol engine for PHP and Laravel. It unifies **MTProto 2.0 User Sessions**, **Telegram Bot API**, **Mini App HMAC Authentication**, **Telegram Passport KYC Decryption**, and **Storage Streaming** in a single low-level library with zero bloat.

---

## 📦 Installation

```bash
composer require merezarezaei/teleproto
```

### Publish Configuration
```bash
php artisan vendor:publish --tag="teleproto-config"
```

Configure `.env`:
```env
# Optional: For HTTP Bot API calls
TELEGRAM_BOT_TOKEN="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"

# Required for MTProto 2.0 (Both User & Bot high-speed binary connections)
# Obtain from https://my.telegram.org (API development tools)
TELEGRAM_API_ID=12345678
TELEGRAM_API_HASH="your_api_hash_here"
```

---

## 💡 Why Teleproto? (Architecture & MTProto vs Bot API)

| Feature | Standard Bot API (HTTP) | Teleproto Native MTProto 2.0 (Binary) |
| :--- | :--- | :--- |
| **Transport** | JSON over HTTP/HTTPS | Binary TL over raw TCP Sockets (Layer 227+) |
| **Speed & Latency** | High (HTTP handshake + JSON parse) | **Ultra-Low (<5ms)** |
| **Bot Support** | ✅ Yes | ✅ **Yes** (via `auth.importBotAuthorization`) |
| **User Support** | ❌ No | ✅ **Yes** (Full User MTProto Account) |
| **Max File Upload** | 50 MB | **Up to 4,000 MB (4 GB)** |
| **State Management** | None (Stateless) | **100% Stateless Session String** (Zero disk locks) |

### 🔑 Why are `api_id` and `api_hash` needed?
Telegram gates direct binary TCP socket connections to its core Data Centers behind application credentials. Providing your `api_id` and `api_hash` allows Teleproto to perform Diffie-Hellman key exchange and authenticate both **User accounts** and **Bots** natively on Telegram's core MTProto RPC servers.

### 💾 Stateless Session Strings
Teleproto introduces zero filesystem locks or local SQLite databases. A session is a lightweight, portable base64 string:
```php
// Export session after login
$sessionString = $user->session->exportString();

// Store encrypted in MySQL/PostgreSQL/Redis:
$userModel->update(['telegram_session' => Crypt::encryptString($sessionString)]);

// Restore anywhere in a single line:
$user = TP::fromSession(Crypt::decryptString($userModel->telegram_session));
```

---

## 🚀 Quick Start

Teleproto provides two standard facades: `Teleproto` and `TP`.

### 1. Bot Client (HTTP Bot API)

```php
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\Types\InlineKeyboard;

// Send message via default bot
TP::bot()->sendMessage('@channel', 'Hello from Teleproto!');

// Dynamic bot token at runtime with Inline Keyboard
$keyboard = InlineKeyboard::make()
    ->row([InlineKeyboard::urlButton('Website', 'https://example.com')])
    ->row([InlineKeyboard::callbackButton('Click Me', 'btn_clicked')]);

$bot = TP::bot('custom_token_here');
$bot->sendMessage(chatId: 123456789, text: 'Choose an option:', options: [
    'reply_markup' => $keyboard
]);
```

---

### 2. High-Speed Native MTProto 2.0 (Both Bots & Users)

Run your **Bots** and **User Accounts** directly over Telegram's high-speed binary MTProto 2.0 TCP sockets for maximum throughput, large file transfers (up to 4GB), and zero HTTP webhook/polling overhead:

```php
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\Types\InputPeer;

// A. Bot operating directly over MTProto 2.0 binary socket
$botMtproto = TP::botMtproto('123456:BOT-TOKEN');
$botMtproto->login();
$botMtproto->sendMessage(peer: '@channel', text: 'Lightning fast message over MTProto binary socket!');

// B. User Account operating over MTProto 2.0
$user = TP::fromSession($sessionString);
$user->sendMessage(peer: '@username', text: 'Hello from User MTProto!');
$chat = $user->getFullChannel(InputPeer::channel(123456, 'access_hash'));
```

---

### 3. Native Text & Markdown Entity Parsing

Calculates exact UTF-16 code unit offsets with emoji and surrogate pair support:

```php
use MeRezaRezaei\Teleproto\Entities\EntityParser;

// Parse HTML
$parsedHtml = EntityParser::htmlToEntities('<b>Bold</b> <i>Italic</i> 😀 <a href="https://tg.org">Link</a>');

// Parse MarkdownV2
$parsedMd = EntityParser::markdownToEntities('*Bold* _Italic_ `Code` [Link](https://tg.org)');

$user->sendMessage('@channel', $parsedHtml['text'], [
    'entities' => $parsedHtml['entities']
]);
```

---

### 4. Telegram Mini App (TMA) Authentication

Protect your Mini App backend routes with cryptographically verified HMAC signatures:

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

### 5. Large File Streaming from Storage

Stream up to 4GB media directly from Laravel Storage disks (`local`, `s3`, `minio`) in 512KB chunks:

```php
use MeRezaRezaei\Teleproto\Media\StorageMedia;

foreach (StorageMedia::readFromDisk('media/large_video.mp4', disk: 's3') as $part) {
    $user->call('upload.saveBigFilePart', [
        'file_id'          => $fileId,
        'file_part'        => $part['part_index'],
        'file_total_parts' => $part['total_parts'],
        'bytes'            => $part['bytes'],
    ]);
}
```

---

### 6. Telegram Passport KYC Decryption

```php
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

$decrypted = PassportDecryptor::decryptCredentials(
    encryptedData:   $passportCredentials['data'],
    encryptedSecret: $passportCredentials['secret'],
    privateKeyPem:   file_get_contents(storage_path('keys/passport_private.pem')),
    hash:            $passportCredentials['hash']
);

$firstName = $decrypted['personal_details']['first_name'];
```

---

## 📖 Documentation Guides

- [User MTProto Client Guide](docs/user-client.md)
- [Bot API Client Guide](docs/bot-client.md)
- [Telegram Passport KYC Guide](docs/telegram-passport.md)
- [Core Engine Design Spec](docs/superpowers/specs/2026-08-27-teleproto-core-design.md)

---

## 🧪 Testing

```bash
composer test
# or
./vendor/bin/phpunit
```

---

## 🤝 Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md) for details.

---

## 🛡️ License

Teleproto is open-sourced software licensed under the [MIT license](LICENSE).
