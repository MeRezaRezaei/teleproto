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

    // ---------------------------------------------------------------------------
    // callBatch: naked msg_container encode + receive demux (W2 T1)
    // ---------------------------------------------------------------------------

    /**
     * Two request bodies, one round-trip: ids are pinned (base+4/+8) so the
     * canned container — ONE frame with both rpc_results keyed to those inner
     * msg_ids — can be pre-seeded before the blocking call runs; afterwards
     * parseClientNakedContainer proves the client really sent those ids.
     */
    public function testBatchOfTwoReturnsBothResultsKeyedCorrectly(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock, apiId: 12345)));
        $pin = $this->pinnedMessageIdBase();

        $canned = [
            'nearest' => self::nearestDcPayload(),
            'config' => ['_' => 'boolTrue'], // any registered response constructor
        ];
        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            [$pin + 4, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 4, 'result' => $canned['nearest'],
            ])],
            [$pin + 8, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 8, 'result' => $canned['config'],
            ])],
        ]));

        $got = $conn->callBatch([
            'nearest' => TLEncoder::encodeObject('help.getNearestDc', []),
            'config' => TLEncoder::encodeObject('help.getConfig', []),
        ]);
        $this->assertSame(['nearest', 'config'], array_keys($got), 'input key order preserved');
        $this->assertSame($canned['nearest'], $got['nearest']);
        $this->assertSame($canned['config'], $got['config']);

        // The sent container must be well-formed naked encoding with correct
        // inner msg_id/seqno conventions.
        $sent = $this->decryptFakeServerRequest($serverSock, $authKey);
        $this->assertSame(0x73f1f8dc, unpack('V', substr($sent['payload'], 0, 4))[1]);
        $inner = self::parseClientNakedContainer($sent['payload']);
        $this->assertCount(2, $inner);
        $this->assertSame('help.getNearestDc', $inner[0]['body']['_']);
        $this->assertSame('help.getConfig', $inner[1]['body']['_']);
        $this->assertSame($pin + 4, $inner[0]['msg_id']);
        $this->assertSame($pin + 8, $inner[1]['msg_id']);
        $this->assertSame(1, $inner[0]['seqno']);
        $this->assertSame(3, $inner[1]['seqno']);
        $this->assertSame(0, $inner[0]['msg_id'] % 4);
        $this->assertSame(0, $inner[1]['msg_id'] % 4);
        $this->assertGreaterThan($inner[0]['msg_id'], $inner[1]['msg_id']);
        // outer envelope id strictly greater than every inner id
        $this->assertGreaterThan($inner[1]['msg_id'], $sent['message_id']);
        // container envelope = non-content-related message: EVEN seq_no above every
        // inner seq, content counter not consumed (live DC4 enforces this: code 34)
        $this->assertSame(4, $sent['seq_no']);

        $conn->close();
        fclose($serverSock);
    }

    public function testSingleEntryBatchWorks(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));
        $pin = $this->pinnedMessageIdBase();

        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            [$pin + 4, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 4, 'result' => self::nearestDcPayload(),
            ])],
        ]));

        $got = $conn->callBatch(['only' => TLEncoder::encodeObject('help.getNearestDc', [])]);
        $this->assertSame(['only' => self::nearestDcPayload()], $got);

        $conn->close();
        fclose($serverSock);
    }

    public function testBatchRpcErrorResolvesWithThatKeysMethodContext(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));
        $pin = $this->pinnedMessageIdBase();

        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            // first body errors; second would succeed but must never surface
            [$pin + 4, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 4,
                'result' => ['_' => 'rpc_error', 'error_code' => 400, 'error_message' => 'QUERY_TOO_LOUD'],
            ])],
            [$pin + 8, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 8, 'result' => self::nearestDcPayload(),
            ])],
        ]));

        try {
            $conn->callBatch([
                'boom' => TLEncoder::encodeObject('help.getConfig', []),
                'ok' => TLEncoder::encodeObject('help.getNearestDc', []),
            ]);
            $this->fail('expected TelegramException on batch rpc_error');
        } catch (TelegramException $e) {
            // resolver exception carries the FAILING key's method (help.getConfig), not its neighbor's
            $this->assertStringContainsString('QUERY_TOO_LOUD', $e->getMessage());
            $this->assertStringContainsString('help.getConfig', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }

        $conn->close();
        fclose($serverSock);
    }

    public function testBatchResendsWholeContainerOnceAfterBadServerSalt(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->idPinnedConn($this->pinnedConn(new EncryptedConnection($session, $clientSock)));

        // Pinning makes the resend's fresh ids deterministic: attempt 1 uses
        // pin+4/+8, the bad_server_salt resend pin+16/+20 — so the honest
        // answer for the RESEND can be pre-seeded (FIFO after the salt error).
        $pin = $this->pinnedMessageIdBase();
        $newSalt = 0xCAFEBABE;
        $this->seedFakeServerResponse($serverSock, $authKey, TLEncoder::encodeObject('bad_server_salt', [
            'bad_msg_id' => 1,
            'bad_msg_seqno' => 0,
            'error_code' => 48,
            'new_server_salt' => $newSalt,
        ]));
        $this->seedFakeServerResponse($serverSock, $authKey, self::nakedResultContainer([
            [$pin + 16, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 16, 'result' => self::nearestDcPayload(),
            ])],
            [$pin + 20, TLEncoder::encodeObject('rpc_result', [
                'req_msg_id' => $pin + 20, 'result' => ['_' => 'boolTrue'],
            ])],
        ]));

        $got = $conn->callBatch([
            'a' => TLEncoder::encodeObject('help.getNearestDc', []),
            'b' => TLEncoder::encodeObject('help.getConfig', []),
        ]);
        $this->assertSame(self::nearestDcPayload(), $got['a']);
        $this->assertSame(['_' => 'boolTrue'], $got['b']);
        $this->assertSame($newSalt, $conn->getServerSalt());

        $first = $this->decryptFakeServerRequest($serverSock, $authKey);
        $second = $this->decryptFakeServerRequest($serverSock, $authKey);
        // both attempts carry a well-formed container; the resend uses the fresh
        // salt and fresh (strictly increasing) outer + inner ids
        $this->assertSame(0x73f1f8dc, unpack('V', substr($first['payload'], 0, 4))[1]);
        $this->assertSame(0x73f1f8dc, unpack('V', substr($second['payload'], 0, 4))[1]);
        $this->assertSame(0, $first['server_salt']);
        $this->assertSame($newSalt, $second['server_salt']);
        $this->assertGreaterThan($first['message_id'], $second['message_id']);
        $firstInner = self::parseClientNakedContainer($first['payload']);
        $secondInner = self::parseClientNakedContainer($second['payload']);
        $this->assertSame([$pin + 4, $pin + 8], [$firstInner[0]['msg_id'], $firstInner[1]['msg_id']]);
        $this->assertSame([$pin + 16, $pin + 20], [$secondInner[0]['msg_id'], $secondInner[1]['msg_id']]);
        $this->assertGreaterThan($firstInner[1]['msg_id'], $secondInner[0]['msg_id'], 'fresh inner ids on resend');

        $conn->close();
        fclose($serverSock);
    }

    public function testBatchOverMessageLimitThrowsBeforeSending(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $conn = new EncryptedConnection($session); // deliberately socket-less

        $bodies = array_fill(0, EncryptedConnection::MAX_BATCH_MESSAGES + 1, TLEncoder::encodeObject('help.getConfig', []));
        try {
            $conn->callBatch($bodies);
            $this->fail('expected RuntimeException above the container message limit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) EncryptedConnection::MAX_BATCH_MESSAGES, $e->getMessage());
        }
    }

    public function testEmptyBatchReturnsEmptyArrayWithoutSending(): void
    {
        $session = new SessionData(dcId: 2, authKey: random_bytes(256));
        $conn = new EncryptedConnection($session); // socket-less: must not even look at it

        $this->assertSame([], $conn->callBatch([]));
    }

    public function testBatchThrowsAfterPoisonFrameCap(): void
    {
        [$clientSock, $serverSock] = $this->socketPair();
        $authKey = random_bytes(256);
        $session = new SessionData(dcId: 2, authKey: $authKey);
        $conn = $this->pinnedConn(new EncryptedConnection($session, $clientSock));

        $msgsAck = pack('V', TLRegistry::id('msgs_ack')) . TLSerializer::packVector([], TLSerializer::packLong(...));
        $cap = 2 * 3 + 10;
        for ($i = 0; $i < $cap; $i++) {
            $this->seedFakeServerResponse($serverSock, $authKey, $msgsAck);
        }

        try {
            $conn->callBatch([
                'a' => TLEncoder::encodeObject('help.getNearestDc', []),
                'b' => TLEncoder::encodeObject('help.getConfig', []),
            ]);
            $this->fail('expected RuntimeException after poison frame cap');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exceeded', $e->getMessage());
        }

        $conn->close();
        fclose($serverSock);
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
     * Pins lastMessageId (same reflection seam style as pinnedConn) so both
     * the original batch and its bad_server_salt resend use predictable ids.
     */
    private function idPinnedConn(EncryptedConnection $conn): EncryptedConnection
    {
        (new \ReflectionProperty(EncryptedConnection::class, 'lastMessageId'))->setValue($conn, $this->pinnedMessageIdBase());
        return $conn;
    }

    /**
     * Builds a server -> client naked msg_container whose entries carry
     * server-style msg_ids (odd — from the server's clock ≢ ours).
     *
     * @param list<array{0: int, 1: string}> $entries [msg_id, body] pairs
     */
    private static function nakedResultContainer(array $entries): string
    {
        $bin = pack('V', 0x73f1f8dc) . pack('V', count($entries));
        foreach ($entries as $i => [$msgId, $body]) {
            $bin .= pack('P', $msgId)          // msg_id
                . pack('V', ($i + 1) * 2 - 1)  // seqno (odd — server content)
                . pack('V', strlen($body))
                . $body;
        }
        return $bin;
    }

    /**
     * Test mirror of parseNakedContainer over a PacketCodec-decrypted payload:
     * splits naked container tuples into decoded per-message bodies.
     *
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
