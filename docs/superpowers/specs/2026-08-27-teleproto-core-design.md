# Teleproto Core Engine Design Specification

## 1. Purpose & Architectural Boundaries

`teleproto` is the low-level Telegram Multi-Protocol (MTProto 2.0 & Bot API) engine for PHP and Laravel. Its sole responsibility is wire-level interaction, cryptographic authentication, binary serialization, and raw API execution.

### Strict Non-Goals (Delegated to Higher-Level Packages)
- **No Data Transfer Objects (DTOs)**: Object hydration, schema casting, validation rules, and Spatie `laravel-data` integration belong entirely in consuming packages.
- **No Eloquent / Database Modeling**: Persisting chats, messages, or users is high-level application logic.
- **No Conversational Routing / Middleware Handlers**: Flow dispatching and update handlers belong in higher layers.

---

## 2. Core Protocol Contract

### A. Uniform Raw Array Returns
All execution endpoints (`BotClient::call`, `UserAccountScope::call`, and facade helpers) strictly return raw PHP associative arrays (`array<string, mixed>`):
```php
$res = TP::bot()->sendMessage('@channel', 'Hello');
// Returns: ['ok' => true, 'result' => ['message_id' => 101, 'chat' => [...]]]

$res = TP::user(12345, $session)->call('messages.getHistory', ['peer' => $peer]);
// Returns: ['_' => 'messages.channelMessages', 'messages' => [...], 'chats' => [...]]
```
Consuming packages can hydrate these directly into Spatie `Data` classes:
```php
$message = MessageData::from($res['result']);
```

### B. Wire Primitives & Helpers (`src/Types/`)
Lightweight constructors producing the exact associative array shapes expected by Telegram:
- **`InputPeer`**: `user()`, `channel()`, `chat()`, `self()`
- **`InputUser`**: `user()`, `self()`, `empty()`, `fromMessage()`
- **`InputChannel`**: `channel()`, `empty()`, `fromMessage()`
- **`InputFile`**: `file()`, `big()`
- **`InputMedia`**: `photo()`, `video()`, `document()`, `audio()`, `animation()`
- **`InlineKeyboard`**: Fluent builder emitting `['inline_keyboard' => [...]]`
- **`ReplyKeyboard`**: Fluent builder emitting `['keyboard' => [...], 'resize_keyboard' => true]`

### C. Low-Level Cryptographic & Wire Utilities
- **`EntityParser`**: Native `DOMDocument` HTML parser and lexical Markdown scanner for MTProto `MessageEntity` generation.
- **`PassportDecryptor`**: Native OpenSSL AES-256-CBC and RSA decryptor for Telegram Passport KYC payloads.
- **`PasswordCalculator`**: SRP-6a 2FA cloud password generator.
- **`PacketCodec`**: MTProto 2.0 packet encryption/decryption with AES-256-IGE.
- **`TLSerializer`**: Binary TL length-prefixed and vector codec.
- **`StorageMedia`**: 512KB chunked stream reader connecting Flysystem disks to Telegram MTProto upload pipes.

---

## 3. Exception Hierarchy
- **`TelegramException`**: Base exception carrying Telegram `error_code` and `description`.
- **`FloodWaitException`**: Thrown on `FLOOD_WAIT_X` with `$seconds` getter.
- **`DcMigrationException`**: Thrown on `PHONE_MIGRATE_X` / `USER_MIGRATE_X` with target `$dcId` getter.

---

## 4. Release Checklist
1. All 28 test cases passing.
2. Code style and typing verified.
3. Documentation references standard facades (`Teleproto`, `TP`).
4. Ready for v1.0.0 tagging and packagist release.
