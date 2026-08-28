# Changelog

All notable changes to `teleproto` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]
### Added
- Real MTProto 2.0 wire path: intermediate TCP framing (`FrameCodec`), auth-key DH handshake (`AuthKeyFactory`), encrypted RPC (`EncryptedConnection`) with gzip_packed + bad_server_salt handling.
- `teleproto:doctor` live verification command (no Telegram account needed).
- `TLRegistry`/`TLEncoder`/`TLDecoder` schema-driven TL engine with golden id vectors.
- `PqFactorizer` (Pollard rho) with official doc vectors; live mode opt-in on `Client` (`->live()`).
### Changed
- `ext-zlib` now required; inert proxy context removed from `StreamSocket` (direct connections only until tunneling ships).
- Live network verification is pending environment access; offline verification uses official transcript byte vectors.

---

## [v1.0.0] - 2026-08-28

### ⚡ Highlights & Overview
- **Unified Telegram Protocol Engine**: First PHP & Laravel engine to seamlessly support both **Telegram Bot API (HTTP)** and **Native MTProto 2.0 (Binary TCP Sockets)** for both **Bots** and **User Accounts**.
- **100% Stateless Architecture**: No local SQLite databases or file locks; cryptographic state is encapsulated in portable session strings.
- **Zero-Reinvention Principle**: Cryptography, networking, and DOM parsing delegate directly to PHP extensions (`openssl`, `hash`, `dom`, `mbstring`), Laravel Http, and `phpseclib3`.

---

### Added

#### 1. Core MTProto 2.0 Binary Protocol & Cryptography
- Full MTProto 2.0 binary packet encoder/decoder (`PacketCodec`) with AES-256-IGE encryption and SHA-256 message keys.
- Diffie-Hellman Key Exchange (`DiffieHellman`) with Pollard's Rho GCD composite prime factorization.
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
- Fluent builders: `InlineKeyboard`, `ReplyKeyboard`, `InputPeer`, `InputUser`, `InputChannel`, `InputContact`, `InputFile`, and `InputMedia`.

#### 4. Decoupled Ingestion & Polling Engine
- `UpdatePollerService` with pluggable `UpdateSinkInterface` to stream updates into Redis Streams, PostgreSQL, or Spatie Laravel-Data DTOs.
- Standard 1-line Webhook macro (`Route::telegramWebhook`) with secret token verification and `TelegramUpdateReceived` event dispatching.
- Interactive terminal development poller (`php artisan teleproto:poll`).

#### 5. Interactive CLI & Programmatic Authentication
- Interactive Artisan Login Wizard (`php artisan teleproto:login`):
  - 📱 Phone number + code verification.
  - 🔒 2FA Cloud Password auto-retry loop.
  - 📷 ANSI terminal QR Code Scan (`TerminalQr`).
  - 🤖 Bot MTProto Token authorization.
  - 💾 Automatic `.env` session persistence.
- Standalone `TeleprotoAuthService` for programmatic login flows in web controllers, Livewire components, or background jobs.

#### 6. Developer Infrastructure & CI
- GitHub Actions CI matrix testing PHP 8.2, 8.3, and 8.4.
- GitHub Issue & Pull Request templates.
- Open Source `CONTRIBUTING.md`, `SECURITY.md`, and `LICENSE` (MIT).
