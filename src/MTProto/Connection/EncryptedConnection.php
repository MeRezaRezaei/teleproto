<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcExceptionResolver;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use RuntimeException;

/**
 * Encrypted MTProto 2.0 RPC connection (single in-flight query; sufficient for CLI/short jobs).
 *
 * First call is wrapped in invokeWithLayer(layer, initConnection(..., query)); later calls
 * send the bare constructor. Handles bad_server_salt (resend once with the fresh salt),
 * gzip_packed results, rpc_error -> TelegramException, and skips transient push messages.
 * callBatch() packs N independent prebuilt bodies into one naked msg_container
 * (single round-trip) and demultiplexes the per-request rpc_results by inner msg_id.
 */
class EncryptedConnection
{
    public const LAYER = 227;

    /**
     * Transient push messages skipped while waiting for the rpc_result. Both
     * constructors are registered in TLRegistry, so their ids are looked up
     * there (msgs_ack msg_ids:Vector long = MsgsAck -> 0x62d6b459,
     * new_session_created first_msg_id:long unique_id:long server_salt:long
     * = NewSession -> 0x9ec20908) instead of being duplicated here.
     */
    private const MAX_TRANSIENT_MESSAGES = 3;

    /**
     * Docs limits for one msg_container (core.telegram.org/mtproto/service_messages):
     * at most 1020 messages and 32 KB of container payload.
     */
    public const MAX_BATCH_MESSAGES = 1020;
    public const MAX_BATCH_CONTAINER_BYTES = 32768;

    /**
     * Bodies of the in-flight callBatch, key => bytes — kept per connection
     * (single in-flight batch, same contract as the single-call path) so a
     * batch rpc_error can name the failing key's method for the resolver.
     *
     * @var array<string, string>|null
     */
    protected ?array $batchRequestBodies = null;

    /** @var resource|null */
    protected $socket;
    protected int $serverSalt = 0;
    protected int $sessionId;
    protected bool $inited = false;
    protected int $lastMessageId = 0;
    protected int $contentCounter = -1;
    protected float $lastActivity = 0.0;

    /** @param resource|null $socket */
    final public function __construct(protected SessionData $session, $socket = null, protected int $apiId = 0)
    {
        $this->socket = $socket;
        $this->sessionId = (int)unpack('P', random_bytes(8))[1];
        $this->serverSalt = $session->serverSalt; // persisted salt survives reconnects
    }

    public static function connect(SessionData $session, string $host, int $port = 443, float $timeout = 10.0): static
    {
        $socket = StreamSocket::createConnection($host, $port, timeout: $timeout);
        FrameCodec::writeInit($socket);
        return new static($session, $socket);
    }

    /**
     * invokeWithLayer cannot go through TLEncoder (generic {X:Type} field), so it is
     * framed manually: id || layer:int || initConnection object.
     */
    public static function buildFirstQueryBody(int $layer, array $initConnectionArgs): string
    {
        $init = TLEncoder::encodeObject('initConnection', $initConnectionArgs);
        return TLSerializer::packInt(TLRegistry::id('invokeWithLayer'))
            . TLSerializer::packInt($layer)
            . $init;
    }

    /**
     * rpc_result's `result` arrives already decoded (Object field). If it is a
     * gzip_packed wrapper, inflate and decode the inner object; otherwise return as-is.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function unwrapResultIfGzipped(array $result): array
    {
        if (($result['_'] ?? '') !== 'gzip_packed') {
            return $result;
        }
        $inflated = gzdecode($result['packed_data']);
        if ($inflated === false) {
            throw new RuntimeException('EncryptedConnection: gzdecode failed');
        }
        $offset = 0;
        return TLDecoder::decodeObject($inflated, $offset);
    }

    /**
     * MTProto keepalive: ping_delay_disconnect with a fresh ping_id. The
     * server answers pong (same ping_id) and guarantees not to close the
     * connection for disconnect_delay seconds (MadelineProto PingLoop parity).
     * The returned pong must echo the sent ping_id — a mismatch (or a missing
     * ping_id) is a protocol violation and throws.
     *
     * @return array<string, mixed> the pong result (['_' => 'pong', 'msg_id' => ..., 'ping_id' => ...])
     */
    public function ping(int $disconnectDelay = 50): array
    {
        $pingId = $this->newPingId();
        $pong = $this->call('ping_delay_disconnect', [
            'ping_id' => $pingId,
            'disconnect_delay' => $disconnectDelay,
        ]);
        if (!hash_equals((string) $pingId, (string) ($pong['ping_id'] ?? ''))) {
            throw new RuntimeException(sprintf(
                'EncryptedConnection: pong ping_id mismatch (sent %d, received %s)',
                $pingId,
                isset($pong['ping_id']) ? var_export($pong['ping_id'], true) : '(missing)'
            ));
        }
        return $pong;
    }

    /**
     * Fresh random ping_id for a keepalive ping (overridable seam for tests:
     * pre-seeded pongs must know the id before the blocking call runs).
     */
    protected function newPingId(): int
    {
        return random_int(0, PHP_INT_MAX);
    }

    /**
     * Seconds since the last successful send/receive on this connection
     * (0.0 while no traffic has happened yet).
     */
    public function idleSeconds(): float
    {
        return $this->lastActivity === 0.0 ? 0.0 : max(0.0, microtime(true) - $this->lastActivity);
    }

    /**
     * Current server salt (last one seen from bad_server_salt / new_session_created),
     * also mirrored into the SessionData for persistence.
     */
    public function getServerSalt(): int
    {
        return $this->serverSalt;
    }

    /**
     * @return array<string, mixed> decoded result of the RPC call
     * @throws TelegramException on rpc_error
     * @throws RuntimeException on transport/protocol failures
     */
    public function call(string $constructor, array $args = []): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('EncryptedConnection: not connected');
        }

        $body = $this->inited
            ? TLEncoder::encodeObject($constructor, $args)
            : self::buildFirstQueryBody(self::LAYER, [
                'api_id' => $this->apiId,
                'device_model' => 'Teleproto',
                'system_version' => PHP_OS . ' PHP ' . PHP_VERSION,
                'app_version' => '1.0.0',
                'system_lang_code' => 'en',
                'lang_pack' => '',
                'lang_code' => 'en',
                'query' => array_merge(['_' => $constructor], $args),
            ]);

        $maxAttempts = 2; // original + one bad_server_salt resend
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $this->inited = true;
            $packet = PacketCodec::encryptPacket(
                payload: $body,
                authKey: $this->session->authKey,
                sessionId: $this->sessionId,
                serverSalt: $this->serverSalt,
                seqNo: $this->nextContentSeqNo(), // content messages require an odd, increasing seq_no
                serverTimeDelta: $this->session->serverTimeDelta,
                messageId: $this->nextMessageId()
            );
            FrameCodec::sendAbridgedMessage($this->socket, $packet);
            $this->touchActivity();

            $result = $this->receiveDecodedResponse();

            if (($result['_'] ?? '') === 'bad_server_salt') {
                $this->refreshServerSalt((int)$result['new_server_salt']);
                continue; // resend with the fresh salt and a fresh msg_id/seq_no
            }

            if (($result['_'] ?? '') === 'bad_msg_notification') {
                throw new RuntimeException(sprintf(
                    'EncryptedConnection: bad_msg_notification code %d for msg_id %s (seq %d)',
                    (int)($result['error_code'] ?? 0),
                    (string)($result['bad_msg_id'] ?? '?'),
                    (int)($result['bad_msg_seqno'] ?? -1)
                ));
            }

            if (($result['_'] ?? '') !== 'rpc_result') {
                // The server answers ping_delay_disconnect with a BARE pong
                // service message (not an rpc_result) — terminal reply for pings.
                if (($result['_'] ?? '') === 'pong' && $constructor === 'ping_delay_disconnect') {
                    return $result;
                }
                throw new RuntimeException(sprintf(
                    "EncryptedConnection: unexpected response '%s'",
                    $result['_'] ?? '(no constructor)'
                ));
            }

            $inner = self::unwrapResultIfGzipped((array)$result['result']);
            if (($inner['_'] ?? '') === 'rpc_error') {
                throw RpcExceptionResolver::resolve(
                    (string)($inner['error_message'] ?? 'UNKNOWN'),
                    (int)($inner['error_code'] ?? 0),
                    $constructor
                );
            }
            return $inner;
        }
        throw new RuntimeException('EncryptedConnection: exhausted retries after bad_server_salt');
    }

    /**
     * Sends N independent prebuilt request bodies inside ONE naked msg_container
     * (one encrypted packet, one round-trip) and demultiplexes the per-request
     * rpc_results by the container's inner msg_ids. Inner ids/seqnos come from
     * the same nextMessageId()/nextContentSeqNo() generators as single calls,
     * so call() and callBatch() interoperate on one connection.
     *
     * @param array<string, string> $bodies key => prebuilt request body bytes
     * @return array<string, array<string, mixed>> key => decoded result, input order preserved
     * @throws TelegramException on rpc_error (with the failing key's method context)
     * @throws RuntimeException on bounds violations, transport/protocol failures, poison streams
     */
    public function callBatch(array $bodies): array
    {
        if ($bodies === []) {
            return [];
        }
        if (count($bodies) > self::MAX_BATCH_MESSAGES) {
            throw new RuntimeException(sprintf(
                'EncryptedConnection: batch of %d messages exceeds the container limit of %d',
                count($bodies),
                self::MAX_BATCH_MESSAGES
            ));
        }
        if (!is_resource($this->socket)) {
            throw new RuntimeException('EncryptedConnection: not connected');
        }

        $maxAttempts = 2; // original + one bad_server_salt resend
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $this->batchRequestBodies = $bodies;
            $innerIds = [];
            $container = $this->encodeBatchContainer($bodies, $innerIds);

            $packet = PacketCodec::encryptPacket(
                payload: $container,
                authKey: $this->session->authKey,
                sessionId: $this->sessionId,
                serverSalt: $this->serverSalt,
                seqNo: $this->nextContainerSeqNo(), // container = non-content-related: EVEN seq_no, counter not consumed
                serverTimeDelta: $this->session->serverTimeDelta,
                messageId: $this->nextMessageId() // outer id allocated last: strictly greater than every inner id
            );
            FrameCodec::sendAbridgedMessage($this->socket, $packet);
            $this->touchActivity();

            $results = $this->receiveBatchResults($innerIds);
            if ($results === null) {
                continue; // bad_server_salt: salt refreshed, whole batch resent with fresh ids
            }

            $this->batchRequestBodies = null;
            $ordered = [];
            foreach (array_keys($bodies) as $key) {
                $ordered[$key] = $results[$key];
            }
            return $ordered;
        }
        throw new RuntimeException('EncryptedConnection: exhausted retries after bad_server_salt');
    }

    /**
     * Naked msg_container encoding — the write side of parseNakedContainer():
     * id:int32 + count:int32 + per message {msg_id:int64, seqno:int32,
     * body_len:int32, body}. Inner msg_ids are ≡ 0 (mod 4), strictly
     * increasing; every inner message is content (odd, increasing seq_no).
     *
     * @param array<string, string> $bodies
     * @param array<int, string> $innerIds populated with inner msg_id => key
     */
    protected function encodeBatchContainer(array $bodies, array &$innerIds): string
    {
        $container = TLSerializer::packInt(0x73f1f8dc) . TLSerializer::packInt(count($bodies));
        foreach ($bodies as $key => $body) {
            $msgId = $this->nextMessageId();
            $innerIds[$msgId] = $key;
            $container .= TLSerializer::packLong($msgId)
                . TLSerializer::packInt($this->nextContentSeqNo())
                . TLSerializer::packInt(strlen($body))
                . $body;
        }
        if (strlen($container) > self::MAX_BATCH_CONTAINER_BYTES) {
            throw new RuntimeException(sprintf(
                'EncryptedConnection: batch container of %d bytes exceeds the %d-byte limit',
                strlen($container),
                self::MAX_BATCH_CONTAINER_BYTES
            ));
        }
        return $container;
    }

    /**
     * Batch receive loop: reads frames until every pending inner msg_id has
     * its rpc_result. Unrelated pushes (msgs_ack, pong, bare
     * new_session_created, not-ours rpc_results, updateShort*) are skipped;
     * new_session_created refreshes the salt. Returns null to signal
     * bad_server_salt (salt refreshed — caller resends). Poison protection:
     * at most count(pending)*3 + 10 frames per attempt.
     *
     * @param array<int, string> $pending inner msg_id => key
     * @return array<string, array<string, mixed>>|null key => decoded result
     */
    protected function receiveBatchResults(array $pending): ?array
    {
        $results = [];
        $frameCap = count($pending) * 3 + 10;
        for ($framesRead = 0; $pending !== []; $framesRead++) {
            if ($framesRead >= $frameCap) {
                throw new RuntimeException(sprintf(
                    'EncryptedConnection: batch receive exceeded %d frames with %d result(s) still pending',
                    $frameCap,
                    count($pending)
                ));
            }

            $frame = FrameCodec::receiveAbridgedMessage($this->socket);
            $this->touchActivity();
            $this->assertNotTransportErrorFrame($frame);

            $payload = PacketCodec::decryptPacket($frame, $this->session->authKey, expectedSessionId: $this->sessionId)['payload'];
            $id = strlen($payload) >= 4 ? unpack('V', substr($payload, 0, 4))[1] : 0;

            if ($id === 0x73f1f8dc) {
                foreach (self::parseBareContainerMessages($payload) as $body) {
                    if ($this->absorbBatchBody($body, $pending, $results) === 'bad_server_salt') {
                        return null;
                    }
                }
                continue;
            }

            $offset = 0;
            $decoded = TLDecoder::decodeObject($payload, $offset);
            if ($this->absorbBatchBody($decoded, $pending, $results) === 'bad_server_salt') {
                return null;
            }
        }
        return $results;
    }

    /**
     * Splits a received naked msg_container payload into its decoded inner
     * bodies WITHOUT any skip/transient semantics — the batch demux loop
     * (absorbBatchBody) makes its own per-body decisions, including salts.
     *
     * @return list<array<string, mixed>>
     */
    protected static function parseBareContainerMessages(string $payload): array
    {
        $messages = [];
        $offset = 8; // id + count
        $count = unpack('V', substr($payload, 4, 4))[1];
        for ($i = 0; $i < $count; $i++) {
            $offset += 12; // msg_id + seqno
            $bodyLen = unpack('V', substr($payload, $offset, 4))[1];
            $offset += 4;
            $bodyOffset = 0;
            $messages[] = TLDecoder::decodeObject(substr($payload, $offset, $bodyLen), $bodyOffset);
            $offset += $bodyLen;
        }
        return $messages;
    }

    /**
     * Routes one decoded server body inside a batch receive. Mutates $pending
     * (unset on routed rpc_result) and $results. Returns 'bad_server_salt' to
     * request a whole-batch resend, null when the body was consumed or skipped.
     *
     * @param array<string, mixed> $body
     * @param array<int, string> $pending msg_id => key (mutated)
     * @param array<string, array<string, mixed>> $results key => decoded result (mutated)
     */
    protected function absorbBatchBody(array $body, array &$pending, array &$results): ?string
    {
        $name = (string)($body['_'] ?? '');

        if ($name === 'bad_server_salt') {
            $this->refreshServerSalt((int)$body['new_server_salt']);
            return 'bad_server_salt';
        }

        if ($name === 'new_session_created') {
            $this->refreshServerSalt((int)($body['server_salt'] ?? $this->serverSalt));
            return null; // transient push
        }

        if ($name === 'bad_msg_notification') {
            throw new RuntimeException(sprintf(
                'EncryptedConnection: bad_msg_notification code %d for msg_id %s (seq %d)',
                (int)($body['error_code'] ?? 0),
                (string)($body['bad_msg_id'] ?? '?'),
                (int)($body['bad_msg_seqno'] ?? -1)
            ));
        }

        if ($name !== 'rpc_result') {
            return null; // msgs_ack / pong / updateShort* / not-ours pushes: batch is request/response only
        }

        $reqId = (int)$body['req_msg_id'];
        if (!isset($pending[$reqId])) {
            return null; // result of an earlier single call, not this batch
        }

        $key = $pending[$reqId];
        unset($pending[$reqId]);

        $inner = self::unwrapResultIfGzipped((array)$body['result']);
        if (($inner['_'] ?? '') === 'rpc_error') {
            $method = TLRegistry::nameOf(unpack('V', substr((string)$this->batchRequestBodies[$key], 0, 4))[1] ?? 0);
            throw RpcExceptionResolver::resolve(
                (string)($inner['error_message'] ?? 'UNKNOWN'),
                (int)($inner['error_code'] ?? 0),
                $method
            );
        }
        $results[$key] = $inner;
        return null;
    }

    /**
     * A 4-byte frame decoding to a negative int32 is a transport-level
     * error code (e.g. -404: auth key unknown) — surface it typed.
     */
    protected function assertNotTransportErrorFrame(string $frame): void
    {
        if (strlen($frame) === 4) {
            $int32 = unpack('l', $frame)[1];
            if ($int32 < 0) {
                throw RpcExceptionResolver::fromTransportCode($int32);
            }
        }
    }

    /**
     * Transient push-message constructor ids (msgs_ack / new_session_created),
     * sourced from TLRegistry so the ids exist in exactly one place.
     *
     * @return list<int>
     */
    protected static function transientConstructorIds(): array
    {
        return [TLRegistry::id('msgs_ack'), TLRegistry::id('new_session_created')];
    }

    /**
     * Reads encrypted frames until a non-transient message arrives; skips at most
     * MAX_TRANSIENT_MESSAGES consecutive msgs_ack / new_session_created pushes.
     *
     * @return array<string, mixed>
     */
    protected function receiveDecodedResponse(): array
    {
        $transientIds = self::transientConstructorIds();
        for ($transients = 0; $transients <= self::MAX_TRANSIENT_MESSAGES; $transients++) {
            $frame = FrameCodec::receiveAbridgedMessage($this->socket);
            $this->touchActivity();
            $this->assertNotTransportErrorFrame($frame);

            $msg = PacketCodec::decryptPacket($frame, $this->session->authKey, expectedSessionId: $this->sessionId);
            $payload = $msg["payload"];

            $id = strlen($payload) >= 4 ? unpack('V', substr($payload, 0, 4))[1] : 0;
            if (in_array($id, $transientIds, true)) {
                if ($id === TLRegistry::id('new_session_created')) {
                    $offset = 0;
                    $push = TLDecoder::decodeObject($payload, $offset);
                    $this->refreshServerSalt((int)($push['server_salt'] ?? $this->serverSalt));
                }
                continue; // transient push, keep reading
            }

            $offset = 0;
            $id = strlen($payload) >= 4 ? unpack('V', substr($payload, 0, 4))[1] : 0;

            // msg_container uses naked encoding (id + count + {msg_id, seqno, bytes, body} tuples,
            // no vector header, no per-message constructor) — must be hand-parsed.
            if ($id === 0x73f1f8dc) {
                $actionable = self::parseNakedContainer($payload, $this->serverSalt);
                $this->refreshServerSalt($actionable['salt']);
                if ($actionable['message'] === null) {
                    continue; // container held only transients; keep reading
                }
                return $actionable['message'];
            }

            $decoded = TLDecoder::decodeObject($payload, $offset);
            return $decoded;
        }
        throw new RuntimeException('EncryptedConnection: too many consecutive transient messages');
    }

    /**
     * Parses a naked-encoded msg_container payload. Returns the first
     * actionable decoded body (or null when every element was transient)
     * plus the possibly-refreshed server salt from new_session_created.
     *
     * @return array{message: array<string, mixed>|null, salt: int}
     */
    protected static function parseNakedContainer(string $payload, int $currentSalt): array
    {
        $transientIds = self::transientConstructorIds();
        $salt = $currentSalt;
        $offset = 4;
        $count = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;

        for ($i = 0; $i < $count; $i++) {
            $offset += 8; // msg_id
            $offset += 4; // seqno
            $bodyLen = unpack('V', substr($payload, $offset, 4))[1];
            $offset += 4;
            $bodyBin = substr($payload, $offset, $bodyLen);
            $offset += $bodyLen;

            $bodyOffset = 0;
            $body = TLDecoder::decodeObject($bodyBin, $bodyOffset);
            $name = (string)($body['_'] ?? '');

            if ($name === 'new_session_created') {
                $salt = (int)($body['server_salt'] ?? $salt);
                continue;
            }
            if (in_array(TLRegistry::id($name), $transientIds, true)) {
                continue;
            }
            return ['message' => $body, 'salt' => $salt];
        }
        return ['message' => null, 'salt' => $salt];
    }

    /**
     * Encrypted client message id: ≡ 0 (mod 4), strictly increasing —
     * matching MadelineProto/TDLib (server rejects other residues with
     * bad_msg_notification code 18). Seq stays odd for content messages.
     */
    protected function nextMessageId(): int
    {
        $candidate = ((int)((microtime(true) + $this->session->serverTimeDelta) * 2**32)) & ~3;
        if ($candidate <= $this->lastMessageId) {
            $candidate = $this->lastMessageId + 4; // keeps ≡ 0 (mod 4) while strictly increasing
        }
        return $this->lastMessageId = $candidate;
    }

    /**
     * Refreshes the in-memory salt and mirrors it into the SessionData the
     * connection holds (same object reference the Client owns), so a fresh
     * salt from bad_server_salt / new_session_created survives reconnects
     * and session persistence.
     */
    protected function refreshServerSalt(int $salt): void
    {
        $this->serverSalt = $salt;
        $this->session->serverSalt = $salt;
    }

    protected function touchActivity(): void
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * seq_no for the outgoing msg_container envelope. A container is a
     * NON-content-related message (never acknowledged on its own), so its
     * seq_no must be EVEN: counter*2 with the content counter NOT consumed
     * (docs: core.telegram.org/mtproto/description, error_code 34 otherwise).
     * Because the container is generated after its contents, this is always
     * one above the highest inner seq_no — "greater than or equal to the
     * sequence numbers of the messages contained in it" holds.
     */
    protected function nextContainerSeqNo(): int
    {
        return $this->contentCounter + 1; // contentCounter is always odd (−1 + 2n), so +1 is even
    }

    /**
     * seq_no for outgoing client content messages: odd and strictly
     * increasing (2n+1). A content message with an even seq_no is rejected
     * by the server with bad_msg_notification code 35.
     */
    protected function nextContentSeqNo(): int
    {
        return $this->contentCounter += 2;
    }

    /**
     * Whether invokeWithLayer/initConnection has already been sent on this
     * connection. callBatch() never sends the wrap itself, so its callers
     * (Client::callMany) route the first request through call() to establish
     * the layer before batching the rest.
     */
    public function isInited(): bool
    {
        return $this->inited;
    }

    public function lastSessionData(): SessionData
    {
        return $this->session;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
