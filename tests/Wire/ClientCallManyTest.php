<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use PHPUnit\Framework\TestCase;

class ClientCallManyTest extends TestCase
{
    /**
     * Offline (stub) mode: per-key stub shape BC-consistent with call() —
     * ['_' => 'rpc_result', method, params, dc_id] — keys and order preserved.
     */
    public function testOfflineStubShapePerKey(): void
    {
        $session = new SessionData(dcId: 4, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session);

        $got = $client->callMany([
            'nearest' => ['method' => 'help.getNearestDc', 'params' => []],
            'status' => ['method' => 'account.updateStatus', 'params' => ['offline' => true]],
        ]);

        $this->assertSame(['nearest', 'status'], array_keys($got), 'input keys and order preserved');
        $this->assertSame('rpc_result', $got['nearest']['_']);
        $this->assertSame('help.getNearestDc', $got['nearest']['method']);
        $this->assertSame([], $got['nearest']['params']);
        $this->assertSame('account.updateStatus', $got['status']['method']);
        $this->assertSame(['offline' => true], $got['status']['params']);
        $this->assertSame(4, $got['status']['dc_id'], 'same dc_id the call() stub carries');
    }

    public function testEmptyRequestsReturnEmptyArray(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);

        // Live flag set on purpose: empty input must short-circuit before any
        // handshake attempt (no session-less network path may trigger).
        $this->assertSame([], $client->callMany([]));
    }

    public function testOfflineStubRequiresAuthKeyLikeCall(): void
    {
        $session = new SessionData(dcId: 2, authKey: ''); // empty key: same guard as call()
        $client = new Client(apiId: 1, apiHash: 'h', session: $session);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AuthKey is required');
        $client->callMany(['a' => ['method' => 'help.getNearestDc', 'params' => []]]);
    }

    /**
     * Method names are validated by TLRegistry while building bodies — an
     * unknown constructor throws InvalidArgumentException naming it, before
     * any bytes hit the wire (socket-less pinned connection proves no I/O).
     */
    public function testUnknownMethodThrowsNamingIt(): void
    {
        [$clientSock] = $this->socketPairHalf();
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);
        self::setConn($client, new EncryptedConnection($session, $clientSock));

        try {
            $client->callMany([
                'ok' => ['method' => 'help.getNearestDc', 'params' => []],
                'bad' => ['method' => 'definitely.notARealMethod', 'params' => []],
            ]);
            $this->fail('expected InvalidArgumentException for the unknown method');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('definitely.notARealMethod', $e->getMessage());
        } finally {
            $client->close();
        }
    }

    /**
     * Fresh connection (layer not yet invoked): the FIRST request establishes
     * invokeWithLayer/initConnection through the existing call() path — the
     * "first-call as today" lazy semantics — and the REST ride one container.
     */
    public function testFirstCallManyOnInitiatesLayerThenBatchesTheRest(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock, apiId: 1)));
        self::setConn($client, $conn);
        $pin = $this->pinnedMessageIdBase();

        // 1st RTT: single call() answer for the first request (rpc_result; call() does not check req_msg_id)
        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => self::nearestDcPayload(),
        ]));
        // 2nd RTT: container answer for the batched remainder (inner id pin+8 — call() used pin+4)
        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            [$pin + 8, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 8, 'result' => ['_' => 'boolTrue'],
            ])],
        ]));

        $got = $client->callMany([
            'dc' => ['method' => 'help.getNearestDc', 'params' => []],
            'config' => ['method' => 'help.getConfig', 'params' => []],
        ]);

        $this->assertSame(['dc', 'config'], array_keys($got));
        $this->assertSame(self::nearestDcPayload(), $got['dc']);
        $this->assertSame(['_' => 'boolTrue'], $got['config']);

        // Sent frame 1: the invokeWithLayer+initConnection wrap, exactly like call() today
        $first = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(TLRegistry::id('invokeWithLayer'), unpack('V', substr($first['payload'], 0, 4))[1]);
        $this->assertSame(EncryptedConnection::LAYER, unpack('V', substr($first['payload'], 4, 4))[1], 'layer inside invokeWithLayer');

        // Sent frame 2: a naked msg_container holding only the remaining request
        $second = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(0x73f1f8dc, unpack('V', substr($second['payload'], 0, 4))[1]);
        $inner = self::parseClientNakedContainer($second['payload']);
        $this->assertCount(1, $inner);
        $this->assertSame('help.getConfig', $inner[0]['body']['_']);

        $client->close();
        fclose($serverSock);
    }

    /**
     * Steady state (connection already inited): every request goes through
     * ONE container round-trip, keys preserved, params encoded.
     */
    public function testSteadyStateBatchRoutesAllThroughOneContainer(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock, apiId: 1)));
        (new \ReflectionProperty(EncryptedConnection::class, 'inited'))->setValue($conn, true);
        self::setConn($client, $conn);
        $pin = $this->pinnedMessageIdBase();

        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            [$pin + 4, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 4, 'result' => self::nearestDcPayload(),
            ])],
            [$pin + 8, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 8, 'result' => ['_' => 'boolTrue'],
            ])],
        ]));

        $got = $client->callMany([
            'nearest' => ['method' => 'help.getNearestDc', 'params' => []],
            'ping' => ['method' => 'ping', 'params' => ['ping_id' => 42]],
        ]);

        $this->assertSame(['nearest', 'ping'], array_keys($got));
        $this->assertSame(self::nearestDcPayload(), $got['nearest']);
        $this->assertSame(['_' => 'boolTrue'], $got['ping']);

        // Exactly ONE frame was sent: a container with both bodies, in input order
        $sent = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(0x73f1f8dc, unpack('V', substr($sent['payload'], 0, 4))[1]);
        $inner = self::parseClientNakedContainer($sent['payload']);
        $this->assertCount(2, $inner);
        $this->assertSame('help.getNearestDc', $inner[0]['body']['_']);
        $this->assertSame('ping', $inner[1]['body']['_']);
        $this->assertSame(42, $inner[1]['body']['ping_id'], 'params encoded into the body');

        $client->close();
        fclose($serverSock);
    }

    public function testTransportFailureEvictsCachedConnection(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));
        (new \ReflectionProperty(EncryptedConnection::class, 'inited'))->setValue($conn, true);
        self::setConn($client, $conn);

        fclose($serverSock); // server side vanishes: the next batch hits EOF

        try {
            $client->callMany(['a' => ['method' => 'help.getNearestDc', 'params' => []]]);
            $this->fail('expected RuntimeException on a dead connection');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull(self::connOf($client), 'dead connection must be evicted (close + null)');

        $client->close();
    }

    // ---------------------------------------------------------------------------
    // socketpair fake-DC harness (mirrors EncryptedConnectionTest / ClientLiveModeTest)
    // ---------------------------------------------------------------------------

    private static function nearestDcPayload(): array
    {
        return ['_' => 'nearestDc', 'country' => 'DE', 'this_dc' => 2, 'nearest_dc' => 2];
    }

    /**
     * Base for pinned-message-id tests: within PacketCodec's "not too far in
     * the future" window (id>>32 < time()+300) yet far above any real clock
     * candidate, so nextMessageId() deterministically yields base+4, +8, ...
     */
    private function pinnedMessageIdBase(): int
    {
        return ((time() + 290) << 32) & ~3;
    }

    private function idPinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'lastMessageId'))->setValue($conn, $this->pinnedMessageIdBase());
        return $conn;
    }

    /**
     * Pins the connection's random session_id to the fixed id the seed helper
     * encrypts with (decryptPacket enforces the session_id echo).
     */
    private function pinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'sessionId'))->setValue($conn, 0x5E5510A1);
        return $conn;
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        return $pair;
    }

    /**
     * Single-ended pair for tests that need a connection object but never talk.
     * @return array{0: resource}
     */
    private function socketPairHalf(): array
    {
        return [$this->socketPair()[0]];
    }

    private function seedFakeServerResponse($serverSock, string $authKey, string $payload): void
    {
        fwrite($serverSock, FrameCodec::wrapAbridgedPayload(PacketCodec::encryptPacket(
            payload: $payload,
            authKey: $authKey,
            sessionId: 0x5E5510A1,
            serverSalt: 0x4242,
            seqNo: 1,
            toServer: false
        )));
    }

    /**
     * @return array{server_salt: int, session_id: int, message_id: int, seq_no: int, payload: string}
     */
    private function decryptFakeServerRequest($serverSock, string $authKey): array
    {
        return PacketCodec::decryptPacket(
            FrameCodec::receiveAbridgedMessage($serverSock),
            $authKey,
            fromServer: false
        );
    }

    /**
     * @param list<array{0: int, 1: string}> $entries [msg_id, body] pairs
     */
    private static function nakedResultContainer(array $entries): string
    {
        $bin = pack('V', 0x73f1f8dc) . pack('V', count($entries));
        foreach ($entries as $i => [$msgId, $body]) {
            $bin .= pack('P', $msgId)
                . pack('V', ($i + 1) * 2 - 1)
                . pack('V', strlen($body))
                . $body;
        }
        return $bin;
    }

    /**
     * @return list<array{msg_id: int, seqno: int, body: array<string, mixed>}>
     */
    private static function parseClientNakedContainer(string $payload): array
    {
        $messages = [];
        $offset = 4;
        $count = unpack('V', substr($payload, $offset, 4))[1];
        $offset += 4;
        for ($i = 0; $i < $count; $i++) {
            $msgId = unpack('P', substr($payload, $offset, 8))[1];
            $offset += 8;
            $seqno = unpack('V', substr($payload, $offset, 4))[1];
            $offset += 4;
            $bodyLen = unpack('V', substr($payload, $offset, 4))[1];
            $offset += 4;
            $bodyOffset = 0;
            $messages[] = [
                'msg_id' => $msgId,
                'seqno' => $seqno,
                'body' => TLDecoder::decodeObject(substr($payload, $offset, $bodyLen), $bodyOffset),
            ];
            $offset += $bodyLen;
        }
        return $messages;
    }

    private static function setConn(Client $client, EncryptedConnection $conn): void
    {
        (new \ReflectionProperty(Client::class, 'conn'))->setValue($client, $conn);
    }

    private static function connOf(Client $client): mixed
    {
        return (new \ReflectionProperty(Client::class, 'conn'))->getValue($client);
    }
}
