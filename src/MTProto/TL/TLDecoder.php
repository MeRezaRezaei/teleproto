<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use RuntimeException;

/**
 * Mirror of TLEncoder: schema-driven generic TL object decoder.
 * Reads the constructor id, resolves it via TLRegistry::nameOf, then decodes
 * each field of TLRegistry::signatureOf(name)->fields in order.
 */
class TLDecoder
{
    /**
     * @param array<string, string> $contextTypes reserved for callers that know
     *        the expected type of a polymorphic field; unused for now.
     * @return array<string, mixed>|list<mixed> ['_' => constructorName, field => value, ...]
     */
    public static function decodeObject(string $data, int &$offset = 0, array $contextTypes = []): array
    {
        if ($offset + 4 > strlen($data)) {
            throw new RuntimeException(sprintf('TLDecoder: buffer underflow reading constructor id at offset %d', $offset));
        }
        $id = TLSerializer::unpackInt($data, $offset);

        // Bare polymorphic Vector<T> (0x1cb5c415) at root level
        if ($id === TLRegistry::VECTOR) {
            $count = TLSerializer::unpackInt($data, $offset);
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $items[] = self::decodeObject($data, $offset);
            }
            return $items;
        }

        $name = TLRegistry::nameOf($id);
        if ($name === null) {
            $parent = $contextTypes['_parent'] ?? '?';
            throw new RuntimeException(sprintf('TLDecoder: unknown constructor id 0x%08x while decoding inside <%s> at offset %d', $id, is_string($parent) ? $parent : '?', $offset - 4));
        }

        $result = ['_' => $name];
        $flagWords = [];
        foreach (TLRegistry::signatureOf($name)->fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            if ($fieldType === 'flags' || $fieldType === '#') {
                $flagWords[$fieldName] = TLSerializer::unpackInt($data, $offset);
                $result[$fieldName] = $flagWords[$fieldName];
                continue;
            }
            if ($field['flagWord'] !== null && isset($flagWords[$field['flagWord']])) {
                if (($flagWords[$field['flagWord']] & (1 << $field['bit'])) === 0) {
                    continue; // bit clear: field absent from the wire
                }
                $result[$fieldName] = self::decodeValue($fieldType, $data, $offset, $name);
                continue;
            }
            $result[$fieldName] = self::decodeValue($fieldType, $data, $offset, $name);
        }
        return $result;
    }

    public static function decodeValue(string $type, string $data, int &$offset, ?string $parent = null): mixed
    {
        $match = match (true) {
            $type === 'int' => TLSerializer::unpackInt($data, $offset),
            $type === 'long' => TLSerializer::unpackLong($data, $offset),
            $type === 'true' => true, // presence was encoded by the flag bit alone
            $type === 'int128' => self::rawBytes($data, $offset, 16),
            $type === 'int256' => self::rawBytes($data, $offset, 32),
            $type === 'bytes' || $type === 'string' => TLSerializer::unpackString($data, $offset),
            str_starts_with($type, 'Vector<') => TLSerializer::unpackVector(
                $data,
                $offset,
                fn(string $d, int &$o) => self::decodeValue(substr($type, 7, -1), $d, $o, $parent)
            ),
            default => self::decodeObject($data, $offset, $parent === null ? [] : ['_parent' => $parent]), // X / !X / Object / named type
        };
        return $match;
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
