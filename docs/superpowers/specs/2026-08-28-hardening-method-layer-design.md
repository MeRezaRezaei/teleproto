# Hardening & Method-Object Layer — Design

Adds to: `2026-08-27-teleproto-core-design.md` (boundaries), `2026-08-28-mtproto-wire-path-design.md`.

## Problem

1. 19 `preg_*` sites across 9 src files. Regex fragility = the three-hour-debug class of failure (a subtly wrong pattern survives silently, explodes layers later). TL signature grammar is parsed by three scattered regexes re-run per encode/decode call.
2. `ctype_*` used but `ext-ctype` undeclared.
3. Hand-rolled env parsing where Laravel's ecosystem parser exists.
4. Method knowledge lives nowhere: codec, docs, and future AI-skills each re-derive what a Telegram method is. No repeatable workflow for Telegram API changes.

## Decisions

### A. Zero regex (hard rule)
- No `preg_*` anywhere under `src/`. Dev scripts (`bin/`, `examples/`) exempt.
- TL signature parsing → `TLSignatureParser`: deterministic character-level tokenizer, parses ONCE at `register()`, cached as `ParsedSignature` (name, id, fields[{name,type,flagWord,bit}], returnType). Malformed line throws with character offset + reason. Encoder/decoder consume the cached struct — zero parsing on the hot path.
- Parameterized matches → `sscanf` (C parser, exact format strings).
- Template matching in `RpcErrorCatalog` → explode-on-`%d` + `str_starts_with`/`str_ends_with` + `strspn` digit check.
- `AuthKeyFactory` PEM handling → phpseclib `PublicKeyLoader`/`toString('PKCS1')` + line-filtered base64 body (structured lib replaces DER byte-scraping).
- `EnvFile`, `LoginCommand` validation, `EntityParser` language check → string functions.
- Enforcement: architecture test scanning `src/**.php` for the literal `preg_` fails the suite.
- All existing goldens/transcript byte-tests/e2e must pass unchanged — byte-identical behavior.

### B. Extension & Laravel hygiene
- Kill `ctype_*` (via strspn replacements); no new ext requirement. Declared set stays: dom, hash, json, libxml, mbstring, openssl, zlib (+gmp/bcmath suggested).
- `EnvFile::read` → `vlucas/phpdotenv` `Dotenv::parse` (Laravel's own parser).
- Validation closures use `Illuminate\Support\Str`.
- Contract unchanged: wire speaks `array<string,mixed>`; Laravel never appears in public returns.

### C. Method-Object layer (Command + Registry + separated Invoker)
- **Full schema as derived data; curated builders as API.** Coverage is a dial, not a door.
- Derived artifacts (committed, never hand-edited):
  - `schema/sources/{api.tl,mtproto.tl}` — fetched from telegramdesktop/tdesktop dev branch (canonical Telegram source).
  - `schema/sources/errors.json` — from core.telegram.org/api/errors.json.
  - `schema/methods-mtproto.json` — every layer function: params (typed via our own TLSignatureParser), return type, docs URL (`core.telegram.org/method/<name>`), per-method error list (inverted from errors.json), layer number.
  - `schema/methods-botapi.json` — Bot HTTP API methods parsed from core.telegram.org/bots/api via DOMDocument (zero regex), with param tables + doc anchors.
- `TelegramMethod` readonly value object + `MethodRegistry::get/has/apiOf` — knowledge, no behavior.
- Fluent request builders **generated** from `config/curated-methods.json` (seeded with the ~30 scope methods) into `src/Methods/Generated/` + `Methods::` static entry. `toRequest(): array` — plain array at the boundary.
- Invoker stays separated: existing `EncryptedConnection`/`BotClient`; thin `TeleprotoClient::dispatch(array $request)` picks invoker by catalog api kind.
- Three consumers, one source of truth: runtime (dispatch), docs (rendered pages later), AI skills (below).

### D. Update/audit pipeline + AI skills
- `bin/teleproto schema-update` — re-fetch sources, regenerate to temp.
- `bin/teleproto schema-audit` — `SchemaDiffer::diff(old, new)` → report (added/removed/changed methods + layer bump) as markdown + nonzero exit on change. Differ unit-tested on fixtures.
- `bin/teleproto skills-generate` — renders `skills/telegram-methods/**.md` per curated method: params table, builder usage example generated from the registry, docs URL, error hints from RpcErrorCatalog. Deterministic; fixture-tested.

## Out of scope
Public Collections, DTOs, Eloquent, handlers, proxy tunneling, full-schema builders.
