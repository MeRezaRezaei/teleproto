<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;
use RuntimeException;

/**
 * Standard Diffie-Hellman (DH) MTProto 2.0 Key Exchange Engine.
 * Powered by phpseclib3 BigInteger (with GMP/BCMath C-level acceleration) and OpenSSL.
 */
class DiffieHellman
{
    /**
     * Factorizes Telegram's 64-bit semi-prime pq into p and q where p < q.
     * Uses Pollard's Rho algorithm with GCD acceleration.
     *
     * @param string $pqBytes 8-byte big-endian or numeric string
     * @return array{p: int, q: int}
     */
    public static function factorizePq(string $pqBytes): array
    {
        $pq = new BigInteger($pqBytes, 256);
        $zero = new BigInteger(0);
        $one = new BigInteger(1);

        // Pollard's rho algorithm
        $x = new BigInteger(random_int(1, 1000));
        $y = clone $x;
        $c = new BigInteger(random_int(1, 1000));
        $d = clone $one;

        $iterations = 0;
        while ($d->equals($one) && $iterations < 10000) {
            // x = (x^2 + c) mod pq
            $x = $x->multiply($x)->add($c)->divide($pq)[1];

            // y = (y^2 + c) mod pq, twice
            $y = $y->multiply($y)->add($c)->divide($pq)[1];
            $y = $y->multiply($y)->add($c)->divide($pq)[1];

            // d = gcd(|x - y|, pq)
            $diff = $x->subtract($y)->abs();
            $d = $diff->gcd($pq);
            $iterations++;
        }

        if ($d->equals($one) || $d->equals($pq)) {
            // Fallback trial division for small 64-bit numbers
            $limit = 1000000;
            for ($i = 3; $i <= $limit; $i += 2) {
                $div = new BigInteger($i);
                if ($pq->divide($div)[1]->equals($zero)) {
                    $d = $div;
                    break;
                }
            }
        }

        $p = $d;
        $q = $pq->divide($p)[0];

        if ($p->compare($q) > 0) {
            $temp = $p;
            $p = $q;
            $q = $temp;
        }

        return [
            'p' => (int)$p->toString(),
            'q' => (int)$q->toString(),
        ];
    }

    /**
     * Computes the Diffie-Hellman client public key g_b and shared AuthKey.
     *
     * @param string $gStr Generator string (e.g. "3", "4", "5", "7")
     * @param string $dhPrimeStr 2048-bit prime number p from server
     * @param string $gaStr 2048-bit server public key g_a
     * @return array{b: string, gb: string, auth_key: string}
     */
    public static function computeAuthKey(string $gStr, string $dhPrimeStr, string $gaStr): array
    {
        $g = new BigInteger($gStr);
        $p = new BigInteger($dhPrimeStr, 256);
        $ga = new BigInteger($gaStr, 256);

        // Generate cryptographically secure random 2048-bit secret exponent b
        $b = new BigInteger(random_bytes(256), 256);

        // g_b = g^b mod p
        $gb = $g->modPow($b, $p);

        // Shared AuthKey = (g_a)^b mod p = g^(ab) mod p
        $authKeyBig = $ga->modPow($b, $p);
        $authKey = str_pad($authKeyBig->toBytes(), 256, "\x00", STR_PAD_LEFT);

        return [
            'b' => $b->toBytes(),
            'gb' => $gb->toBytes(),
            'auth_key' => $authKey,
        ];
    }

    /**
     * Encrypts client DH parameters with Telegram's RSA Public Key using phpseclib3.
     */
    public static function rsaEncrypt(string $data, string $rsaPublicKeyPem): string
    {
        /** @var RSA\PublicKey $key */
        $key = PublicKeyLoader::load($rsaPublicKeyPem);
        $key = $key->withPadding(RSA::ENCRYPTION_PKCS1);

        return $key->encrypt($data);
    }
}
