<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests\Wire;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\Formats\Keys\PKCS1;
use phpseclib3\Math\BigInteger;
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
        $this->assertSame(AuthKeyFactory::KNOWN_DH_PRIME_HEX, bin2hex($innerDh['dh_prime']));
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

    // --- official-spec validation hardening (audit fix #3) ---

    public function testAssertKnownDhParamsAcceptsOfficialPrimeForEveryAllowedG(): void
    {
        $prime = hex2bin(AuthKeyFactory::KNOWN_DH_PRIME_HEX);
        $this->assertSame(256, strlen($prime));
        foreach ([2, 3, 4, 5, 6, 7] as $g) {
            AuthKeyFactory::assertKnownDhParams($g, $prime); // must not throw
        }
        $this->assertTrue(true);
    }

    public function testAssertKnownDhParamsRejectsForeignPrime(): void
    {
        $tampered = hex2bin(AuthKeyFactory::KNOWN_DH_PRIME_HEX);
        $tampered[200] = chr(ord($tampered[200]) ^ 0x01);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dh_prime');
        AuthKeyFactory::assertKnownDhParams(3, $tampered);
    }

    public function testAssertKnownDhParamsRejectsGBelowTwo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('g');
        AuthKeyFactory::assertKnownDhParams(1, hex2bin(AuthKeyFactory::KNOWN_DH_PRIME_HEX));
    }

    public function testAssertKnownDhParamsRejectsGAboveSeven(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('g');
        AuthKeyFactory::assertKnownDhParams(8, hex2bin(AuthKeyFactory::KNOWN_DH_PRIME_HEX));
    }

    public function testAssertNonceEchoVerifiesBothNonces(): void
    {
        $nonce = random_bytes(16);
        $serverNonce = random_bytes(16);
        AuthKeyFactory::assertNonceEcho(
            ['nonce' => $nonce, 'server_nonce' => $serverNonce],
            $nonce,
            $serverNonce,
            'ctx'
        ); // exact echo: must not throw

        try {
            AuthKeyFactory::assertNonceEcho(
                ['nonce' => self::flip($nonce), 'server_nonce' => $serverNonce],
                $nonce,
                $serverNonce,
                'ctx'
            );
            $this->fail('mismatched nonce must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('AuthKeyFactory: ctx nonce mismatch', $e->getMessage());
        }

        try {
            AuthKeyFactory::assertNonceEcho(
                ['nonce' => $nonce, 'server_nonce' => self::flip($serverNonce)],
                $nonce,
                $serverNonce,
                'ctx'
            );
            $this->fail('mismatched server_nonce must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('AuthKeyFactory: ctx server_nonce mismatch', $e->getMessage());
        }
        $this->assertTrue(true);
    }

    public function testGenerateCompletesFullOfflineHandshakeAgainstFakeServer(): void
    {
        $server = new FakeHandshakeServer();
        $session = $this->generateViaFakeServer($server);

        $this->assertSame(256, strlen($session->authKey));
        $this->assertSame($server->serverAuthKey, $session->authKey); // both sides derived the same key
        $this->assertSame(2, $session->dcId);
    }

    public function testGenerateRejectsServerDhParamsWithMismatchedNonce(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperDhParamsNonce = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('server_DH_params_ok nonce mismatch');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsServerDhParamsWithMismatchedServerNonce(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperDhParamsServerNonce = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('server_DH_params_ok server_nonce mismatch');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsServerDhInnerDataWithMismatchedNonce(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperInnerNonce = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('server_DH_inner_data nonce mismatch');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsForeignDhPrime(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperDhPrime = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dh_prime');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsGOne(): void
    {
        $server = new FakeHandshakeServer();
        $server->overrideG = 1;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('g');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsGEight(): void
    {
        $server = new FakeHandshakeServer();
        $server->overrideG = 8;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('g');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsDhGenOkWithMismatchedNonce(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperGenNonce = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dh_gen_ok nonce mismatch');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsDhGenOkWithMismatchedServerNonce(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperGenServerNonce = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dh_gen_ok server_nonce mismatch');
        $this->generateViaFakeServer($server);
    }

    public function testGenerateRejectsDhGenOkWithWrongNewNonceHash1(): void
    {
        $server = new FakeHandshakeServer();
        $server->tamperGenHash1 = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('new_nonce_hash1');
        $this->generateViaFakeServer($server);
    }

    /** Drives AuthKeyFactory::generate() against an in-process fake DC pinned to a test-only RSA key. */
    private function generateViaFakeServer(FakeHandshakeServer $server): SessionData
    {
        $factoryClass = get_class(new class extends AuthKeyFactory {
            public static ?string $pem = null;

            /** @return list<string> */
            protected static function bundledPublicKeys(): array
            {
                if (self::$pem === null) {
                    throw new RuntimeException('test factory: pem not configured');
                }
                return [self::$pem];
            }
        });
        $factoryClass::$pem = FakeHandshakeServer::keyPem();

        $conn = new class(fopen('php://memory', 'r')) extends PlainConnection {
            public static ?FakeHandshakeServer $server = null;

            public function request(string $payload): string
            {
                $server = self::$server;
                if ($server === null) {
                    throw new RuntimeException('fake connection: server not configured');
                }
                return $server->respond($payload);
            }
        };
        $conn::$server = $server;

        return $factoryClass::generate($conn, 2);
    }

    private static function flip(string $bytes): string
    {
        $bytes[0] = chr(ord($bytes[0]) ^ 0x01);
        return $bytes;
    }
}

/**
 * In-process fake Telegram DC for offline AuthKeyFactory::generate() coverage:
 * speaks the whole handshake (req_pq_multi / req_DH_params / set_client_DH_params)
 * under a locally generated 2048-bit RSA key, performing a genuine RSA-PAD unwrap
 * of req_DH_params and re-encrypting server_DH_inner_data / dh_gen_ok with the
 * tmp-AES keys derived from the recovered new_nonce. Tamper switches let tests
 * forge spec-violating responses for the negative paths.
 */
final class FakeHandshakeServer
{
    public bool $tamperDhParamsNonce = false;
    public bool $tamperDhParamsServerNonce = false;
    public bool $tamperInnerNonce = false;
    public bool $tamperDhPrime = false;
    public ?int $overrideG = null;
    public bool $tamperGenNonce = false;
    public bool $tamperGenServerNonce = false;
    public bool $tamperGenHash1 = false;

    public string $serverAuthKey = '';

    /** @var array{pem: string, n: BigInteger, e: BigInteger, d: BigInteger}|null */
    private static ?array $key = null;

    private string $nonce = '';
    private string $serverNonce = '';
    private string $newNonce = '';
    private BigInteger $gaSecret;

    public static function keyPem(): string
    {
        if (self::$key === null) {
            $private = RSA::createKey(2048);
            $pem = $private->getPublicKey()->toString('PKCS1'); // client side only ever sees the public key
            $body = '';
            foreach (explode("\n", $private->toString('PKCS1')) as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '-----')) {
                    $body .= $line;
                }
            }
            $components = PKCS1::load((string) base64_decode($body, true));
            self::$key = [
                'pem' => $pem,
                'n' => $components['modulus'],
                'e' => $components['publicExponent'],
                'd' => $components['privateExponent'],
            ];
        }
        return self::$key['pem'];
    }

    public function respond(string $request): string
    {
        $offset = 0;
        $req = TLDecoder::decodeObject($request, $offset);
        return match ($req['_']) {
            'req_pq_multi' => $this->resPq((string) $req['nonce']),
            'req_DH_params' => $this->serverDhParams($req),
            'set_client_DH_params' => $this->dhGenResult($req),
            default => throw new RuntimeException('fake server: unexpected constructor ' . $req['_']),
        };
    }

    private function resPq(string $nonce): string
    {
        $this->nonce = $nonce;
        $this->serverNonce = random_bytes(16);
        // pq = 101 * 65537 (small semiprime, instantly factorized by PqFactorizer)
        $pq = (new BigInteger(101))->multiply(new BigInteger(65537))->toBytes();
        // wire fingerprint: the raw SHA1[12:20] bytes read as a little-endian
        // long — exactly what generate()'s candidate loop packs and compares
        $wireFingerprint = unpack('P', AuthKeyFactory::fingerprintBytesOf(self::keyPem()))[1];
        return TLEncoder::encodeObject('resPQ', [
            'nonce' => $nonce,
            'server_nonce' => $this->serverNonce,
            'pq' => $pq,
            'server_public_key_fingerprints' => [$wireFingerprint],
        ]);
    }

    /** @param array<string, mixed> $req */
    private function serverDhParams(array $req): string
    {
        self::keyPem();
        $key = self::$key ?? throw new RuntimeException('fake server: key not booted');

        // RSA-PAD unwrap (reverse of AuthKeyFactory::rsaPadEncrypt) to recover new_nonce
        $payload = str_pad(
            (new BigInteger((string) $req['encrypted_data'], 256))->powMod($key['d'], $key['n'])->toBytes(),
            256,
            "\x00",
            STR_PAD_LEFT
        );
        $tempKey = substr($payload, 0, 32) ^ hash('sha256', substr($payload, 32), true);
        $dataWithHash = AesIge::decrypt(substr($payload, 32), $tempKey, str_repeat("\x00", 32));
        $dataWithPadding = strrev(substr($dataWithHash, 0, 192));
        if (!hash_equals(substr($dataWithHash, 192), hash('sha256', $tempKey . $dataWithPadding, true))) {
            throw new RuntimeException('fake server: RSA-PAD hash mismatch');
        }
        $offset = 0;
        $inner = TLDecoder::decodeObject($dataWithPadding, $offset);
        if ($inner['_'] !== 'p_q_inner_data_dc'
            || $inner['nonce'] !== $this->nonce
            || $inner['server_nonce'] !== $this->serverNonce) {
            throw new RuntimeException('fake server: bad p_q_inner_data_dc');
        }
        $this->newNonce = (string) $inner['new_nonce'];

        $g = new BigInteger((string) ($this->overrideG ?? 3));
        $primeBytes = hex2bin(AuthKeyFactory::KNOWN_DH_PRIME_HEX);
        if ($this->tamperDhPrime && $primeBytes !== false) {
            $primeBytes[200] = chr(ord($primeBytes[200]) ^ 0x01);
        }
        $prime = new BigInteger(bin2hex((string) $primeBytes), 16);
        $this->gaSecret = new BigInteger(random_bytes(256), 256);
        $gA = str_pad($g->modPow($this->gaSecret, $prime)->toBytes(), 256, "\x00", STR_PAD_LEFT);

        $innerData = TLEncoder::encodeObject('server_DH_inner_data', [
            'nonce' => $this->tamperInnerNonce ? self::flip($this->nonce) : $this->nonce,
            'server_nonce' => $this->serverNonce,
            'g' => (int) $g->toString(),
            'dh_prime' => (string) $primeBytes,
            'g_a' => $gA,
            'server_time' => time(),
        ]);
        $padded = sha1($innerData, true) . $innerData;
        $padLen = (16 - (strlen($padded) % 16)) % 16;
        $padded .= $padLen > 0 ? random_bytes($padLen) : '';
        $encryptedAnswer = AesIge::encrypt(
            $padded,
            AuthKeyFactory::tmpAesKey($this->newNonce, $this->serverNonce),
            AuthKeyFactory::tmpAesIv($this->newNonce, $this->serverNonce)
        );

        return TLEncoder::encodeObject('server_DH_params_ok', [
            'nonce' => $this->tamperDhParamsNonce ? self::flip($this->nonce) : $this->nonce,
            'server_nonce' => $this->tamperDhParamsServerNonce ? self::flip($this->serverNonce) : $this->serverNonce,
            'encrypted_answer' => $encryptedAnswer,
        ]);
    }

    /** @param array<string, mixed> $req */
    private function dhGenResult(array $req): string
    {
        $plain = AesIge::decrypt(
            (string) $req['encrypted_data'],
            AuthKeyFactory::tmpAesKey($this->newNonce, $this->serverNonce),
            AuthKeyFactory::tmpAesIv($this->newNonce, $this->serverNonce)
        );
        $clientInner = AuthKeyFactory::decodeHashPrefixed($plain);
        if ($clientInner['_'] !== 'client_DH_inner_data') {
            throw new RuntimeException('fake server: bad client_DH_inner_data');
        }
        $prime = new BigInteger(AuthKeyFactory::KNOWN_DH_PRIME_HEX, 16);
        $gB = new BigInteger(bin2hex((string) $clientInner['g_b']), 16);
        $this->serverAuthKey = str_pad($gB->modPow($this->gaSecret, $prime)->toBytes(), 256, "\x00", STR_PAD_LEFT);

        $hash1 = AuthKeyFactory::newNonceHash1($this->newNonce, $this->serverAuthKey);
        return TLEncoder::encodeObject('dh_gen_ok', [
            'nonce' => $this->tamperGenNonce ? self::flip($this->nonce) : $this->nonce,
            'server_nonce' => $this->tamperGenServerNonce ? self::flip($this->serverNonce) : $this->serverNonce,
            'new_nonce_hash1' => $this->tamperGenHash1 ? self::flip($hash1) : $hash1,
        ]);
    }

    private static function flip(string $bytes): string
    {
        $bytes[0] = chr(ord($bytes[0]) ^ 0x01);
        return $bytes;
    }
}
