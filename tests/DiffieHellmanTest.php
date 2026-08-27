<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\MTProto\Crypto\DiffieHellman;
use phpseclib3\Crypt\RSA;

class DiffieHellmanTest extends TestCase
{
    public function testFactorizeSemiPrimePq(): void
    {
        // 499 * 503 = 250997
        $p = 499;
        $q = 503;
        $pq = $p * $q;

        $pqBytes = pack('N', $pq); // big-endian
        $result = DiffieHellman::factorizePq($pqBytes);

        $this->assertEquals($p, $result['p']);
        $this->assertEquals($q, $result['q']);
    }

    public function testComputeDiffieHellmanSharedKey(): void
    {
        $gStr = '3';
        // 2048-bit sample prime p
        $dhPrime = str_repeat("\xFF", 255) . "\xED";
        $ga = str_repeat("\xAA", 256);

        $result = DiffieHellman::computeAuthKey($gStr, $dhPrime, $ga);

        $this->assertNotEmpty($result['b']);
        $this->assertNotEmpty($result['gb']);
        $this->assertEquals(256, strlen($result['auth_key']));
    }

    public function testRsaEncryptionWithPhpseclib(): void
    {
        $privateKey = RSA::createKey(2048);
        $publicKey = $privateKey->getPublicKey()->toString('PKCS8');

        $plaintext = 'Secret Handshake Message';
        $encrypted = DiffieHellman::rsaEncrypt($plaintext, $publicKey);

        $this->assertNotEmpty($encrypted);

        $decrypted = $privateKey->withPadding(RSA::ENCRYPTION_PKCS1)->decrypt($encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }
}
