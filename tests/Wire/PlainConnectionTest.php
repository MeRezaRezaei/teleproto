<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PlainConnectionTest extends TestCase
{
    /**
     * Official sample handshake (core.telegram.org/mtproto/samples-auth_key),
     * first request on the wire (transport header stripped):
     * 0000000000000000 (auth_key_id) 78F404006170466A (message_id)
     * 14000000 (length=20) F18E7EBE (req_pq_multi) nonce.
     */
    public function testBuildPlainEnvelopeMatchesOfficialTranscriptRequest(): void
    {
        $nonce = hex2bin('51A1143FC7A3666BE4BE54D6890A02DC');
        $payload = TLEncoder::encodeObject('req_pq_multi', ['nonce' => $nonce]);
        $transcriptMsgId = unpack('P', hex2bin('78F404006170466A'))[1];

        $envelope = PlainConnection::buildPlainEnvelope($payload, (int)$transcriptMsgId);

        $this->assertSame(
            '0000000000000000'
                . '78f404006170466a'
                . '14000000'
                . 'f18e7ebe'
                . '51a1143fc7a3666be4be54d6890a02dc',
            bin2hex($envelope)
        );
    }

    public function testParsePlainEnvelopeReturnsBodyAndRejectsMalformedFrames(): void
    {
        $body = TLEncoder::encodeObject('req_pq_multi', ['nonce' => str_repeat("\x42", 16)]);
        $frame = PlainConnection::buildPlainEnvelope($body, 0x6A4670610004F478);
        $this->assertSame($body, PlainConnection::parsePlainEnvelope($frame));

        $badKeyId = "\x01" . str_repeat("\x00", 7) . substr($frame, 8);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('auth_key_id');
        PlainConnection::parsePlainEnvelope($badKeyId);
    }

    public function testParsePlainEnvelopeRejectsLengthMismatchAndOversize(): void
    {
        $body = 'abcd';
        $frame = PlainConnection::buildPlainEnvelope($body, 0x6A4670610004F478);

        try {
            PlainConnection::parsePlainEnvelope(substr($frame, 0, 21)); // body shorter than length
            $this->fail('expected RuntimeException for length mismatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('length', $e->getMessage());
        }

        try {
            $oversize = str_repeat("\x00", 8) . pack('P', 1) . pack('V', 3 * 1024 * 1024) . 'abcd';
            PlainConnection::parsePlainEnvelope($oversize);
            $this->fail('expected RuntimeException for oversized length');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('length', $e->getMessage());
        }
    }

    /**
     * Deterministic in-memory transport: the fake server pre-seeds its
     * envelope-wrapped response into the socket-pair buffer, so the
     * single-threaded request() write+read cannot deadlock; the framed
     * request is then read back from the server side and unwrapped.
     */
    public function testRequestWrapsAndUnwrapsPlainEnvelopeOverSocketPair(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$clientSock, $serverSock] = $pair;

        $conn = new PlainConnection($clientSock);

        // fake telegram: pre-buffer one envelope-wrapped response
        fwrite($serverSock, FrameCodec::wrapAbridgedPayload(
            PlainConnection::buildPlainEnvelope('handshake-payload!!!', 0x6A4670610004F478)
        ));

        $response = $conn->request('handshake-payload!!!');
        $this->assertSame('handshake-payload!!!', $response);

        // the request itself must have arrived framed and envelope-wrapped
        $this->assertSame('handshake-payload!!!', PlainConnection::parsePlainEnvelope(
            FrameCodec::receiveAbridgedMessage($serverSock)
        ));

        $conn->close();
        fclose($serverSock);
    }

    public function testConnectWritesIntermediateInitByteOverLoopback(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $conn = PlainConnection::connect($host, (int) $port, timeout: 5.0);

        $client = stream_socket_accept($server, 5.0);
        $this->assertNotFalse($client);
        $this->assertSame("\xef", fread($client, 1)); // 0xef init byte

        $conn->close();
        fclose($client);
        fclose($server);
    }

    public function testCloseIsIdempotent(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$clientSock, $serverSock] = $pair;

        $conn = new PlainConnection($clientSock);
        $conn->close();
        $this->assertFalse(is_resource($conn->socket));
        $this->expectOutputString(''); // set before the guarded call: second close must emit nothing
        $conn->close();

        fclose($serverSock);
        $this->addToAssertionCount(1); // second close did not throw
    }
}
