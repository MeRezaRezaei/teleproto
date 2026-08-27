<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

/**
 * Base abstract class for all MTProto Type Language (TL) Objects.
 */
abstract class TLObject
{
    /**
     * Unique 32-bit TL Constructor / Function ID (CRC32).
     */
    abstract public static function getConstructorId(): int;

    /**
     * Serializes object fields into binary TL wire representation.
     */
    abstract public function serialize(): string;

    /**
     * Deserializes binary buffer into typed TLObject instance.
     */
    abstract public static function deserialize(string $data, int &$offset = 0): static;

    /**
     * Converts TLObject into an associative array for JSON representation.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
