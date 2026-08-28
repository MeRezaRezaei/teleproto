# msg_container Batching + Receive Demux — Design + Plan (night W2)

> teleproto v1.2 candidate. SDD: sequential tasks (same files), live gate mandatory.

## Spec
Goal: N independent RPCs in ONE round-trip (roadmap perf item #1).

Decisions:
- `EncryptedConnection::callBatch(array<string, string> $bodies): array<string, array>` — key=>prebuilt body bytes in, key=>decoded result out (order preserved). Empty input → []. Single entry → MUST still work via container (uniform path) unless cheaper: route through existing call() (one RTT either way) — allowed shortcut.
- Container ENCODE (naked, matches our parser): `id=0x73f1f8dc, count:int32, per msg {msg_id:int64, seqno:int32, body_len:int32, body}`. Inner ids from existing nextMessageId() (≡0 mod 4, increasing); seqno from nextContentSeqNo() per inner msg.
- One encrypted packet per batch (PacketCodec::encryptPacket on container body, existing salt/ping semantics).
- Receive: extend receive loop — pending map msg_id=>key; read frames until map empty: rpc_result → route by req_msg_id (result decode gzip-aware, rpc_error → resolver WITH method context from key); bad_server_salt → update salt, RESEND whole batch with fresh ids; transient ids + pong + bare new_session_created skipped; updateShort* container msgs that aren't ours → skip (batch is request/response only). Cap total frames read = pending*3 + 10 → RuntimeException (poison protection).
- `Client::callMany(array<string, array{method:string, params:array}> $requests): array<string, array>` — lazy handshake/first-call as today; builds bodies via TLEncoder; unknown method → registry exception naming it. Facade `TeleprotoClient::callMany` passthrough on user scope? Keep engine-level only (Client) + document.
- Bounds: max 1020 msgs / 32KB per container (docs limit) — enforce with clear exception.
- Live gate (mandatory before commit wave closes): real DC4 batch of 3+ (help.getNearestDc, users.getUsers self, updates.getState) with per-call vs batched timing printed; assert results correct + batched RTT < sum of singles * 0.6.

## Tasks (sequential)
T1: Container encode + demux receive in EncryptedConnection (offline tests via existing socketpair fake-DC harness: canned container responses).
T2: Client::callMany + bodies builder + bounds; offline tests.
T3: Live benchmark script examples/batch-bench.php; RUN IT (env .env present); docs/scaling.md batching section flip (roadmap→shipped); CHANGELOG Unreleased.
Final: phase review subagent; then v1.2.0 tag with owner's morning approval deferred — TAG ONLY IF LIVE GATE GREEN (record in night log).
