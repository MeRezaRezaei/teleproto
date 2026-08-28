# Scaling Guide

How to run Teleproto across many accounts and bots today, and what the roadmap changes. Every claim below reflects the current code in `src/` — where a limit exists, it is stated as a limit.

---

## 📦 Today (v1.0): One Process per Account

Teleproto's MTProto connection is a **blocking, single in-flight socket** (`EncryptedConnection` sends one query and reads until its `rpc_result` returns). There is no shared event loop and no in-process multiplexing — by design, this is what keeps the engine small and stateless.

The supported scaling model is **horizontal: one process per account/bot**, driven by Laravel queue workers or Horizon.

Two facts make this safe:

1. **The same auth key may be used from multiple `session_id`s.** Every `EncryptedConnection` generates its own random `session_id` at construction; Telegram treats each `session_id` as an independent authorized session on the same authorization. Two workers using the same session string are two independent connections, not a conflict.
2. **A session string is a value, not a lock.** Sessions are plain strings (no files, no SQLite). Each worker loads its account's session string from your encrypted storage, builds its own `Client` → `EncryptedConnection` → server salt, and never shares mutable state with other workers.

### Horizon Supervisor for 20 Accounts

Dispatch one long-running polling job per account onto a dedicated queue; Horizon keeps 20 processes alive (one job each):

```php
// config/horizon.php
'supervisors' => [
    [
        'name' => 'teleproto',
        'connection' => 'redis',
        'queue' => ['teleproto-accounts'],
        'processes' => 20,          // one process per account
        'balance' => 'simple',      // each process takes exactly one job
        'maxTime' => 0,             // polling jobs run until deployed/stopped
        'sleep' => 0,
        'tries' => 1,
        'timeout' => 0,             // long-lived; rely on `stop()` / redeploy
    ],
],
```

```php
// App\Jobs\PollTelegramAccount — dispatched once per account row
use Illuminate\Support\Facades\Crypt;
use MeRezaRezaei\Teleproto\Facades\TP;
use MeRezaRezaei\Teleproto\Services\UpdatePollerService;

public function handle(): void
{
    $account = TelegramAccount::findOrFail($this->accountId);

    // Each worker owns its Client + connection + salt,
    // built from the encrypted session string it alone loads:
    $sessionString = Crypt::decryptString($account->telegram_session);
    $user = TP::fromSession($sessionString);

    (new UpdatePollerService(new RedisStreamSink()))
        ->pollUser($user, pts: $account->pts, date: $account->date, qts: $account->qts);
}
```

```php
// Bootstrap: push 20 jobs (idempotent — re-dispatch on deploy)
TelegramAccount::query()->pluck('id')->each(
    fn ($id) => PollTelegramAccount::dispatch($id)->onQueue('teleproto-accounts')
);
```

Because `maxTime`/`timeout` are `0`, each job polls until Horizon restarts it on deploy. The poller's `stop()` and its interruptible backoff sleep (`UpdatePollerService::MAX_BACKOFF_SECONDS`) keep shutdowns and FLOOD_WAIT windows well-behaved inside those long-lived workers.

For **bots**, the same layout applies — swap `pollUser()` for `pollBot(TP::bot($account->bot_token))`, one process per bot token.

---

## ❄️ Cold Start, Measured

Real numbers from a live measurement (fresh PHP process → one delivered `messages.sendMessage` RPC):

| Process state | First delivered RPC |
| :--- | :--- |
| **Cold** — fresh process, session string loaded from `.env`/DB | **~49 ms** |
| **Warm** — client already connected in-process | **~5 ms** |

The key fact: the ~49 ms cold cost is **socket + encrypted-session setup only — never the handshake**. The expensive Diffie–Hellman handshake happens **once, ever**: `php artisan teleproto:login` performs it and packs the resulting auth key into the session string (`TELEGRAM_USER_SESSION`). Every later process loads that string and pays only the ~49 ms setup before its first call, then ~5 ms per call.

What that means per Laravel runtime:

- **PHP-FPM** — every request is a cold process. ~49 ms of setup is perfectly fine for *occasional* Telegram work (a notification, a Passport callback). Don't push a per-request hot path through MTProto at volume; that's what workers are for.
- **Queue workers / Horizon** — each worker boots once per account and stays warm: every subsequent call is the ~5 ms path. The layout above already gives you this for free.
- **Octane** — boot once, stay warm over HTTP: build the client in a container binding during boot and reuse it across requests (workers handle one request at a time, matching the single in-flight socket).

```text
 once, ever                 every process                  per call
──────────────────        ─────────────────────────      ─────────────────
teleproto:login     ──▶   load session string      ──▶   warm RPC ≈ 5 ms
  (DH handshake           (~49 ms cold start:            │
   → auth key)             TCP + session setup)           ├── FPM: every request cold
                           ├── FPM: every request         │     (fine: occasional)
                           ├── queue worker: once         └── worker/Octane:
                           └── Octane: boot once               warm from call #2
```

The honest takeaway: Teleproto has no daemon precisely because cold start is cheap. You only reach for long-lived workers when you want *warm* latency or continuous polling — not because the library forces you to.

---

## 🗺️ v1.1 Roadmap (Ranked)

Audited against the current `EncryptedConnection`; highest value first:

### 1. Send-side `msg_container` batching (N requests → 1 RTT)
Today `call()` blocks per query: encrypt → send → read. Batching wraps N outgoing queries in one `msg_container` on one TCP write, so N round trips collapse into one. This is the biggest single win because it attacks RTT, which dominates Telegram latency for PHP workers.

What it requires:
- a **pending map**: `msg_id → deferred result` so replies can be matched to callers,
- proper **ack tracking** (`msgs_ack`) instead of today's "skip transient pushes" behavior in `receiveDecodedResponse()`.

### 2. Receive-side demultiplexing
The receive path already parses incoming `msg_container`s (`parseNakedContainer()`), but returns only the **first actionable message** and discards the rest. Full demux delivers every element to the pending map — a prerequisite for >1 in-flight request per connection.

### 3. Per-DC connection pools
A pool of `EncryptedConnection`s per data center (chosen by session DC info), round-robining batched sends. This multiplies throughput per account once items 1–2 land.

### 4. Full Fiber/event-loop: deliberately NOT planned
A single-threaded event loop (Amphp ReactPHP, MadelineProto-style) would complicate every code path for one account — and MadelineProto's own handshake is sequential anyway. With container batching plus process fan-out, the marginal win from async I/O is small. PHP processes are cheap; RTTs are not. Batching first, Fibers never (unless a contributor proves otherwise with benchmarks).

---

## 🐢 Backpressure: Slow Consumers

Updates are delivered **synchronously** to your sink inside the polling loop:

```php
interface UpdateSinkInterface
{
    public function handle(array $update, ?string $source = null): bool;
}
```

Returning `false` is a **not-now / skip refusal** — the sink signaling backpressure. When `handle()` returns `false`, the poller skips the inline `onUpdate` callback for that update (the update is already consumed by the sink's refusal, not re-delivered). Do not throw to refuse softly; return `false` and let your pipeline decide (drop, retry, defer).

There is no internal buffer: a sink that blocks (heavy DB work, synchronous HTTP) stalls that account's whole loop — polling pauses, and backoff only covers Telegram errors, not slow sinks. Keep `handle()` fast and hand off: write to a Redis Stream / queue and let workers do the heavy lifting. That is what the contract exists for: it is the single seam where your pipeline plugs in, and the single place you must not be slow.

---

## 📊 Load Limits, Honestly

| Capability | v1.0 | v1.1 (planned) |
| :--- | :--- | :--- |
| In-flight queries per connection | **1** (blocking `call()`) | N (via `msg_container` batching) |
| Round trips for N requests | N | ~1 |
| Throughput ceiling per connection | ~1/RTT per query | RTT-amortized |
| Concurrent accounts | One process each (Horizon/queue workers) | Same — process model does not change |

Rules of thumb for v1.0:

- **One process per account/bot.** Multiple processes may share one auth key (distinct `session_id`s); do not share one `EncryptedConnection` across processes — it is a socket, not a broker.
- **Fan out processes when** you are latency-bound on a single account (sequential dependent calls) or volume-bound across accounts (add workers). Fan-out is the v1.0 answer to both.
- **Do not** try to raise per-account throughput by opening many connections to the same DC from one script — that is the pool feature (roadmap item 3), and doing it ad-hoc today just multiplies handshakes without batching.
