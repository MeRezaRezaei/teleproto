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
use RuntimeException;

/**
 * Audit fix #1: ping keepalive, reconnect-after-eviction and server-salt
 * persistence for long-lived MTProto connections.
 */
class KeepaliveTest extends TestCase
{
    /** session_id the fake-server seed() helper encrypts with; decryptPacket enforces the echo. */
    private const SEED_SESSION_ID = 0x5E5510A1;

    /**
     * Pins the connection's random session_id to the fixed id seed() uses, so
     * the enforced session_id check passes for pre-seeded canned frames.
     */
    private function pinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'sessionId'))->setValue($conn, self::SEED_SESSION_ID);
        return $conn;
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

    /**
     * Pins lastMessageId on top of pinnedConn so the req_msg_id values the
     * canned rpc_results echo are deterministic (call() demuxes by req_msg_id).
     */
    private function idPinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'lastMessageId'))->setValue($conn, $this->pinnedMessageIdBase());
        return $conn;
    }

    /**
     * @return array<string, mixed>
     */
    private static function nearestDc(): array
    {
        return ['_' => 'nearestDc', 'country' => 'DE', 'this_dc' => 2, 'nearest_dc' => 2];
    }

    public function testPingRoundTripSendsPingDelayDisconnectAndReturnsPong(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new SeededPingIdConnection($session, $clientSock)));

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc())); // init the conn first
        $this->assertSame(self::nearestDc(), $conn->call('help.getNearestDc'));
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        $pongPingId = 0x0BADF00D;
        SeededPingIdConnection::$pingId = $pongPingId; // pong must echo this exact id (validated by ping())
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('pong', [
            'msg_id' => 7, 'ping_id' => $pongPingId,
        ])); // real DCs answer with a BARE pong service message, not an rpc_result
        $pong = $conn->ping();
        $this->assertSame('pong', $pong['_']);
        $this->assertSame($pongPingId, $pong['ping_id']); // pong carries the echoed ping_id

        $req = $this->decryptRequest($serverSock, $authKey);
        $this->assertSame(pack('V', 0xf3427b8c), substr($req['payload'], 0, 4)); // ping_delay_disconnect golden id
        $this->assertSame($pongPingId, unpack('P', substr($req['payload'], 4, 8))[1]); // threaded ping_id:long
        $this->assertSame(50, unpack('V', substr($req['payload'], 12, 4))[1]); // default disconnect_delay:int

        // custom disconnect delay reaches the wire
        SeededPingIdConnection::$pingId = 1;
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('pong', [
            'msg_id' => 8, 'ping_id' => 1,
        ]));
        $conn->ping(33);
        $req2 = $this->decryptRequest($serverSock, $authKey);
        $offset2 = 12;
        $this->assertSame(33, unpack('V', substr($req2['payload'], $offset2, 4))[1]);

        $conn->close();
        fclose($serverSock);
    }

    public function testIdleConnectionIsPingedBeforeNextRpc(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session, live: true));
        $conn = $this->idPinnedConn($this->pinnedConn(new SeededPingIdConnection($session, $clientSock)));
        self::setConn($client, $conn);

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
        $this->assertSame(self::nearestDc(), $client->call('help.getNearestDc')); // conn live + recently active
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        // simulate 60s of silence: the next call must keepalive BEFORE the RPC
        $prop = new \ReflectionProperty(EncryptedConnection::class, 'lastActivity');
        $prop->setValue($conn, microtime(true) - 60.0);
        $this->assertGreaterThan(45.0, $conn->idleSeconds());

        SeededPingIdConnection::$pingId = 5; // lazy keepalive ping must receive an echoing pong
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('pong', ['msg_id' => 7, 'ping_id' => 5]));
        // ids: init pin+4, keepalive ping pin+8 (answered by the bare pong),
        // so the getConfig call itself is pin+12
        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc(), $this->pinnedMessageIdBase() + 12));

        $this->assertSame(self::nearestDc(), $client->call('help.getConfig'));

        $first = $this->decryptRequest($serverSock, $authKey);
        $this->assertSame(pack('V', 0xf3427b8c), substr($first['payload'], 0, 4), 'idle > 45s must ping first');
        $second = $this->decryptRequest($serverSock, $authKey);
        $offset = 0;
        $decoded = TLDecoder::decodeObject($second['payload'], $offset);
        $this->assertSame('help.getConfig', $decoded['_']);
        $this->assertLessThan(45.0, $conn->idleSeconds()); // ping + rpc refreshed the activity clock

        $client->close();
        fclose($serverSock);
    }

    public function testRecentlyActiveConnectionSkipsKeepalivePing(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session, live: true));
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));
        self::setConn($client, $conn);

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
        $client->call('help.getNearestDc');
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc(), $this->pinnedMessageIdBase() + 8));
        $this->assertSame(self::nearestDc(), $client->call('help.getConfig'));

        // exactly one request for the second call, and it is the bare RPC (no ping)
        $req = $this->decryptRequest($serverSock, $authKey);
        $this->assertNotSame(pack('V', 0xf3427b8c), substr($req['payload'], 0, 4));
        $offset = 0;
        $this->assertSame('help.getConfig', TLDecoder::decodeObject($req['payload'], $offset)['_']);

        $client->close();
        fclose($serverSock);
    }

    public function testSecondCallAfterDeadSocketFailureReconnectsFresh(): void
    {
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $serverEnds = [];
        $dials = 0;

        $seedAndTrack = function ($serverSock) use ($authKey, &$serverEnds, &$dials): void {
            $serverEnds[] = $serverSock;
            $dials++;
            $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
        };

        $client = new class ($session, $seedAndTrack) extends Client {
            /** @var list<resource> */
            private array $serverEnds = [];
            private int $dials = 0;

            public function __construct(SessionData $session, private \Closure $onDial)
            {
                parent::__construct(apiId: 1, apiHash: 'h', session: $session, live: true);
            }

            protected function connectEncrypted(SessionData $session, string $host, int $port, $promotedSocket = null): EncryptedConnection
            {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                stream_set_timeout($pair[0], 5);
                stream_set_timeout($pair[1], 5);
                ($this->onDial)($pair[1]);
                $conn = new EncryptedConnection($session, $pair[0], $this->apiId);
                // canned frames are seeded with the fixed session id; pin the echo
                (new \ReflectionProperty(EncryptedConnection::class, 'sessionId'))->setValue($conn, 0x5E5510A1);
                // deterministic msg ids: canned results echo pin+4 (each fresh
                // connection's first successful call)
                (new \ReflectionProperty(EncryptedConnection::class, 'lastMessageId'))->setValue($conn, ((time() + 290) << 32) & ~3);
                return $conn;
            }
        };

        // first call: dials once, succeeds
        $this->assertSame(self::nearestDc(), $client->call('help.getNearestDc'));
        $this->assertSame(1, $dials);

        // kill the socket the conn lives on: the next call hits EOF and must evict
        fclose($serverEnds[0]);
        try {
            $client->call('help.getNearestDc');
            $this->fail('dead socket must surface a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('StreamSocket', $e->getMessage()); // write failed / EOF while reading
        }
        $this->assertNull(self::connOf($client), 'dead connection must be evicted');

        // second call after the failure: ensureConnection() redials fresh and succeeds
        $this->assertSame(self::nearestDc(), $client->call('help.getNearestDc'));
        $this->assertSame(2, $dials, 'a brand-new connection must have been dialed');

        $client->close();
        fclose($serverEnds[1]);
    }

    public function testBadServerSaltPersistsIntoSessionDataAndNextConnection(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));
        $this->assertSame(0, $conn->getServerSalt());

        $newSalt = 0x7AFEBABEDEADBEE;
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('bad_server_salt', [
            'bad_msg_id' => 1,
            'bad_msg_seqno' => 0,
            'error_code' => 48,
            'new_server_salt' => $newSalt,
        ]));
        // bad_server_salt resend gets a fresh msg id (pin+8) — echo it
        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc(), $this->pinnedMessageIdBase() + 8));

        $this->assertSame(self::nearestDc(), $conn->call('help.getNearestDc'));

        $this->assertSame($newSalt, $conn->getServerSalt());
        $this->assertSame($newSalt, $session->serverSalt, 'fresh salt must be written back to the SessionData reference');

        // a connection built on the same session starts from the persisted salt
        [$clientSock2, $serverSock2] = $this->socketPair();
        $conn2 = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock2)));
        $this->assertSame($newSalt, $conn2->getServerSalt());
        $this->seed($serverSock2, $authKey, $this->cannedResult(self::nearestDc()));
        $this->assertSame(self::nearestDc(), $conn2->call('help.getNearestDc'));
        $req = $this->decryptRequest($serverSock2, $authKey);
        $this->assertSame($newSalt, $req['server_salt'], 'redialed connection must encrypt with the persisted salt');

        $conn->close();
        $conn2->close();
        fclose($serverSock);
        fclose($serverSock2);
    }

    public function testBareNewSessionCreatedPushRefreshesPersistedSalt(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));

        $pushSalt = 0x7234567890ABCD;
        $this->seed($serverSock, $authKey,
            pack('V', TLRegistry::id('new_session_created')) . pack('P', 1) . pack('P', 2) . pack('P', $pushSalt));
        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));

        $this->assertSame(self::nearestDc(), $conn->call('help.getNearestDc'));
        $this->assertSame($pushSalt, $conn->getServerSalt());
        $this->assertSame($pushSalt, $session->serverSalt);

        $conn->close();
        fclose($serverSock);
    }

    public function testSessionStringCarriesServerSaltAndLegacyFourFieldStringsStillImport(): void
    {
        $salt = 0x7F00DBA11C0;
        $session = new SessionData(dcId: 2, authKey: random_bytes(256), serverTimeDelta: -15, userId: 987, serverSalt: $salt);

        $imported = SessionData::importString($session->exportString());
        $this->assertSame($salt, $imported->serverSalt);
        $this->assertSame(-15, $imported->serverTimeDelta);
        $this->assertSame(987, $imported->userId);

        // legacy 4-field export (pre-salt sessions) keeps importing with salt 0
        $legacy = base64_encode(implode(':', [2, base64_encode($session->authKey), 987, -15]));
        $legacyImported = SessionData::importString($legacy);
        $this->assertSame(2, $legacyImported->dcId);
        $this->assertSame($session->authKey, $legacyImported->authKey);
        $this->assertSame(-15, $legacyImported->serverTimeDelta);
        $this->assertSame(987, $legacyImported->userId);
        $this->assertSame(0, $legacyImported->serverSalt);
    }

    public function testArrayFormsCarryServerSalt(): void
    {
        $salt = 0x5A17 & 0xFFFF;
        $session = new SessionData(dcId: 2, authKey: random_bytes(256), serverSalt: $salt);
        $this->assertSame($salt, SessionData::fromArray($session->toArray())->serverSalt);
        $this->assertSame(0, SessionData::fromArray(['dc_id' => 2, 'auth_key' => base64_encode(random_bytes(256))])->serverSalt);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        stream_set_timeout($pair[0], 5);
        stream_set_timeout($pair[1], 5);
        return $pair;
    }

    private function seed($serverSock, string $authKey, string $payload): void
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
    private function decryptRequest($serverSock, string $authKey): array
    {
        return PacketCodec::decryptPacket(
            FrameCodec::receiveAbridgedMessage($serverSock),
            $authKey,
            fromServer: false
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function cannedResult(array $result, ?int $reqMsgId = null): string
    {
        return TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => $reqMsgId ?? $this->pinnedMessageIdBase() + 4, // first id an id-pinned conn sends
            'result' => $result,
        ]);
    }

    private static function setConn(Client $client, EncryptedConnection $conn): void
    {
        $prop = new \ReflectionProperty(Client::class, 'conn');
        $prop->setValue($client, $conn);
    }

    private static function connOf(Client $client): mixed
    {
        $prop = new \ReflectionProperty(Client::class, 'conn');
        return $prop->getValue($client);
    }
}

/**
 * Deterministic ping_id seam: pongs must be pre-seeded into the socket before
 * the blocking ping() runs, so the id it will send has to be known up front.
 */
class SeededPingIdConnection extends EncryptedConnection
{
    public static int $pingId = 1;

    protected function newPingId(): int
    {
        return self::$pingId;
    }
}
