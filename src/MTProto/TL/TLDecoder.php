<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use RuntimeException;

/**
 * Mirror of TLEncoder: schema-driven generic TL object decoder.
 * Reads the constructor id, resolves it via TLRegistry::nameOf, then decodes
 * each field of TLEncoder::fieldsOf(signature) in order.
 */
class TLDecoder
{
    /**
     * @param array<string, string> $contextTypes reserved for callers that know
     *        the expected type of a polymorphic field; unused for now.
     * @return array<string, mixed> ['_' => constructorName, field => value, ...]
     */
    public static function decodeObject(string $data, ?int &$offset = 0, array $contextTypes = []): array
    {
        $offset ??= 0;
        if ($offset + 4 > strlen($data)) {
            throw new RuntimeException(sprintf('TLDecoder: buffer underflow reading constructor id at offset %d', $offset));
        }
        $id = TLSerializer::unpackInt($data, $offset);
        $name = TLRegistry::nameOf($id);
        if ($name === null) {
            throw new RuntimeException(sprintf('TLDecoder: unknown constructor id 0x%08x', $id));
        }

        $result = ['_' => $name];
        $flags = 0;
        foreach (TLEncoder::fieldsOf(TLRegistry::signature($name)) as [$fieldName, $fieldType]) {
            if ($fieldType === 'flags' || $fieldType === '#') {
                $flags = TLSerializer::unpackInt($data, $offset);
                $result[$fieldName] = $flags;
                continue;
            }
            if (preg_match('/^flags\.(\d+)\?(.+)$/', $fieldType, $m)) {
                if (($flags & (1 << (int)$m[1])) === 0) {
                    continue; // bit clear: field absent from the wire
                }
                $result[$fieldName] = self::decodeValue($m[2], $data, $offset);
                continue;
            }
            $result[$fieldName] = self::decodeValue($fieldType, $data, $offset);
        }
        return $result;
    }

    public static function decodeValue(string $type, string $data, int &$offset): mixed
    {
        return match (true) {
            $type === 'int' => TLSerializer::unpackInt($data, $offset),
            $type === 'long' => TLSerializer::unpackLong($data, $offset),
            $type === 'int128' => self::rawBytes($data, $offset, 16),
            $type === 'int256' => self::rawBytes($data, $offset, 32),
            $type === 'bytes' || $type === 'string' => TLSerializer::unpackString($data, $offset),
            str_starts_with($type, 'Vector<') => TLSerializer::unpackVector(
                $data,
                $offset,
                fn(string $d, int &$o) => self::decodeValue(substr($type, 7, -1), $d, $o)
            ),
            default => self::decodeObject($data, $offset), // X / !X / Object / named type
        };
    }

    protected static function rawBytes(string $data, int &$offset, int $len): string
    {
        if ($offset + $len > strlen($data)) {
            throw new RuntimeException(sprintf('TLDecoder: buffer underflow reading %d raw bytes at offset %d', $len, $offset));
        }
        $bytes = substr($data, $offset, $len);
        $offset += $len;
        return $bytes;
    }
}
