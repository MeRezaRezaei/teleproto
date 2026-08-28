<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    /**
     * Official sample handshake (core.telegram.org/mtproto/samples-auth_key):
     * decrypting the published server_DH_params_ok encrypted_answer with the
     * tmp-AES key/iv derived from the published new_nonce/server_nonce must
     * yield the documented tmp_aes_key/tmp_aes_iv and, after the SHA1-prefix
     * strip, the documented server_DH_inner_data.
     */
    public function testTmpAesKeysAndAnswerDecryptMatchOfficialTranscript(): void
    {
        $newNonce = hex2bin('BF8CB5BD9C5B4FE7CF24D64D281F89311576D53C0DA65A83267E57315414C9A6');
        $serverNonce = hex2bin('63248F6748214EAB8A2F4CC876E11974');
        $encryptedAnswer = hex2bin(
            'C334D313064174F443CE90E13C835FAEA6AE9677089A0781CC8C17ADC8FF5B5072934C1DFB1F2B9222197DE8'
            . '06186E6612E0CFA2593809B4B91B49F006FFBBD9EAAADE1EEDCA046F500A77BB538E3C2F02A4A6814DF0BC779'
            . '93E493B7F2C98344F674455A90A541070740F4B811FFF4B80B161737E0E867FF20D03BE6B52BA66F7319D03B62'
            . '1732E1C880202EAF61DF31DE831E7AC97B0FFDBFFFFA7019D399553F37B645913238443F4C560A59A5BA6AA6B'
            . 'FAEB159FD2291FCDA49A23E8009196B8062DD424F45D3B43538C68B2C070A845C260052DD3C266659FA6C0C6A8'
            . 'FF36FBFF8DAB36E06BEB5E18AFE38027FDA45E65884A503402840E21C1869101F4C613E9EDA61C2EB0AA987046'
            . 'F8069C2C002EE48A95844DD62E0E4B612257391B014D3B043D7C193F936352F9D799CC4017CA544896BB09B3B5'
            . 'B8B70C2C5E48295A82CF13BCF5FB0BA52991EC5C25188AB783CBCED3573BABB255E82741EAF4941609AEFF960A'
            . '4F2B41F92E78595141EBC73677E09104B8690D3E3C30FCA78BB0F97B4336E925BCB0C85A805458EE7B8DAD7599'
            . '388C338394FB317C0C6BD5EC2CD564177EC059E9705EFAF2048ED0B014EA89C60EE48CC547FE51CE4D0F13DEA2'
            . 'ABEA9F2425B3FA2987D960EDEF619B67921B2E92219C81F709C2924414932257D5F3EEB12D0AAF2B29511988DAC'
            . 'C966792D7E26F04EF5F78CC18F8A9C620668FD76A668AC6E6D43474BFB5CFC927C15B5D1FD531F50B7EDFFCD50'
            . 'F6F04A0884566CC858D05A2B846D69A8799D36022112ACCCA567D6B5EFE799AC93DE439A7D16CE61C16B02F89AD'
            . '9ACBD045111BC5F1'
        );

        $this->assertSame('16f548177058e8d39c41cbad4d419446beb12eb9b8f5ad28ea824b8015f17d81', bin2hex(AuthKeyFactory::tmpAesKey($newNonce, $serverNonce)));
        $this->assertSame('c4d14166c1378e35c698460047dbb6075441be9984611c28837357ebbf8cb5bd', bin2hex(AuthKeyFactory::tmpAesIv($newNonce, $serverNonce)));

        $plain = AesIge::decrypt($encryptedAnswer, AuthKeyFactory::tmpAesKey($newNonce, $serverNonce), AuthKeyFactory::tmpAesIv($newNonce, $serverNonce));
        $innerDh = AuthKeyFactory::decodeHashPrefixed($plain);
        $this->assertSame('server_DH_inner_data', $innerDh['_']);
        $this->assertSame(3, $innerDh['g']);
        $this->assertSame(256, strlen($innerDh['dh_prime']));
        $this->assertStringStartsWith('c71caeb9c6b1c9048e6c522f70f13f73980d40238e3e21c1', bin2hex($innerDh['dh_prime']));
        $this->assertSame(256, strlen($innerDh['g_a']));
        $this->assertStringStartsWith('8539db1e497692ee8bd112463f5f2669', bin2hex($innerDh['g_a']));
        $this->assertSame(1783001185, $innerDh['server_time']);
    }

    public function testDecodeHashPrefixedRejectsCorruptedHash(): void
    {
        $nonce = str_repeat("\x42", 16);
        $body = TLEncoder::encodeObject('server_DH_inner_data', [
            'nonce' => $nonce, 'server_nonce' => str_repeat("\x43", 16),
            'g' => 3, 'dh_prime' => random_bytes(256), 'g_a' => random_bytes(256), 'server_time' => 1783001185,
        ]);
        $buffer = sha1($body, true) . $body;
        AuthKeyFactory::decodeHashPrefixed($buffer); // valid prefix: must not throw

        $corrupted = "\x00" . substr($buffer, 1); // flip first digest byte
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA1');
        AuthKeyFactory::decodeHashPrefixed($corrupted);
    }

    public function testFingerprintOfEachBundledKeyStaysStable(): void
    {
        $bundle = file_get_contents(__DIR__ . '/../../src/MTProto/resources/telegram_public_key.pub');
        $this->assertNotFalse($bundle);
        $fps = [];
        foreach (explode('-----END RSA PUBLIC KEY-----', (string) $bundle) as $chunk) {
            if (!str_contains($chunk, 'BEGIN')) {
                continue;
            }
            $pem = $chunk . '-----END RSA PUBLIC KEY-----';
            $fps[] = sprintf('%016x', AuthKeyFactory::fingerprintOf($pem));
        }
        $this->assertSame(['05fd64de851d9dd0', '03268d20df9858b2'], $fps); // official transcript + test-DC keys
    }
}
