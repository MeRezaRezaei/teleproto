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
    protected function setUp(): void
    {
        EncryptedConnection::registerPingSchema(); // pong/ping_delay_disconnect must be encodable in seeds
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
        $conn = new EncryptedConnection($session, $clientSock);

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc())); // init the conn first
        $this->assertSame(self::nearestDc(), $conn->call('help.getNearestDc'));
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        $pongPingId = 0x0BADF00D;
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('pong', [
            'msg_id' => 7, 'ping_id' => $pongPingId,
        ])); // real DCs answer with a BARE pong service message, not an rpc_result
        $pong = $conn->ping();
        $this->assertSame('pong', $pong['_']);
        $this->assertSame($pongPingId, $pong['ping_id']); // pong carries the echoed ping_id

        $req = $this->decryptRequest($serverSock, $authKey);
        $this->assertSame(pack('V', 0xf3427b8c), substr($req['payload'], 0, 4)); // ping_delay_disconnect golden id
        $this->assertGreaterThan(0, unpack('P', substr($req['payload'], 4, 8))[1]); // random ping_id:long
        $this->assertSame(50, unpack('V', substr($req['payload'], 12, 4))[1]); // default disconnect_delay:int

        // custom disconnect delay reaches the wire
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
        $conn = new EncryptedConnection($session, $clientSock);
        self::setConn($client, $conn);

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
        $this->assertSame(self::nearestDc(), $client->call('help.getNearestDc')); // conn live + recently active
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        // simulate 60s of silence: the next call must keepalive BEFORE the RPC
        $prop = new \ReflectionProperty(EncryptedConnection::class, 'lastActivity');
        $prop->setValue($conn, microtime(true) - 60.0);
        $this->assertGreaterThan(45.0, $conn->idleSeconds());

        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('pong', ['msg_id' => 7, 'ping_id' => 5]));
        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));

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
        $conn = new EncryptedConnection($session, $clientSock);
        self::setConn($client, $conn);

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
        $client->call('help.getNearestDc');
        $this->decryptRequest($serverSock, $authKey); // drain the invokeWithLayer-wrapped init request

        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));
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
                ($this->onDial)($pair[1]);
                return new EncryptedConnection($session, $pair[0], $this->apiId);
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
        $conn = new EncryptedConnection($session, $clientSock);
        $this->assertSame(0, $conn->getServerSalt());

        $newSalt = 0x7AFEBABEDEADBEE;
        $this->seed($serverSock, $authKey, TLEncoder::encodeObject('bad_server_salt', [
            'bad_msg_id' => 1,
            'bad_msg_seqno' => 0,
            'error_code' => 48,
            'new_server_salt' => $newSalt,
        ]));
        $this->seed($serverSock, $authKey, $this->cannedResult(self::nearestDc()));

        $this->assertSame(self::nearestDc(), $conn->call('help.getNearestDc'));

        $this->assertSame($newSalt, $conn->getServerSalt());
        $this->assertSame($newSalt, $session->serverSalt, 'fresh salt must be written back to the SessionData reference');

        // a connection built on the same session starts from the persisted salt
        [$clientSock2, $serverSock2] = $this->socketPair();
        $conn2 = new EncryptedConnection($session, $clientSock2);
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
        $conn = new EncryptedConnection($session, $clientSock);

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
    private function cannedResult(array $result): string
    {
        return TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
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
