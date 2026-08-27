<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use phpseclib3\Math\BigInteger;
use RuntimeException;

/**
 * Pollard's rho factorization for the semiprime pq used by the MTProto
 * key exchange (p, q are ~32-bit; pq up to 64 bits).
 */
class PqFactorizer
{
    /** @return array{0: string, 1: string} [smaller, larger] big-endian bytes */
    public static function factorize(string $pqBytes): array
    {
        $pq = new BigInteger(bin2hex($pqBytes), 16);
        $one = new BigInteger(1);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $x = new BigInteger(random_int(1, PHP_INT_MAX));
            $y = clone $x;
            $c = new BigInteger(random_int(1, PHP_INT_MAX));
            $g = $one;

            while ($g->equals($one)) {
                $x = self::f($x, $c, $pq);
                $y = self::f(self::f($y, $c, $pq), $c, $pq);
                $g = $x->subtract($y)->abs()->gcd($pq);
            }

            if (!$g->equals($pq)) {
                $p = $g;
                $q = $pq->divide($g)[0];
                $smaller = $p->compare($q) > 0 ? $q : $p;
                $larger = $p->compare($q) > 0 ? $p : $q;
                return [
                    self::toBytes($smaller),
                    self::toBytes($larger),
                ];
            }
        }
        throw new RuntimeException('PqFactorizer: failed to factor pq after 8 attempts');
    }

    protected static function f(BigInteger $x, BigInteger $c, BigInteger $n): BigInteger
    {
        return $x->multiply($x)->add($c)->divide($n)[1]; // (x^2 + c) mod n
    }

    protected static function toBytes(BigInteger $n): string
    {
        $hex = $n->toHex();
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }
}
