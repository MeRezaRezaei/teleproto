<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use PHPUnit\Framework\TestCase;

class StreamSocketTest extends TestCase
{
    public function testWriteAndReadExactOverLoopbackEcho(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "loopback server failed: $errstr");
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $client = StreamSocket::createConnection($host, (int)$port, timeout: 5.0);
        $accepted = stream_socket_accept($server, 5.0);

        StreamSocket::write($client, "hello-frame-bytes");

        // echo server: read then write back
        $got = fread($accepted, 17);
        fwrite($accepted, $got);

        $this->assertSame('hello-frame-bytes', StreamSocket::readExact($client, 17));
        fclose($client);
        fclose($accepted);
        fclose($server);
    }

    public function testReadThrowsOnPrematureEof(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EOF');
        // A closed pipe resource: stream_socket_pair is simplest
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fclose($b);
        StreamSocket::readExact($a, 64);
    }
}
