<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use PHPUnit\Framework\TestCase;

class AuthKeyFactoryOfflineTest extends TestCase
{
    public function testServerSaltIsXorOfNonceAndServerNonce(): void
    {
        $newNonce = str_repeat("\x11", 32);
        $serverNonce = str_repeat("\x22", 16);
        $salt = AuthKeyFactory::serverSalt($newNonce, $serverNonce);
        $this->assertSame(8, strlen($salt));
        // first 8 bytes of new_nonce XOR first 8 bytes of server_nonce
        $this->assertSame(str_repeat("\x33", 8), $salt);
    }

    /**
     * Official sample handshake vector from
     * https://core.telegram.org/mtproto/samples-auth_key : the server's
     * dh_gen_ok carried new_nonce_hash1 = AA404B58DF404D8F363772B14CE5A56F
     * for the published new_nonce / auth_key pair.
     */
    public function testNewNonceHash1MatchesOfficialSampleVector(): void
    {
        $newNonce = hex2bin('BF8CB5BD9C5B4FE7CF24D64D281F89311576D53C0DA65A83267E57315414C9A6');
        $authKey = hex2bin(
            '8E1081A1B5CA1B399A9A9D7E08BB9A9182AB634F8C03F2A49F944E2F944A9C7'
            . '1EDBA61A32A70D3DADEB33752AE515B16B2D8E75039C40EBE18136775C37273'
            . '72A8DF486606D671FD63842DF0A44ACC31E68B7B1EC6A731A1DC5C748F0CB46'
            . 'AC00FDE363F0520B51D9B59EAE519EA511A8E8591FC7010DF0B07CDBAB0401'
            . '3DD85172CB54555DC5C982EA0A5DCF4411E798D338B823161FD8C93100B7A4'
            . '26186B4C16F9113521081C8D2075872F4A0CF238034843DC01F2C26828721A2'
            . 'E2FFD93A9B0142B8DF6355C43D9AEF5B448F1CC0D84E0E72A7FF494D4CC3B1'
            . '650050DDEC5DC321ADA68E420F45098280CEAB58A1CBFAA60FFF3218E56B474'
            . '1143AC5A6F0'
        );
        $this->assertSame('aa404b58df404d8f363772b14ce5a56f', bin2hex(AuthKeyFactory::newNonceHash1($newNonce, $authKey)));
    }

    public function testNewNonceHash1IsSixteenBytes(): void
    {
        $this->assertSame(16, strlen(AuthKeyFactory::newNonceHash1(random_bytes(32), random_bytes(256))));
    }

    /**
     * The primary bundled key must produce the fingerprint byte sequence
     * 85fd64de851d9dd0 published in the official sample handshake
     * (server_public_key_fingerprints[0]); fingerprintOf returns it as a
     * positive 63-bit integer.
     */
    public function testFingerprintOfWellKnownKeyIsStable(): void
    {
        $pem = file_get_contents(__DIR__ . '/../../src/MTProto/resources/telegram_public_key.pub');
        $this->assertNotFalse($pem);
        $fp = AuthKeyFactory::fingerprintOf($pem);
        $this->assertGreaterThan(0, $fp);
        $this->assertSame(0x05fd64de851d9dd0, $fp);
    }

    public function testFingerprintBytesMatchOfficialTranscript(): void
    {
        $pem = file_get_contents(__DIR__ . '/../../src/MTProto/resources/telegram_public_key.pub');
        $this->assertNotFalse($pem);
        $this->assertSame('85fd64de851d9dd0', bin2hex(AuthKeyFactory::fingerprintBytesOf($pem)));
    }
}
