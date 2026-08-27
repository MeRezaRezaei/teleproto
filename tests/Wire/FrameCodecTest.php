<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use PHPUnit\Framework\TestCase;

class FrameCodecTest extends TestCase
{
    public function testWrapPayloadUsesLittleEndianLengthPrefix(): void
    {
        $wrapped = FrameCodec::wrapPayload('ABCD');
        $this->assertSame(4, unpack('V', substr($wrapped, 0, 4))[1]);
        $this->assertSame('ABCD', substr($wrapped, 4));
    }

    public function testFrameRoundTripOverLoopback(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $client = StreamSocket::createConnection($host, (int)$port, timeout: 5.0);
        $accepted = stream_socket_accept($server, 5.0);

        FrameCodec::writeInit($client);
        FrameCodec::sendMessage($client, 'hello-transport');
        // server side: read 1 init byte, one length, echo the payload back
        fread($accepted, 1);
        $len = unpack('V', StreamSocket::readExact($accepted, 4))[1];
        $payload = StreamSocket::readExact($accepted, $len);
        FrameCodec::sendMessage($accepted, $payload);

        $this->assertSame('hello-transport', FrameCodec::receiveMessage($client));
        fclose($client); fclose($accepted); fclose($server);
    }
}
