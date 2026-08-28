<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\TL;

use InvalidArgumentException;

/**
 * Deterministic tokenizer for canonical TL schema lines:
 *   name[#id] field:type [field2:flags.N?Type ...] = ReturnType
 * Malformed input throws with the exact column and reason. No regex anywhere.
 */
final class TLSignatureParser
{
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.';
    private const IDENT_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
    private const DIGIT_CHARS = '0123456789';
    private const HEX_CHARS = '0123456789abcdefABCDEF';

    public static function parse(string $line): ParsedSignature
    {
        $line = trim($line);
        $len = strlen($line);
        $col = 0;

        $name = self::takeWhile($line, $col, self::NAME_CHARS);
        if ($name === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected constructor name");
        }

        $id = 0;
        $hasId = false;
        if ($col < $len && $line[$col] === '#') {
            $col++;
            $hex = self::takeWhile($line, $col, self::HEX_CHARS);
            if ($hex === '') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected hex id after '#'");
            }
            $id = (int) hexdec($hex);
            $hasId = true;
        }

        /** @var list<array{name: string, type: string, flagWord: string|null, bit: int|null}> $fields */
        $fields = [];

        // fields until '='
        while ($col < $len && $line[$col] !== '=') {
            self::skipSpaces($line, $col);
            if ($col >= $len || $line[$col] === '=') {
                break;
            }
            $fName = self::takeWhile($line, $col, self::IDENT_CHARS);
            if ($fName === '') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected field name");
            }
            if ($col >= $len || $line[$col] !== ':') {
                throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected ':' after field name '{$fName}'");
            }
            $col++;
            [$type, $flagWord, $bit] = self::parseType($line, $col);

            // generic declaration `{X:Type}` arrives here as type 'Type' — skip, not a wire field
            if ($type !== 'Type') {
                $fields[] = ['name' => $fName, 'type' => $type, 'flagWord' => $flagWord, 'bit' => $bit];
            }
        }

        if ($col >= $len || $line[$col] !== '=') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected '=' before return type");
        }
        $col++;
        self::skipSpaces($line, $col);
        $returnType = self::parseReturnType($line, $col);
        if ($returnType === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected return type after '='");
        }

        return new ParsedSignature($name, $id, $hasId, $fields, $returnType);
    }

    /**
     * Parses one field type token; handles `flags.N?T`, the flag-mask `#`
     * type, dotted namespace types (`auth.SentCode`), the angle form
     * `Vector<...>`, and the bare two-token `Vector t` form.
     * Returns [normalizedType, flagWord|null, bit|null].
     *
     * @return array{0: string, 1: string|null, 2: int|null}
     */
    private static function parseType(string $line, int &$col): array
    {
        $len = strlen($line);
        $word = self::takeWhile($line, $col, self::IDENT_CHARS);
        if ($word === '') {
            // bare '#' is the flag-mask type (`flags:#`)
            if ($col < $len && $line[$col] === '#') {
                $col++;
                return ['#', null, null];
            }
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected type token");
        }

        // conditional `flagsWord.N?T`: dot followed by digits then '?'
        if ($col < $len && $line[$col] === '.') {
            $p = $col + 1;
            $digits = self::takeWhile($line, $p, self::DIGIT_CHARS);
            if ($digits !== '') {
                if ($p >= $len || $line[$p] !== '?') {
                    throw new InvalidArgumentException("TLSignatureParser: col {$p}: expected 'N?' after '{$word}.'");
                }
                $col = $p + 1;
                [$inner, ,] = self::parseType($line, $col);
                return [$inner, $word, (int) $digits];
            }
        }

        // dotted namespace segments (`auth.SentCode`): dot followed by an identifier char
        while ($col < $len && $line[$col] === '.' && $col + 1 < $len && str_contains(self::IDENT_CHARS, $line[$col + 1])) {
            $col++;
            $word .= '.' . self::takeWhile($line, $col, self::IDENT_CHARS);
        }

        // explicit angle form `Word<...>` — consumed verbatim (nested groups kept)
        if ($col < $len && $line[$col] === '<') {
            $inner = self::takeAngleInner($line, $col);
            return [$word . '<' . $inner . '>', null, null];
        }

        // bare Vector two-token form: `Vector t` -> Vector<t>
        if ($word === 'Vector') {
            $peek = $col;
            self::skipSpaces($line, $peek);
            $inner = self::takeWhile($line, $peek, self::NAME_CHARS);
            if ($inner !== '') {
                $col = $peek;
                return ['Vector<' . $inner . '>', null, null];
            }
            return ['Vector', null, null];
        }

        return [$word, null, null];
    }

    /** Return type may itself be the bare `Vector t` or `Vector<t>` form. */
    private static function parseReturnType(string $line, int &$col): string
    {
        if ($col >= strlen($line)) {
            return '';
        }
        [$type, ,] = self::parseType($line, $col);
        return $type;
    }

    /**
     * Precondition: `$line[$col] === '<'`. Consumes one balanced `<...>`
     * group, returning its raw inner text without the outer brackets.
     */
    private static function takeAngleInner(string $line, int &$col): string
    {
        $len = strlen($line);
        $col++;
        $start = $col;
        $depth = 1;
        while ($col < $len && $depth > 0) {
            if ($line[$col] === '<') {
                $depth++;
            } elseif ($line[$col] === '>') {
                $depth--;
            }
            $col++;
        }
        if ($depth !== 0) {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected '>' to close '<'");
        }
        $inner = substr($line, $start, $col - 1 - $start);
        if ($inner === '') {
            throw new InvalidArgumentException("TLSignatureParser: col {$col}: expected type inside '<...>'");
        }
        return $inner;
    }

    private static function takeWhile(string $line, int &$col, string $allowed): string
    {
        $start = $col;
        $len = strlen($line);
        while ($col < $len && str_contains($allowed, $line[$col])) {
            $col++;
        }
        return substr($line, $start, $col - $start);
    }

    private static function skipSpaces(string $line, int &$col): void
    {
        $len = strlen($line);
        while ($col < $len && ($line[$col] === ' ' || $line[$col] === "\t")) {
            $col++;
        }
    }
}
