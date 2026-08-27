<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use InvalidArgumentException;
use RuntimeException;

/**
 * High-Performance Telegram Type Language (TL) Binary Wire Engine.
 * Handles binary serialization/deserialization of primitives, flags, vectors, and polymorphic TL constructors.
 */
class TLSerializer
{
    public const VECTOR_CONSTRUCTOR_ID = 0x1cb5c415; // Vector<T> CRC32

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
        if ($offset >= strlen($data)) {
            throw new InvalidArgumentException("Buffer underflow reading TL string at offset {$offset}");
        }

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
     * Packs a 64-bit float (double) in little-endian format.
     */
    public static function packDouble(float $d): string
    {
        return pack('e', $d);
    }

    /**
     * Packs a 128-bit integer (16 raw bytes).
     */
    public static function packInt128(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new InvalidArgumentException("Int128 must be exactly 16 bytes.");
        }
        return $bytes;
    }

    /**
     * Packs a 256-bit integer (32 raw bytes).
     */
    public static function packInt256(string $bytes): string
    {
        if (strlen($bytes) !== 32) {
            throw new InvalidArgumentException("Int256 must be exactly 32 bytes.");
        }
        return $bytes;
    }

    /**
     * Packs a Vector<T> of items.
     *
     * @param list<mixed> $items
     * @param callable(mixed): string $itemSerializer
     */
    public static function packVector(array $items, callable $itemSerializer): string
    {
        $buffer = self::packInt(self::VECTOR_CONSTRUCTOR_ID);
        $buffer .= self::packInt(count($items));
        foreach ($items as $item) {
            $buffer .= $itemSerializer($item);
        }
        return $buffer;
    }

    /**
     * Unpacks a 32-bit integer in little-endian format.
     */
    public static function unpackInt(string $data, int &$offset = 0): int
    {
        if ($offset + 4 > strlen($data)) {
            throw new InvalidArgumentException("Buffer underflow reading int32 at offset {$offset}");
        }
        $val = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        return $val;
    }

    /**
     * Unpacks a 64-bit integer in little-endian format.
     */
    public static function unpackLong(string $data, int &$offset = 0): int
    {
        if ($offset + 8 > strlen($data)) {
            throw new InvalidArgumentException("Buffer underflow reading int64 at offset {$offset}");
        }
        $val = unpack('P', substr($data, $offset, 8))[1];
        $offset += 8;
        return $val;
    }

    /**
     * Unpacks a 64-bit float (double) in little-endian format.
     */
    public static function unpackDouble(string $data, int &$offset = 0): float
    {
        if ($offset + 8 > strlen($data)) {
            throw new InvalidArgumentException("Buffer underflow reading double at offset {$offset}");
        }
        $val = unpack('e', substr($data, $offset, 8))[1];
        $offset += 8;
        return $val;
    }

    /**
     * Unpacks a Vector<T> of items.
     *
     * @param string $data
     * @param int $offset
     * @param callable(string, int&): mixed $itemDeserializer
     * @return list<mixed>
     */
    public static function unpackVector(string $data, int &$offset, callable $itemDeserializer): array
    {
        $constructorId = self::unpackInt($data, $offset);
        if ($constructorId !== self::VECTOR_CONSTRUCTOR_ID) {
            throw new RuntimeException(sprintf("Invalid Vector constructor 0x%08x, expected 0x%08x", $constructorId, self::VECTOR_CONSTRUCTOR_ID));
        }

        $count = self::unpackInt($data, $offset);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $itemDeserializer($data, $offset);
        }
        return $items;
    }
}
