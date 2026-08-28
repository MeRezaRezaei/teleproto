# skills/ — generated per-method reference for AI agents

Every file in `skills/telegram-methods/` is a **generated** reference page (`<!-- @generated -->` marker) for one Telegram method on Teleproto's curated dial, distilled from the packaged schema artifacts (`schema/methods-mtproto.json`, `schema/methods-botapi.json`) and the official error database. There are two kinds:

- **MTProto methods** (dot-names like `messages.sendMessage.md`, `auth.sendCode.md`) — callable on a user scope (`Teleproto::user()->call(...)`) or MTProto bot scope, transport `mtproto`
- **Bot API methods** (camelCase names like `sendMessage.md`, `getMe.md`) — callable on the HTTP bot client (`Teleproto::bot()->call(...)`), transport `bot-http`

## How an agent consumes a page

Each page has four sections, all machine-extractable:

1. **Parameters** — table of `name | type | required | description`. Required rows are marked `*`. Types are schema types (`InputPeer`, `long`, `Vector<MessageEntity>`, ...); construct them as plain arrays with a `_` key, e.g. `['_' => 'inputPeerChannel', 'channel_id' => 123, 'access_hash' => 0]`.
2. **Returns** — the expected result constructor(s).
3. **Errors** — the official, verbatim error strings this method can emit (with `%d` placeholders where Telegram substitutes values). At runtime these arrive as typed `MeRezaRezaei\Teleproto\Exceptions\Rpc\*` exceptions; match on `$e->rpcErrorMessage`, never on the human-readable message.
4. **Usage** — a ready-to-paste PHP snippet: build the request with the fluent builder (`Methods::...` group per the page), call `toRequest()`, then hand it to `TeleprotoClient::dispatch()`, which routes it over the correct transport automatically.

For methods outside the dial, call them raw — `$scope->call('method.name', [...$params])` — using the same parameter shape documented here for their curated siblings, and the official schema for details.

## The curated dial (config/curated-methods.json)

Coverage is intentionally curated, not exhaustive: `config/curated-methods.json` lists the `mtproto` and `bot-http` method names that get builders, skill pages, and docs. The dial currently covers messaging, history/search, dialogs, contacts, reactions, forwarding/deleting, user info, login/auth (phone, 2FA, QR, bot import), password, and the core Bot API send/inline/webhook methods. Grow it by adding method names and regenerating:

```bash
# 1. add names to config/curated-methods.json, then:
php bin/generate-method-builders.php   # fluent builders in src/Methods/Generated/
php bin/generate-skill-files.php       # these pages in skills/telegram-methods/
```

Never hand-edit files in this directory — regeneration overwrites them. Schema-layer workflow (audit/update against the live Telegram schema) is documented in `./bin/teleproto help` and AGENTS.md.
