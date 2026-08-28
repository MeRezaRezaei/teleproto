<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use RuntimeException;

class TLEncoder
{
    public static function encodeObject(string $constructor, array $args): string
    {
        $bin = TLSerializer::packInt(TLRegistry::id($constructor));
        $fields = TLRegistry::signatureOf($constructor)->fields;
        $flagWords = [];
        // First pass: auto-compute flag bits from present arguments, while preserving any explicit flags passed
        foreach ($fields as $field) {
            if ($field['type'] === 'flags' || $field['type'] === '#') {
                $flagWords[$field['name']] = (int)($args[$field['name']] ?? 0);
            }
        }
        foreach ($fields as $field) {
            if ($field['flagWord'] !== null) {
                if (array_key_exists($field['name'], $args) && $args[$field['name']] !== null && $args[$field['name']] !== false) {
                    $flagWords[$field['flagWord']] = ($flagWords[$field['flagWord']] ?? 0) | (1 << $field['bit']);
                }
            }
        }
        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $fieldType = $field['type'];
            if ($fieldType === 'flags' || $fieldType === '#') {
                $bin .= TLSerializer::packInt($flagWords[$fieldName]);
                continue;
            }
            if ($field['flagWord'] !== null && isset($flagWords[$field['flagWord']])) {
                if (!array_key_exists($fieldName, $args) || $args[$fieldName] === null) {
                    continue; // bit clear: field absent from the wire
                }
                $bit = 1 << $field['bit'];
                if (($flagWords[$field['flagWord']] & $bit) === 0) {
                    throw new RuntimeException("TLEncoder: field '{$fieldName}' for {$constructor} is set but {$field['flagWord']} bit {$field['bit']} is not");
                }
                $bin .= self::encodeValue($fieldType, $args[$fieldName]);
                continue;
            }
            if (!array_key_exists($fieldName, $args)) {
                throw new RuntimeException("TLEncoder: missing field '{$fieldName}' for {$constructor}");
            }
            $bin .= self::encodeValue($fieldType, $args[$fieldName]);
        }
        return $bin;
    }

    public static function encodeValue(string $type, mixed $value): string
    {
        return match (true) {
            $type === 'int' => TLSerializer::packInt((int)$value),
            $type === 'long' => TLSerializer::packLong((int)$value),
            $type === 'true' => '', // presence is encoded by the flag bit alone
            $type === 'int128' || $type === 'int256' => str_pad((string)$value, $type === 'int128' ? 16 : 32, "\x00", STR_PAD_LEFT),
            $type === 'bytes' || $type === 'string' => TLSerializer::packString((string)$value),
            str_starts_with($type, 'Vector<') => TLSerializer::packVector(
                $value,
                fn($item) => self::encodeValue(substr($type, 7, -1), $item)
            ),
            default => is_array($value)
                ? self::encodeObject((string)($value['_'] ?? throw new RuntimeException('TLEncoder: nested object missing "_" key')), $value)
                : throw new RuntimeException("TLEncoder: cannot encode {$type} from scalar"),
        };
    }

    /**
     * BC wrapper kept for tests and external callers: [name, type] pairs in
     * schema order, derived from the same parser the registry caches from.
     * Conditional types render back as `flagWord.N?Type` for readability.
     *
     * @return list<array{0: string, 1: string}> [name, type] pairs in schema order
     */
    public static function fieldsOf(string $signature): array
    {
        $fields = [];
        foreach (TLSignatureParser::parse($signature)->fields as $field) {
            $type = $field['flagWord'] !== null
                ? $field['flagWord'] . '.' . $field['bit'] . '?' . $field['type']
                : $field['type'];
            $fields[] = [$field['name'], $type];
        }
        return $fields;
    }
}
