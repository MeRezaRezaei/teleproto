# Changelog

All notable changes to `teleproto` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- **`msg_container` batching — N independent RPCs in ONE round-trip.** `EncryptedConnection::callBatch()` packs up to 1020 prebuilt request bodies (32 KB container limit) into a single naked `msg_container` — one encrypted packet, one TCP write — and demultiplexes the per-request `rpc_result`s by inner msg_id: gzip-aware result unwrap, typed `rpc_error` resolution carrying the failing request's method context, one whole-batch resend after `bad_server_salt`, poison-frame cap. The container envelope uses the protocol-correct even (non-content-related) seq_no — live DC4 rejects an odd one with `bad_msg_notification` code 34. `Client::callMany()` validates and builds every body up front and returns key => result with input order preserved (fresh connections still pay the `invokeWithLayer` init once; warm connections send all N in one container); `TeleprotoClient::callMany()` passes it through on the default user scope. Live-measured on production DC4: 3 requests in one 21–33 ms container vs 58–81 ms sequential (**2.3–2.7×**, `php examples/batch-bench.php`).

## [v1.0.0] - 2026-08-28

### ⚡ Highlights & Overview
- **Unified Telegram Protocol Engine**: First PHP & Laravel engine to seamlessly support both **Telegram Bot API (HTTP)** and **Native MTProto 2.0 (Binary TCP Sockets)** for both **Bots** and **User Accounts**.
- **100% Stateless Architecture**: No local SQLite databases or file locks; cryptographic state is encapsulated in portable session strings.
- **Zero-Reinvention Principle**: Cryptography, networking, and DOM parsing delegate directly to PHP extensions (`openssl`, `hash`, `dom`, `mbstring`), Laravel Http, and `phpseclib3`.

---

### Added

#### 1. Core MTProto 2.0 Binary Protocol & Cryptography
- Full MTProto 2.0 binary packet encoder/decoder (`PacketCodec`) with AES-256-IGE encryption and SHA-256 message keys.
- Real MTProto 2.0 wire path: TCP framing (`FrameCodec`), auth-key DH handshake (`AuthKeyFactory`), encrypted RPC (`EncryptedConnection`) with gzip_packed + bad_server_salt handling.
- Diffie-Hellman Key Exchange (`DiffieHellman`) with Pollard's Rho GCD composite prime factorization (`PqFactorizer`, verified against official doc vectors); live mode is opt-in on `Client` (`->live()`).
- 2FA Cloud Password (`PasswordCalculator`) using SRP-6a with 100,000 PBKDF2 iterations and modular exponentiation.
- Telegram Passport KYC Decryptor (`PassportDecryptor`) with AES-256-CBC and RSA private key decryption.
- Telegram Mini App (TMA) HMAC signature validation middleware (`VerifyMiniAppInitData`).
- Large media chunked stream reader (`StorageMedia`) supporting multi-gigabyte uploads (up to 4GB) from Flysystem/S3.

#### 2. Bot & User Capabilities
- **Bot HTTP Client (`BotClient`)**: High-performance HTTP client powered by Laravel's Http factory with retry logic and proxy support.
- **Bot MTProto Scope (`BotAccountScope`)**: Direct bot authentication on Telegram Data Centers via `auth.importBotAuthorization`.
- **User MTProto Scope (`UserAccountScope`)**: Full suite of user methods including message reactions, message pinning, address book contact imports, channel administration, profile editing, and active session management.

#### 3. Type Language (TL) & Input Type Builders
- Binary TL Wire Codec (`TLSerializer`) for `int32`, `int64`, `double`, `int128`, `int256`, length-prefixed `bytes`/`string`, and `Vector<T>`.
- Schema-driven TL engine (`TLRegistry`/`TLEncoder`/`TLDecoder`) with golden id vectors.
- Fluent builders: `InlineKeyboard`, `ReplyKeyboard`, `InputPeer`, `InputUser`, `InputChannel`, `InputContact`, `InputFile`, and `InputMedia`.

#### 4. Decoupled Ingestion & Polling Engine
- `UpdatePollerService` with pluggable `UpdateSinkInterface` to stream updates into Redis Streams, PostgreSQL, or Spatie Laravel-Data DTOs.
- Standard 1-line Webhook macro (`Route::telegramWebhook`) with secret token verification and `TelegramUpdateReceived` event dispatching.
- Gap detection and resync events: `TelegramGapDetected` (kind `slice|too_long|hole` + context) and `TelegramResynced` (adopted `{pts, date, qts, seq}` state), both dispatched with the same Laravel-availability guard as `TelegramUpdateReceived`.
- Interactive terminal development poller (`php artisan teleproto:poll`).

#### 5. Interactive CLI & Programmatic Authentication
- Interactive Artisan Login Wizard (`php artisan teleproto:login`):
  - 📱 Phone number + code verification.
  - 🔒 2FA Cloud Password auto-retry loop.
  - 📷 ANSI terminal QR Code Scan (`TerminalQr`).
  - 🤖 Bot MTProto Token authorization.
  - 💾 Automatic `.env` session persistence.
- `teleproto:doctor` live verification command (no Telegram account needed).
- Standalone `TeleprotoAuthService` for programmatic login flows in web controllers, Livewire components, or background jobs.

#### 6. Developer Infrastructure & CI
- GitHub Actions CI matrix testing PHP 8.2, 8.3, and 8.4.
- GitHub Issue & Pull Request templates.
- Open Source `CONTRIBUTING.md`, `SECURITY.md`, and `LICENSE` (MIT).

### Changed
- `ext-zlib` now required; inert proxy context removed from `StreamSocket` (direct connections only until tunneling ships).
- Abridged TCP transport (`0xef` + varint length/4) is the default wire framing — production DCs silently drop intermediate framing.
- Handshake uses `p_q_inner_data_dc` (#a9f55f95) with RSA-PAD encryption (raw RSA over the temp_key/aes construction), required by current servers.
- Live-verified against production Telegram DC2 (both 149.154.167.50 and .51): full DH handshake + encrypted `help.getNearestDc` in ~1.2s (`php examples/live-doctor.php`), cross-checked against MadelineProto as ground truth. Offline verification additionally uses official transcript byte vectors.
- **BC break (pre-release)**: `UpdateSinkInterface::handle()` now returns `bool` (true = processed, false = skip/not-now backpressure) instead of `void`; `EventDispatcherSink` returns true after dispatching. Custom sinks written against earlier pre-release builds must be updated.
- `TelegramUpdateReceived` is enriched with `?int $accountId` and `string $source` (`'mtproto-user'|'bot-http'`); `EventDispatcherSink` derives both automatically from the sink source string (numeric account id → mtproto-user, bot token → bot-http) or takes them explicitly via constructor.
- `pollUser()` now implements the full `updates.getDifference` state machine: `differenceSlice` adopts `intermediate_state` and keeps fetching without ever re-requesting the same window (fixes an infinite-refetch loop), `differenceTooLong` hard-resets pts to the server's, `new_encrypted_messages` (qts items) are wrapped as `updateNewEncryptedMessage` and streamed to the sink instead of being dropped, and sequence state is exposed via `getSequenceState()`/`setSequenceState()` plus a per-channel pts map via `getChannelPts()`.
- Presentation pass for the 1.0 release: Packagist metadata (description, keywords, support links), `bin/teleproto` exposed via `vendor/bin`, `SUPPORT.md`, and this changelog finalized in Keep-a-Changelog format.

### Removed
- Deprecated BC alias `MeRezaRezaei\Teleproto\Exceptions\FloodWaitException` — use `MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException` (pre-release internal BC break; the alias never shipped in a tagged release).
- Dead config keys `redis_connection`, `update_stream`, `command_queue_prefix`, and `bot_username` from `config/teleproto.php` (referenced nowhere in the codebase).
