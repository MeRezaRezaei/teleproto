<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

/**
 * Immutable parse result of one canonical TL line. Built only by TLSignatureParser.
 *
 * @phpstan-type SigField array{name: string, type: string, flagWord: string|null, bit: int|null}
 */
final class ParsedSignature
{
    /**
     * @param list<SigField> $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly bool $hasExplicitId,
        public readonly array $fields,
        public readonly string $returnType,
    ) {}
}
