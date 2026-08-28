<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLRegistry;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EncryptedConnectionTest extends TestCase
{
    /**
     * A real help.getNearestDc response payload (nearestDc#8e1a1775 is
     * registered in TLRegistry) — canned results must be encodable AND
     * decodable response constructors, never request names.
     *
     * @return array<string, mixed>
     */
    private static function nearestDcPayload(): array
    {
        return ['_' => 'nearestDc', 'country' => 'DE', 'this_dc' => 2, 'nearest_dc' => 2];
    }

    /**
     * invokeWithLayer#da9b0d0d {X:Type} layer:int query:!X = X
     * followed by layer 227 and initConnection#... with the inner query.
     */
    public function testFirstQueryIsWrappedInInvokeWithLayerAndInitConnection(): void
    {
        $body = EncryptedConnection::buildFirstQueryBody(227, [
            'api_id' => 12345,
            'device_model' => 'Teleproto',
            'system_version' => PHP_VERSION,
            'app_version' => '1.0.0',
            'system_lang_code' => 'en',
            'lang_pack' => '',
            'lang_code' => 'en',
            'query' => ['_' => 'help.getNearestDc'],
        ]);

        $this->assertSame(pack('V', 0xda9b0d0d), substr($body, 0, 4)); // invokeWithLayer golden
        $this->assertSame(227, unpack('V', substr($body, 4, 4))[1]);    // layer

        // The generic wrapper is fully decodable: layer + nested initConnection + query.
        $offset = 0;
        $decoded = TLDecoder::decodeObject($body, $offset);
        $this->assertSame('invokeWithLayer', $decoded['_']);
        $this->assertSame(227, $decoded['layer']);
        $this->assertSame('initConnection', $decoded['query']['_']);
        $this->assertSame(12345, $decoded['query']['api_id']);
        $this->assertSame('Teleproto', $decoded['query']['device_model']);
        $this->assertSame(0, $decoded['query']['flags']);
        $this->assertSame(['_' => 'help.getNearestDc'], $decoded['query']['query']);
    }

    public function testUnwrapResultIfGzippedInflatesAndDecodesPackedData(): void
    {
        $inner = TLEncoder::encodeObject('rpc_error', ['error_code' => 420, 'error_message' => 'TEST_GZIP']);
        $bin = TLEncoder::encodeObject('gzip_packed', ['packed_data' => gzencode($inner, 9)]);

        $decoded = TLDecoder::decodeObject($bin); // what rpc_result decoding hands us
        $this->assertSame('gzip_packed', $decoded['_']);

        $result = EncryptedConnection::unwrapResultIfGzipped($decoded);
        $this->assertSame('rpc_error', $result['_']);
        $this->assertSame(420, $result['error_code']);
        $this->assertSame('TEST_GZIP', $result['error_message']);
    }

    public function testUnwrapResultIfGzippedReturnsPlainResultAsIs(): void
    {
        $plain = self::nearestDcPayload();
        $this->assertSame($plain, EncryptedConnection::unwrapResultIfGzipped($plain));
    }

    /**
     * Full offline round-trip over a socket pair: the fake server pre-seeds an
     * encrypted canned rpc_result (same auth key, server->client direction),
     * the client call() must encrypt a proper invokeWithLayer request which
     * the test then decrypts back and inspects.
     */
    public function testCallRoundTripOverSocketPair(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock, apiId: 12345));

        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 0x1122334455667788,
            'result' => self::nearestDcPayload(),
        ]));

        $result = $conn->call('help.getNearestDc');
        $this->assertSame(self::nearestDcPayload(), $result);

        $req = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(pack('V', 0xda9b0d0d), substr($req['payload'], 0, 4));
        $this->assertSame(1, $req['seq_no']);           // first content message: odd seq_no (2n+1)
        $this->assertSame(0, $req['message_id'] % 4);   // client msg_id ≡ 0 (mod 4) per MadelineProto/TDLib
        $this->assertGreaterThan(0, $req['message_id']);

        $offset = 0;
        $decoded = TLDecoder::decodeObject($req['payload'], $offset);
        $this->assertSame(227, $decoded['layer']);
        $this->assertSame(12345, $decoded['query']['api_id']);
        $this->assertSame(['_' => 'help.getNearestDc'], $decoded['query']['query']);

        $conn->close();
        fclose($serverSock);
    }

    public function testCallRetriesWithNewServerSaltAfterBadServerSalt(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $newSalt = 0xCAFEBABE;
        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('bad_server_salt', [
            'bad_msg_id' => 1,
            'bad_msg_seqno' => 0,
            'error_code' => 48,
            'new_server_salt' => $newSalt,
        ]));
        $this->seedFakeServerResponse($serverSock, $authKey, $this->cannedNearestDcResult());

        $this->assertSame(self::nearestDcPayload(), $conn->call('help.getNearestDc'));

        $first = $this->decryptFakeServerRequest($serverSock, $authKey);
        $second = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(0, $first['server_salt']);
        $this->assertSame($newSalt, $second['server_salt']);
        $this->assertSame(0, $first['message_id'] % 4);
        $this->assertSame(0, $second['message_id'] % 4);
        $this->assertGreaterThan($first['message_id'], $second['message_id']); // strictly increasing

        $conn->close();
        fclose($serverSock);
    }

    public function testCallThrowsTelegramExceptionOnRpcError(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => ['_' => 'rpc_error', 'error_code' => 420, 'error_message' => 'SLOWMODE_WAIT_10'],
        ]));

        try {
            $conn->call('help.getNearestDc');
            $this->fail('expected TelegramException on rpc_error');
        } catch (TelegramException $e) {
            $this->assertSame(420, $e->getCode());
            $this->assertStringContainsString('SLOWMODE_WAIT_10', $e->getMessage());
        }

        $conn->close();
        fclose($serverSock);
    }

    public function testCallInflatesGzipPackedRpcResult(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => ['_' => 'gzip_packed', 'packed_data' => gzencode(
                TLEncoder::encodeObject('nearestDc', self::nearestDcPayload()), 9
            )],
        ]));

        $this->assertSame(self::nearestDcPayload(), $conn->call('help.getNearestDc'));

        $conn->close();
        fclose($serverSock);
    }

    public function testCallSkipsTransientNewSessionCreatedAndMsgsAckFrames(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        // new_session_created first_msg_id:long unique_id:long server_salt:long
        $this->seedFakeServerResponse($serverSock, $authKey,
            pack('V', TLRegistry::id('new_session_created')) . pack('P', 1) . pack('P', 2) . pack('P', 3));
        // msgs_ack msg_ids:Vector<long>
        $this->seedFakeServerResponse($serverSock, $authKey,
            pack('V', TLRegistry::id('msgs_ack')) . TLSerializer::packVector([111, 222], TLSerializer::packLong(...)));
        $this->seedFakeServerResponse($serverSock, $authKey, $this->cannedNearestDcResult());

        $this->assertSame(self::nearestDcPayload(), $conn->call('help.getNearestDc'));

        $conn->close();
        fclose($serverSock);
    }

    public function testCallThrowsAfterTooManyConsecutiveTransientMessages(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $msgsAck = pack('V', TLRegistry::id('msgs_ack')) . TLSerializer::packVector([], TLSerializer::packLong(...));
        for ($i = 0; $i < 4; $i++) {
            $this->seedFakeServerResponse($serverSock, $authKey, $msgsAck);
        }

        try {
            $conn->call('help.getNearestDc');
            $this->fail('expected RuntimeException after transient message limit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('transient', $e->getMessage());
        }

        $conn->close();
        fclose($serverSock);
    }

    public function testCallWithoutSocketThrows(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $conn = new EncryptedConnection($session);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not connected');
        $conn->call('help.getNearestDc');
    }

    public function testSecondCallUsesBareConstructorWithoutWrapper(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $this->seedFakeServerResponse($serverSock, $authKey, $this->cannedNearestDcResult());
        $this->seedFakeServerResponse($serverSock, $authKey, $this->cannedNearestDcResult());

        $conn->call('help.getNearestDc'); // first: invokeWithLayer-wrapped
        $conn->call('help.getConfig');    // second: bare constructor

        $this->decryptFakeServerRequest($serverSock, $authKey); // discard first (wrapped)
        $second = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertNotSame(pack('V', 0xda9b0d0d), substr($second['payload'], 0, 4));
        $offset = 0;
        $decoded = TLDecoder::decodeObject($second['payload'], $offset);
        $this->assertSame('help.getConfig', $decoded['_']);

        $conn->close();
        fclose($serverSock);
    }

    public function testCloseIsIdempotent(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $conn->close();
        $conn->close();
        $this->addToAssertionCount(1);

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
     * Pins the connection's random session_id to the fixed id the seed helper
     * encrypts with (decryptPacket enforces the session_id echo).
     */
    private function pinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'sessionId'))->setValue($conn, 0x5E5510A1);
        return $conn;
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

    private function cannedNearestDcResult(): string
    {
        return TLEncoder::encodeObject('rpc_result', [
            'req_msg_id' => 7,
            'result' => self::nearestDcPayload(),
        ]);
    }
}
