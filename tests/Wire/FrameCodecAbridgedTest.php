<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use PHPUnit\Framework\TestCase;

class FrameCodecAbridgedTest extends TestCase
{
    public function testAbridgedShortFrameUsesSingleByteLength(): void
    {
        $payload = str_repeat('A', 40); // 40/4 = 10 < 127
        $wrapped = FrameCodec::wrapAbridgedPayload($payload);
        $this->assertSame("\x0a", substr($wrapped, 0, 1));
        $this->assertSame($payload, substr($wrapped, 1));
    }

    public function testAbridgedLongFrameUses0x7fPlusThreeByteLength(): void
    {
        $payload = str_repeat('B', 2048); // 2048/4 = 512 >= 127
        $wrapped = FrameCodec::wrapAbridgedPayload($payload);
        $this->assertSame("\x7f", $wrapped[0]);
        $len4 = unpack('V', substr($wrapped, 1, 3) . "\x00")[1];
        $this->assertSame(512, $len4);
        $this->assertSame($payload, substr($wrapped, 4));
    }

    public function testAbridgedRoundTripOverLoopbackBothFrameSizes(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $addr);

        $client = StreamSocket::createConnection($host, (int) $port, timeout: 5.0);
        $accepted = stream_socket_accept($server, 5.0);

        // client -> server: short frame
        FrameCodec::sendAbridgedMessage($client, 'tiny-frames!');
        $this->assertSame('tiny-frames!', FrameCodec::receiveAbridgedMessage($accepted));

        // server -> client: long frame (exercises 0x7f+3LE path on receive)
        $longPayload = str_repeat('C', 1020 + 8); // 1028 bytes -> 257 >= 127
        FrameCodec::sendAbridgedMessage($accepted, $longPayload);
        $this->assertSame($longPayload, FrameCodec::receiveAbridgedMessage($client));

        fclose($client);
        fclose($accepted);
        fclose($server);
    }
}
