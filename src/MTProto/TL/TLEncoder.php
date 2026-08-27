<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use RuntimeException;

class TLEncoder
{
    public static function encodeObject(string $constructor, array $args): string
    {
        $bin = TLSerializer::packInt(TLRegistry::id($constructor));
        $signature = TLRegistry::signature($constructor);
        $fields = self::fieldsOf($signature);
        $flags = 0;
        foreach ($fields as [$fieldName, $fieldType]) {
            if ($fieldType === 'flags' || $fieldType === '#') {
                $flags = (int)($args[$fieldName] ?? 0);
            }
        }
        foreach ($fields as [$fieldName, $fieldType]) {
            if ($fieldType === 'flags' || $fieldType === '#') {
                $bin .= TLSerializer::packInt((int)($args[$fieldName] ?? 0));
                continue;
            }
            if (preg_match('/^flags\.(\d+)\?/', $fieldType, $m)) {
                if (!array_key_exists($fieldName, $args) || $args[$fieldName] === null) {
                    continue; // bit clear: field absent from the wire
                }
                $bit = 1 << (int)$m[1];
                if (($flags & $bit) === 0) {
                    throw new RuntimeException("TLEncoder: field '{$fieldName}' for {$constructor} is set but flag bit {$m[1]} is not");
                }
                $bin .= self::encodeValue(substr($fieldType, strpos($fieldType, '?') + 1), $args[$fieldName]);
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
     * @return list<array{0: string, 1: string}> [name, type] pairs in schema order
     */
    public static function fieldsOf(string $signature): array
    {
        // "name#id f1:t1 f2:t2 = Type" (id optional)
        $body = preg_replace('/^[A-Za-z0-9_.]+(#[0-9a-fA-F]+)?\s*/', '', $signature);
        $body = trim(explode('=', (string)$body)[0]);
        $fields = [];
        if ($body === '' || $body === null) {
            return $fields;
        }
        // Canonical strings use the bare form `field:Vector t`; normalize to `field:Vector<t>`.
        $body = (string)preg_replace('/:Vector ([A-Za-z0-9_.]+)(?=\s|$)/', ':Vector<$1>', $body);
        foreach (explode(' ', $body) as $token) {
            [$name, $type] = explode(':', $token, 2);
            if ($type === 'Type') {
                continue; // generic declaration (canonical brace-less `{X:Type}`), not a wire field
            }
            $fields[] = [$name, $type];
        }
        return $fields;
    }
}
