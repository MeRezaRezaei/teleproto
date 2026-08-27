# MTProto Wire Path — Design Addendum

Adds to: `2026-08-27-teleproto-core-design.md`

## Problem

The engine's MTProto layer is a facade: `MTProto\Client::call()` returns a mock
`rpc_result`. Missing pieces (identified in the 2026-08-28 audit):

1. **TCP transport framing** — no `0xee` intermediate-transport framing; `StreamSocket` proxy options are inert.
2. **Auth key handshake** — req_pq_multi → req_DH_params → set_client_DH_params is not orchestrated.
3. **Constructor registry** — no method-name → CRC32 constructor-ID mapping; nothing can be serialized.
4. **Real RPC path** — no encrypt → frame → read → decrypt → parse loop in `Client::call()`.
5. **No live verification** — the package has never spoken to a real Telegram DC.

## Decisions

- **Transport**: intermediate framing (`0xee` prefix once, then 4-byte LE length + payload per message).
- **Constructor IDs**: computed at runtime via `crc32()` of canonical TL schema strings, stored in a `TLRegistry`. Golden-vector unit tests pin the IDs we have published values for; if a golden fails, the schema string has a typo (the published ID is authoritative — see core.telegram.org).
- **RSA key**: Telegram's well-known RSA public key is fetched once (curl from core.telegram.org/mtproto_rsa_public_key), normalized, committed at `src/MTProto/resources/telegram_public_key.pub`. Runtime asserts its SHA1-based fingerprint appears in the server's `resPQ.server_public_key_fingerprints` — self-verifying, no memorized fingerprint needed.
- **Offline vs live**: `Client::call()` keeps the current stub when `live=false` (default; CI and existing tests stay green). `live=true` (config `live_mode`, env `TELEPROTO_LIVE=1`) performs the real wire path. If the session has an AuthKey but no salt, `Connection` starts with salt 0 and recovers via `bad_server_salt` retry.
- **First RPC** must be wrapped `invokeWithLayer(LAYER, initConnection(..., query))`; LAYER = 227 (matches README claim).
- **Verification tool**: `php artisan teleproto:doctor` — TCP connect + handshake + `help.getNearestDc` (optionally bot MTProto login), with timings. Acceptance = doctor succeeds against DC2 without any Telegram account.
- **New dev-only deps allowed**: larastan/phpstan. **New runtime requirement**: `ext-zlib` (Telegram gzips RPC responses via `gzip_packed`).
- **Pollard's rho** gets a canonical home in `Crypto/PqFactorizer` (the handshake needs it; `DiffieHellman` is left untouched to avoid regressions).

## Out of scope

Update handler DSLs, DTOs, Redis/Postgres sinks (higher-layer package work). SOCKS4, obfuscated transport. Full TL schema coverage — registry holds only the ~30 constructors the wire path needs.
