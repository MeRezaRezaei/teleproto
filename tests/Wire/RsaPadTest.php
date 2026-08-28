<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use phpseclib3\Crypt\RSA;
use PHPUnit\Framework\TestCase;

/**
 * RSA-PAD (https://core.telegram.org/mtproto/protocols#rsa-pad) construction
 * verified end-to-end with a locally generated keypair, mirroring the
 * construction used by MadelineProto's AuthKeyHandler.
 */
class RsaPadTest extends TestCase
{
    public function testRsaPadConstructionRoundTripsWithOwnKeypair(): void
    {
        $key = RSA::createKey(2048);
        $publicPem = $key->getPublicKey()->toString('PKCS1');

        $inner = TLEncoder::encodeObject('p_q_inner_data_dc', [
            'pq' => random_bytes(8),
            'p' => random_bytes(4),
            'q' => random_bytes(4),
            'nonce' => random_bytes(16),
            'server_nonce' => random_bytes(16),
            'new_nonce' => random_bytes(32),
            'dc' => 2,
        ]);

        $ciphertext = AuthKeyFactory::rsaPadEncrypt($publicPem, $inner);
        $this->assertSame(256, strlen($ciphertext));

        // Raw RSA decrypt with the private key (no padding scheme)
        $m = $key->withPadding(RSA::ENCRYPTION_NONE)->decrypt($ciphertext);
        $this->assertNotFalse($m);
        $m = str_pad((string) $m, 256, "\x00", STR_PAD_LEFT);

        // Unwrap RSA-PAD: key_xor(32) || aes_encrypted(224)
        $tempKeyXor = substr($m, 0, 32);
        $aesEncrypted = substr($m, 32);
        $tempKey = $tempKeyXor ^ hash('sha256', $aesEncrypted, true);

        $dataWithHash = AesIge::decrypt($aesEncrypted, $tempKey, str_repeat("\x00", 32));
        $this->assertSame(224, strlen($dataWithHash));

        $reversed = substr($dataWithHash, 0, 192);
        $hash = substr($dataWithHash, 192, 32);
        $dataWithPadding = strrev($reversed);

        $this->assertSame(192, strlen($dataWithPadding));
        $this->assertSame(hash('sha256', $tempKey . $dataWithPadding, true), $hash);
        $this->assertStringStartsWith($inner, $dataWithPadding);
    }

    public function testRsaPadRejectsOversizedInnerData(): void
    {
        $key = RSA::createKey(2048);
        $publicPem = $key->getPublicKey()->toString('PKCS1');

        $this->expectException(\RuntimeException::class);
        AuthKeyFactory::rsaPadEncrypt($publicPem, str_repeat('x', 145));
    }
}
