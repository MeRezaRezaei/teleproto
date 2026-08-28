<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Client;
use MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use PHPUnit\Framework\TestCase;

class ClientLiveModeTest extends TestCase
{
    public function testOfflineStubUnchangedByDefault(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session);
        $res = $client->call('help.getNearestDc');
        $this->assertSame('rpc_result', $res['_']);
        $this->assertSame('help.getNearestDc', $res['method']);
    }

    public function testResolveLiveDefaultIsFalseWithoutLaravelContainer(): void
    {
        // config() only exists inside a Laravel app; the dev/test runtime here
        // has no container, so the config-if-available default resolves false.
        $client = new Client(apiId: 1, apiHash: 'h');

        $resolve = new \ReflectionMethod(Client::class, 'resolveLiveDefault');
        $this->assertFalse($resolve->invoke($client));
    }

    public function testConstructorNullLiveWithNoConfigStaysOffline(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: null);

        $res = $client->call('help.getNearestDc'); // must be the stub, not a socket
        $this->assertSame('rpc_result', $res['_']);
    }

    public function testConstructorTrueForcesLiveWirePath(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = new Client(apiId: 1, apiHash: 'h', session: $session, live: true);
        self::setConn($client, self::pinnedConn(new EncryptedConnection($session, $clientSock)));
        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => ['_' => 'nearestDc', 'country' => 'DE', 'this_dc' => 2, 'nearest_dc' => 2],
        ]));

        $res = $client->call('help.getNearestDc'); // live flag must use the wire, not the stub
        $this->assertSame('nearestDc', $res['_']);
        $this->assertSame(2, $res['this_dc']);

        $client->close();
        fclose($serverSock);
    }

    public function testLiveRequiresAuthKeyOrFailsFast(): void
    {
        $session = new SessionData(dcId: 2, authKey: ''); // empty key forces handshake attempt
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        // Connecting to a dead port must fail fast with our RuntimeException,
        // proving the live path really attempts the network (not the stub).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('127.0.0.1:1');
        $client->callToHost('127.0.0.1', 1); // port 1: connection refused
    }

    public function testFailedCallToHostDoesNotCacheHalfState(): void
    {
        $session = new SessionData(dcId: 2, authKey: '');
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();

        // First attempt: dead port -> RuntimeException with host:port context.
        try {
            $client->callToHost('127.0.0.1', 1);
            $this->fail('callToHost against a dead port must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }

        // Second attempt must start fresh: it fails the same way (a cached
        // half-state would either short-circuit or produce a different error).
        try {
            $client->callToHost('127.0.0.1', 1);
            $this->fail('second callToHost must also throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }

        $this->assertNull(self::connOf($client), 'no connection may be cached after failure');
        $this->assertSame('', $session->authKey, 'failed handshake must not store a partial key');
    }

    public function testLiveRuntimeExceptionEvictsCachedConnection(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();
        self::setConn($client, self::pinnedConn(new EncryptedConnection($session, $clientSock)));

        fclose($serverSock); // server side vanishes: the next call hits EOF

        try {
            $client->call('help.getNearestDc');
            $this->fail('expected RuntimeException on a dead connection');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(TelegramException::class, $e); // transport-level, not RPC
        }

        $this->assertNull(self::connOf($client), 'dead connection must be evicted (close + null)');
    }

    public function testLiveTelegramExceptionKeepsCachedConnection(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $client = (new Client(apiId: 1, apiHash: 'h', session: $session))->live();
        self::setConn($client, self::pinnedConn(new EncryptedConnection($session, $clientSock)));

        // rpc_error-encrypted canned response (same helper pattern as EncryptedConnectionTest)
        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => ['_' => 'rpc_error', 'error_code' => 420, 'error_message' => 'SLOWMODE_WAIT_10'],
        ]));

        try {
            $client->call('help.getNearestDc');
            $this->fail('expected TelegramException on rpc_error');
        } catch (TelegramException $e) {
            $this->assertSame(420, $e->getCode());
        }

        $this->assertInstanceOf(EncryptedConnection::class, self::connOf($client), 'RPC-level error must keep the connection');

        $client->close();
        fclose($serverSock);
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
     * Fake telegram pre-buffers one encrypted (server->client) framed response
     * so the single-threaded call() write+read cannot deadlock.
     */
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
     * Pins the connection's random session_id to the fixed id the seed helper
     * encrypts with (decryptPacket enforces the session_id echo).
     */
    private static function pinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'sessionId'))->setValue($conn, 0x5E5510A1);
        return $conn;
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
