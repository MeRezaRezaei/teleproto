<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Transport;

use RuntimeException;

/**
 * MTProto TCP transport framing.
 *
 * Abridged (default): single 0xef init byte, then each message framed as
 * length/4 in one byte (when < 0x7f) or 0x7f + 3-byte little-endian length/4.
 * Production Telegram DCs answer abridged-framed handshake messages;
 * intermediate framing was observed being silently dropped (2026-08).
 *
 * Intermediate (kept for compatibility): single 0xef init byte, then
 * each message framed as 4-byte little-endian length + payload.
 */
class FrameCodec
{
    private const MAX_FRAME = 2 * 1024 * 1024;

    public static function writeInit($socket): void
    {
        StreamSocket::write($socket, "\xef");
    }

    /**
     * Wraps a payload in abridged framing: varint(len/4).
     * Payload length must be a multiple of 4 (all MTProto envelopes and
     * encrypted packets are; abridged framing cannot express a remainder).
     */
    public static function wrapAbridgedPayload(string $payload): string
    {
        if (strlen($payload) % 4 !== 0) {
            throw new \InvalidArgumentException(
                'FrameCodec: abridged payloads must be a multiple of 4 bytes, got ' . strlen($payload)
            );
        }
        $len4 = strlen($payload) >> 2;
        $prefix = $len4 < 0x7f ? chr($len4) : "\x7f" . substr(pack('V', $len4), 0, 3);
        return $prefix . $payload;
    }

    public static function sendAbridgedMessage($socket, string $payload): void
    {
        StreamSocket::write($socket, self::wrapAbridgedPayload($payload));
    }

    public static function receiveAbridgedMessage($socket): string
    {
        $first = ord(StreamSocket::readExact($socket, 1));
        $len4 = $first;
        if ($len4 >= 0x7f) {
            $len4 = unpack('V', StreamSocket::readExact($socket, 3) . "\x00")[1];
        }
        $len = $len4 << 2;
        if ($len < 1 || $len > self::MAX_FRAME) {
            throw new RuntimeException("FrameCodec: invalid abridged frame length {$len}");
        }
        return StreamSocket::readExact($socket, $len);
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
