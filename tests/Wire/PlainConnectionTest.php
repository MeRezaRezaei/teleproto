<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use PHPUnit\Framework\TestCase;

class PlainConnectionTest extends TestCase
{
    /**
     * Deterministic in-memory transport: the fake server pre-seeds its
     * response into the socket-pair buffer, so the single-threaded request()
     * write+read cannot deadlock; the framed request is then read back from
     * the server side and verified.
     */
    public function testRequestEchoesSingleFrameOverSocketPair(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        [$clientSock, $serverSock] = $pair;

        $conn = new PlainConnection($clientSock);

        // fake telegram: answers with one pre-buffered frame-echo
        fwrite($serverSock, FrameCodec::wrapPayload('handshake-payload'));

        $response = $conn->request('handshake-payload');
        $this->assertSame('handshake-payload', $response);

        // the request itself must have arrived framed (4-byte LE length + payload)
        $this->assertSame('handshake-payload', FrameCodec::receiveMessage($serverSock));

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
        $conn->close(); // must not warn or throw

        fclose($serverSock);
        $this->expectOutputString(''); // assert no warning leaked as output
    }
}
