<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;

class MtprotoCryptoAndTlTest extends TestCase
{
    public function testAesIgeEncryptAndDecrypt(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(32);
        $plaintext = 'Secret Telegram MTProto message payload 1234567890!';

        $cipher = AesIge::encrypt($plaintext, $key, $iv);
        $decrypted = AesIge::decrypt($cipher, $key, $iv);

        // Strip padding null bytes
        $this->assertEquals($plaintext, rtrim($decrypted, "\x00"));
    }

    public function testTLStringPackingAndUnpacking(): void
    {
        $testStrings = [
            'short',
            'Hello world! A bit longer string that spans several words.',
            str_repeat('A', 300), // > 253 bytes to trigger 4-byte length prefix
        ];

        foreach ($testStrings as $original) {
            $packed = TLSerializer::packString($original);
            $offset = 0;
            $unpacked = TLSerializer::unpackString($packed, $offset);

            $this->assertEquals($original, $unpacked);
            $this->assertEquals(strlen($packed), $offset);
        }
    }

    public function testPasswordCalculatorSrpProof(): void
    {
        $accountPassword = [
            'has_password' => true,
            'current_algo' => [
                'g' => 3,
                'p' => str_repeat("\xFF", 255) . "\xED",
                'salt1' => 'salt1_test_bytes',
                'salt2' => 'salt2_test_bytes',
            ],
            'srp_B' => str_repeat("\xBB", 256),
            'srp_id' => 123456789,
        ];

        $proof = \MeRezaRezaei\Teleproto\MTProto\Crypto\PasswordCalculator::computeSrpProof(
            $accountPassword,
            'my_secure_cloud_password'
        );

        $this->assertEquals(123456789, $proof['srp_id']);
        $this->assertNotEmpty($proof['A']);
        $this->assertEquals(32, strlen($proof['M1']));
    }
}
