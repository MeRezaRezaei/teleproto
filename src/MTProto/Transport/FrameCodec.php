<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Transport;

use RuntimeException;

/**
 * MTProto intermediate TCP transport: single 0xef init byte, then
 * each message framed as 4-byte little-endian length + payload.
 */
class FrameCodec
{
    private const MAX_FRAME = 2 * 1024 * 1024;

    public static function writeInit($socket): void
    {
        StreamSocket::write($socket, "\xef");
    }

    public static function wrapPayload(string $payload): string
    {
        return pack('V', strlen($payload)) . $payload;
    }

    public static function sendMessage($socket, string $payload): void
    {
        StreamSocket::write($socket, self::wrapPayload($payload));
    }

    public static function receiveMessage($socket): string
    {
        $len = unpack('V', StreamSocket::readExact($socket, 4))[1];
        if ($len < 1 || $len > self::MAX_FRAME) {
            throw new RuntimeException("FrameCodec: invalid frame length {$len}");
        }
        return StreamSocket::readExact($socket, $len);
    }
}
