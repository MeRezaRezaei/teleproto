<div align="center">

# Teleproto ⚡

**Telegram power with almost zero friction — for PHP & Laravel.**

[![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/MeRezaRezaei/teleproto/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/MeRezaRezaei/teleproto/actions)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-8892BF.svg?style=flat-square)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Latest Version](https://img.shields.io/packagist/v/merezarezaei/teleproto.svg?style=flat-square)](https://packagist.org/packages/merezarezaei/teleproto)

</div>

---

You need to send a message, verify a Mini App, decrypt Passport KYC, run a bot, fetch a file — **occasionally**, inside a Laravel app that is about something else. You should not have to adopt a Telegram framework to do it.

- **One composer package, not a framework.** Native MTProto 2.0, Bot API (HTTP), Mini App HMAC auth, Passport KYC decryption, and Storage-backed file streaming — no event loop, no daemon, no IPC.
- **Stateless session strings.** Auth lives in a portable base64 string (`.env` or your DB). No session files, no SQLite, no disk locks. The handshake happened once, ever — it is inside the session string.
- **Typed errors and AI-shaped docs.** Every RPC error resolves to a typed exception carrying Telegram's official error-database hint, and the `skills/` directory gives AI agents a per-method reference they can drive the package from.

---

## The friction we remove

| | **teleproto** | MadelineProto | TDLib |
| :--- | :--- | :--- | :--- |
| **Footprint** | One composer package | Full amphp-based framework | Native C++ library + bindings |
| **Session model** | Stateless string in `.env`/DB | Session files on disk | Own local database |
| **Daemon / event loop** | None — plain blocking calls | amphp event loop | TDLib client process |
| **Learning curve** | Facade + `.env` | Event loop, wrappers, IPC | Auth state machine, build steps |
| **Login wizard** | `php artisan teleproto:login` (phone / QR / 2FA / bot) | Multi-step manual setup | Implement the flows yourself |
| **AI-skill docs** | Generated per-method reference files | — | — |

If you are building a Telegram *client*, MadelineProto and TDLib are the right tools. If you need Telegram call X from your Laravel app tonight, that is the gap teleproto fills.

---

## What you can do today

### Bot on the HTTP Bot API

```php
use MeRezaRezaei\Teleproto\Facades\TP;

TP::bot()->sendMessage('@channel', 'Hello from Teleproto!');
```

### Bot or user over native MTProto 2.0

Binary TCP RPC (Layer 227). Warm calls are a single socket round-trip; file uploads go up to 4 GB.

```php
// Bot over MTProto (auth.importBotAuthorization under the hood):
$bot = TP::botMtproto();
$bot->login();
$bot->sendMessage(peer: '@channel', text: 'Bot broadcast over MTProto');

// User account over MTProto:
$user = TP::user();
$user->sendMessage('@username', 'Hello from a user account!');
```

### Mini App auth middleware

Cryptographic HMAC-SHA256 verification of Telegram `initData`, as one route middleware:

```php
// routes/api.php
Route::middleware('tg.miniapp')->group(function () {
    Route::post('/miniapp/me', function (Request $request) {
        return response()->json($request->attributes->get('telegram_user'));
    });
});
```

### Passport KYC decryption

```php
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

$creds = PassportDecryptor::decryptCredentials(
    encryptedData:   $payload['data'],
    encryptedSecret: $payload['secret'],
    privateKeyPem:   file_get_contents(storage_path('keys/passport_private.pem')),
    hash:            $payload['hash'],
);

$firstName = $creds['personal_details']['first_name'];
```

### Stream large files straight from Laravel Storage

512 KB MTProto parts, chunk-by-chunk from any Storage disk (`local`, `s3`, `minio`) — never load the file in memory:

```php
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\Media\StorageMedia;

$user   = TP::user();
$fileId = random_int(1, PHP_INT_MAX);
foreach (StorageMedia::readFromDisk('media/large_video.mp4', disk: 's3') as $part) {
    $user->call('upload.saveBigFilePart', [
        'file_id'          => $fileId,
        'file_part'        => $part['part_index'],
        'file_total_parts' => $part['total_parts'],
        'bytes'            => $part['bytes'],
    ]);
}
```

### Login wizard: phone / QR / 2FA / bot

```bash
php artisan teleproto:login        # phone + code, 2FA SRP handled automatically — or pick QR
php artisan teleproto:login --qr   # scan the terminal QR from Telegram -> Settings -> Devices
```

Sessions land in `.env` as `TELEGRAM_USER_SESSION` / `TELEGRAM_BOT_SESSION`. After that, `TP::user()` and `TP::botMtproto()` take **zero parameters**.

### Typed errors with official hints

Every RPC failure resolves through the packaged catalog of Telegram's official error database (Layer 227) into a typed exception with a doc-backed hint:

```php
use MeRezaRezaei\Teleproto\Exceptions\FloodWaitException;

try {
    TP::user()->sendMessage('@channel', 'hello');
} catch (FloodWaitException $e) {
    logger()->warning("Flood: retry in {$e->seconds}s — {$e->docHint}");
}
```

### Session strings, not session files

```php
$sessionString = TP::user()->session->exportString();

// Store encrypted in MySQL/PostgreSQL/Redis:
$userModel->update(['telegram_session' => Crypt::encryptString($sessionString)]);

// Restore anywhere, in one line:
$user = TP::fromSession(Crypt::decryptString($userModel->telegram_session));
```

---

## Zero-friction install

```bash
composer require merezarezaei/teleproto
php artisan vendor:publish --tag="teleproto-config"
```

```env
# Bot API (HTTP) — optional if you only use MTProto
TELEGRAM_BOT_TOKEN="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"

# MTProto 2.0 (required for user + bot binary sessions)
# Get yours at https://my.telegram.org (API development tools)
TELEGRAM_API_ID=12345678
TELEGRAM_API_HASH="your_api_hash_here"

# Written automatically by the login wizard:
TELEGRAM_USER_SESSION="2:AQAD...:12345678:0"
TELEGRAM_BOT_SESSION="2:AQAD...:98765432:0"
```

Then log in once: `php artisan teleproto:login`. That is the only setup for MTProto user (and binary-bot) sessions — HTTP Bot API users need nothing but the bot token.

> **Note:** In a Laravel app use `php artisan teleproto:login`. The wizard also ships as `vendor/bin/teleproto login` (in a repo clone: `./bin/teleproto login`).

---

## Tradeoffs — and why they are deliberate

- **One in-flight query per connection.** No Fibers, no event loop. A blocking `call()` (encrypt → send → read) is what keeps the engine small and stateless. `msg_container` batching (N requests → 1 RTT) is the top-ranked v1.1 roadmap item.
- **No update-handler framework.** Updates arrive as Laravel events (`TelegramUpdateReceived`) and through the one-method `UpdateSinkInterface` contract — your pipeline (Redis Stream, queue, Spatie models) plugs in at that seam. Building opinionated handlers on top is deliberately left to higher layers.
- **Full schema registry, curated fluent builders.** Every schema method is callable *today* via `call('method.name', [...])`; generated fluent builders (`Methods::auth()->signIn()->…`) are added from a curated list validated against the schema artifacts.
- **No proxy tunneling yet.** `setProxy()` accepts config, but connections are currently direct — tracked in the transport layer.

Details and the ranked roadmap: [docs/scaling.md](docs/scaling.md) · [Core design spec](docs/superpowers/specs/2026-08-27-teleproto-core-design.md).

---

## Going faster

The DH handshake happened once, ever — it is baked into the session string — so a cold start is just connect + salt (~49 ms measured), and a warm call is one socket round-trip (< 5 ms). Three patterns:

- **Plain FPM** — completely fine for occasional calls per request.
- **One queue worker per account** — Horizon/queue fan-out is the supported multi-account model.
- **Octane** — keeps workers (and sockets) warm between requests.

Full scaling guide, Horizon config, and honest load limits: [docs/scaling.md](docs/scaling.md).

---

## AI-friendly by design

The [`skills/telegram-methods/`](skills/telegram-methods/) directory is a generated, per-method reference — parameter tables, return types, every official error with Telegram's own hint, and copy-paste usage:

```php
use MeRezaRezaei\Teleproto\Methods\Methods;

$request = Methods::auth()->signIn()
    ->phoneNumber('+15551234567')
    ->phoneCodeHash($hash)
    ->phoneCode($code)
    ->toRequest();

$result = app(\MeRezaRezaei\Teleproto\Services\TeleprotoClient::class)->dispatch($request);
```

The same information is indexed for agent crawlers in [`llms.txt`](llms.txt). Both are generated from the packaged schema artifacts — regenerate with `php bin/generate-skill-files.php`.

---

## Documentation

- [Changelog & Release Notes](CHANGELOG.md)
- [Quickstart: Install → First Call](docs/quickstart.md)
- [Docs Index](docs/index.md)
- [User MTProto Client Guide](docs/user-client.md)
- [Bot API Client Guide](docs/bot-client.md)
- [Telegram Passport KYC Guide](docs/telegram-passport.md)
- [Scaling: Multiple Accounts & Load Limits](docs/scaling.md)
- [Core Engine Design Spec](docs/superpowers/specs/2026-08-27-teleproto-core-design.md)

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md) for details.

## License

Teleproto is open-sourced software licensed under the [MIT license](LICENSE).
