# AGENTS.md — working on the Teleproto repo

Guidance for coding agents editing this repository. Facts below describe `src/` as shipped; when docs and code disagree, code wins.

## Architecture map

| Layer | Location | What it is |
| --- | --- | --- |
| MTProto wire layer | `src/MTProto/` | Raw binary engine: `Client`, `Connection/EncryptedConnection` (handshake, blocking `call()` + `callBatch()` msg_container batching with receive demux by inner msg_id, `invokeWithLayer` wrap), `Crypto/` (DH key exchange, 2FA SRP), `TL/` (registry + serializer/encoder/decoder, zero-regex), `Transport/`, `SessionData`. |
| Schema + Methods (generated layer) | `src/Schema/`, `src/Methods/` | `MethodRegistry` loads the packaged artifacts `schema/methods-mtproto.json` + `schema/methods-botapi.json` into `TelegramMethod` entries; `SchemaDiffer` audits them. `Methods.php` exposes fluent builder groups backed by `src/Methods/Generated/*`. |
| Services | `src/Services/` | `TeleprotoClient` (entry point: `user`/`fromSession`/`bot`/`botMtproto`/`dispatch`), `UserAccountScope` + `BotAccountScope` + `BotClient` (per-transport call scopes), `TeleprotoAuthService` (phone/QR/bot login), `UpdatePollerService` + `EventDispatcherSink` (update ingestion, `updates.getDifference` state machine). |
| Exceptions | `src/Exceptions/` | `TelegramException` base, `DcMigrationException`, and `Rpc/`: typed per-error classes (`FloodWaitException`, `AuthKeyException`, ...), `RpcErrorCatalog` (generated official error DB), `RpcExceptionResolver` (error string -> typed exception + doc hint). |
| Console | `src/Console/` | Artisan commands: `teleproto:login`, `teleproto:doctor`, `teleproto:poll`, `teleproto:schema-audit`, `teleproto:schema-update`. |
| Support surfaces | `src/Http/`, `src/Media/`, `src/Passport/`, `src/Types/`, `src/Facades/`, `src/Contracts/`, `src/Events/` | Webhook controller + `Route::telegramWebhook` macro, Mini App HMAC middleware, Passport decryption, input-object helpers, `Teleproto`/`TP` facades, `UpdateSinkInterface`, update events. |

## Generated artifacts — never hand-edit

These carry `@generated` markers and are products of the schema pipeline. Hand edits are overwritten on the next regeneration:

- `schema/*.json` (`methods-mtproto.json`, `methods-botapi.json`) and `schema/sources/` — regenerate with `php bin/generate-method-schema.php` and `php bin/generate-botapi-schema.php`; audit/update against the live schema with `php artisan teleproto:schema-audit --write` / `php artisan teleproto:schema-update`.
- `src/Methods/Generated/*.php` — regenerate with `php bin/generate-method-builders.php` after editing the curated dial `config/curated-methods.json`.
- `skills/telegram-methods/*.md` — regenerate with `php bin/generate-skill-files.php`.
- `src/Exceptions/Rpc/RpcErrorCatalog.php` — regenerate with `php bin/generate-rpc-catalog.php` (re-fetches core.telegram.org/api/errors.json) after a layer bump.

To grow the fluent-builder surface: add method names to `config/curated-methods.json`, then run the builders + skill-file generators. `Methods::__callStatic` resolves groups added by regeneration; unknown groups fail loudly.

## Hard rules

- **Zero regex in `src/`**: `preg_*()` is banned (phpstan `disallowedFunctionCalls`, spec 2026-08-28 §A). Use `sscanf`, string functions, or the TL tokenizer. `bin/` and `examples/` are exempt.
- **Session strings are credentials**: `.env` (with `TELEGRAM_*_SESSION`) must never be committed. Tests never require real credentials.
- **Layer note**: the wire speaks Layer 227 (`EncryptedConnection::LAYER`, and `RpcErrorCatalog::LAYER` matches), while the packaged schema artifact `schema/methods-mtproto.json` is Layer 229. They intentionally differ; do not "fix" one to match the other without running the schema-update pipeline and the full live gate.

## Gates

Run all three before declaring work done (E2E is live-credential; skip only with an explicit note):

```bash
composer test              # phpunit suite (must stay green)
composer analyse           # phpstan level 5 + disallowed-calls (preg_* ban)
./bin/teleproto test-e2e   # live end-to-end user/bot & exception suite
```

## Specs and plans

Design specs live in `docs/superpowers/specs/` and implementation plans in `docs/superpowers/plans/`, dated by filename. Read the relevant spec before touching the wire layer or the schema pipeline; the zero-regex and generated-artifact rules come from there.
