<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

/**
 * Clean-Room Telegram Type Language (TL) Binary Wire Formatter.
 * Uses standard PHP little-endian format specifiers (V = 32-bit, P = 64-bit).
 */
class TLSerializer
{
    /**
     * Packs a string or byte sequence into Telegram TL length-prefixed format with 4-byte padding.
     */
    public static function packString(string $s): string
    {
        $len = strlen($s);

        if ($len <= 253) {
            $header = pack('C', $len);
            $padding = (4 - (($len + 1) % 4)) % 4;
            return $header . $s . str_repeat("\x00", $padding);
        }

        // Length >= 254: 0xFE followed by 3 bytes 24-bit little-endian length
        $header = "\xfe" . substr(pack('V', $len), 0, 3);
        $padding = (4 - (($len + 4) % 4)) % 4;

        return $header . $s . str_repeat("\x00", $padding);
    }

    /**
     * Unpacks a Telegram TL length-prefixed string from binary buffer.
     */
    public static function unpackString(string $data, int &$offset = 0): string
    {
        $first = ord($data[$offset++]);

        if ($first <= 253) {
            $len = $first;
            $str = substr($data, $offset, $len);
            $offset += $len;
            $padding = (4 - (($len + 1) % 4)) % 4;
            $offset += $padding;
            return $str;
        }

        // Read 3-byte 24-bit length using standard 32-bit unpack
        $lenBytes = substr($data, $offset, 3) . "\x00";
        $len = unpack('V', $lenBytes)[1];
        $offset += 3;

        $str = substr($data, $offset, $len);
        $offset += $len;
        $padding = (4 - (($len + 4) % 4)) % 4;
        $offset += $padding;

        return $str;
    }

    /**
     * Packs a 32-bit signed/unsigned integer in little-endian format.
     */
    public static function packInt(int $i): string
    {
        return pack('V', $i);
    }

    /**
     * Packs a 64-bit integer in little-endian format.
     */
    public static function packLong(int $i): string
    {
        return pack('P', $i);
    }

    /**
     * Unpacks a 32-bit integer in little-endian format.
     */
    public static function unpackInt(string $data, int &$offset = 0): int
    {
        $val = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        return $val;
    }

    /**
     * Unpacks a 64-bit integer in little-endian format.
     */
    public static function unpackLong(string $data, int &$offset = 0): int
    {
        $val = unpack('P', substr($data, $offset, 8))[1];
        $offset += 8;
        return $val;
    }
}
