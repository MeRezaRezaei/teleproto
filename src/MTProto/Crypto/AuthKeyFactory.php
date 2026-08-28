<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\MTProto\TL\TLDecoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLEncoder;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\Math\BigInteger;
use RuntimeException;

/**
 * Full MTProto 2.0 authorization-key handshake:
 * req_pq_multi -> req_DH_params -> set_client_DH_params.
 *
 * Cryptographic recipes verified offline against the official sample
 * handshake transcript: https://core.telegram.org/mtproto/samples-auth_key
 */
class AuthKeyFactory
{
    private const RESOURCE_PATH = __DIR__ . '/../resources/telegram_public_key.pub';

    /**
     * The official 2048-bit safe prime Telegram's production DCs use as
     * dh_prime (the one published in the core.telegram.org sample handshake
     * and hardcoded by TDLib-class clients). Extracted from the official
     * transcript at https://core.telegram.org/mtproto/samples-auth_key .
     */
    public const KNOWN_DH_PRIME_HEX =
        'c71caeb9c6b1c9048e6c522f70f13f73980d40238e3e21c14934d037563d930f'
        . '48198a0aa7c14058229493d22530f4dbfa336f6e0ac925139543aed44cce7c372'
        . '0fd51f69458705ac68cd4fe6b6b13abdc9746512969328454f18faf8c595f64247'
        . '7fe96bb2a941d5bcd1d4ac8cc49880708fa9b378e3c4f3a9060bee67cf9a4a4a69'
        . '5811051907e162753b56b0f6b410dba74d8a84b2a14b3144e0ef1284754fd17ed9'
        . '50d5965b4b9dd46582db1178d169c6bc465b0d6ff9ca3928fef5b9ae4e418fc15e'
        . '83ebea0f87fa9ff5eed70050ded2849f47bf959d956850ce929851f0d8115f635'
        . 'b105ee2e4e15d04b2454bf6f4fadf034b10403119cd8e3b92fcc5b';

    /**
     * server_salt := substr(new_nonce, 0, 8) XOR substr(server_nonce, 0, 8)
     */
    public static function serverSalt(string $newNonce, string $serverNonce): string
    {
        return substr($newNonce, 0, 8) ^ substr($serverNonce, 0, 8);
    }

    /**
     * Validates the server's DH parameters against the official spec:
     * dh_prime must be the KNOWN 2048-bit safe prime (equality check with
     * the published constant — generic primality testing is unnecessary for
     * a fixed server constant) and g must lie in 2..7.
     *
     * @throws RuntimeException naming the offending field on violation
     */
    public static function assertKnownDhParams(int $g, string $dhPrime): void
    {
        if ($g < 2 || $g > 7) {
            throw new RuntimeException(sprintf('AuthKeyFactory: g must be in 2..7, got %d', $g));
        }
        if (!hash_equals(hex2bin(self::KNOWN_DH_PRIME_HEX), $dhPrime)) {
            throw new RuntimeException('AuthKeyFactory: dh_prime is not the official 2048-bit MTProto safe prime');
        }
    }

    /**
     * Validates the nonce/server_nonce echo of a server handshake response
     * (server_DH_params_ok, server_DH_inner_data, dh_gen_ok) against the
     * values the client generated, per core.telegram.org/mtproto/auth_key.
     *
     * @param array<string, mixed> $obj decoded server response carrying nonce fields
     * @throws RuntimeException naming the mismatched field on violation
     */
    public static function assertNonceEcho(array $obj, string $nonce, string $serverNonce, string $context): void
    {
        if (!hash_equals($nonce, (string)($obj['nonce'] ?? ''))) {
            throw new RuntimeException(sprintf('AuthKeyFactory: %s nonce mismatch', $context));
        }
        if (!hash_equals($serverNonce, (string)($obj['server_nonce'] ?? ''))) {
            throw new RuntimeException(sprintf('AuthKeyFactory: %s server_nonce mismatch', $context));
        }
    }

    /**
     * new_nonce_hash1 = 128 lower-order bits (final 16 bytes) of
     * SHA1(new_nonce || 0x01 || auth_key_aux_hash), where auth_key_aux_hash
     * is the 64 higher-order bits (first 8 bytes) of SHA1(auth_key).
     */
    public static function newNonceHash1(string $newNonce, string $authKey): string
    {
        $auxHash = substr(sha1($authKey, true), 0, 8);
        return substr(sha1($newNonce . "\x01" . $auxHash, true), 4, 16);
    }

    /**
     * The fingerprint as the raw 8 bytes transmitted in the TL long:
     * final 8 bytes of SHA1(TL_string(n) || TL_string(e)) over the
     * PKCS#1 RSAPublicKey, matching the rsa_public_key n:string e:string
     * representation documented at core.telegram.org/mtproto/auth_key and
     * implemented by official clients. Verified to yield 85fd64de851d9dd0
     * for the official sample-transcript key.
     */
    public static function fingerprintBytesOf(string $pem): string
    {
        $der = self::pkcs1DerOf($pem);
        $decoded = ASN1::decodeBER($der);
        if ($decoded === null) {
            throw new RuntimeException('AuthKeyFactory: unable to decode RSA public key DER');
        }
        $mapped = ASN1::asn1map($decoded[0], Maps\RSAPublicKey::MAP);
        if (!is_array($mapped)) {
            throw new RuntimeException('AuthKeyFactory: unexpected RSA key structure');
        }
        $hash = sha1(
            TLSerializer::packString($mapped['modulus']->toBytes())
                . TLSerializer::packString($mapped['publicExponent']->toBytes()),
            true
        );
        return substr($hash, 12, 8);
    }

    /**
     * Lower 63 bits (positive int, big-endian) of the fingerprint.
     * Real server fingerprints occupy the full 64 bits, so generate()
     * compares modulo the sign bit and echoes the server's wire value.
     */
    public static function fingerprintOf(string $pemOrDer): int
    {
        $bytes = self::fingerprintBytesOf($pemOrDer);
        $bytes[0] = chr(ord($bytes[0]) & 0x7f);
        return (int)(new BigInteger(bin2hex($bytes), 16))->toString();
    }

    public static function generate(PlainConnection $conn, int $dcId): SessionData
    {
        $nonce = random_bytes(16);
        $newNonce = random_bytes(32);

        // --- Step 1: req_pq_multi
        $resPq = $conn->request(TLEncoder::encodeObject('req_pq_multi', ['nonce' => $nonce]));
        $offset = 0;
        $resPqObj = TLDecoder::decodeObject($resPq, $offset);
        if ($resPqObj['_'] !== 'resPQ' || $resPqObj['nonce'] !== $nonce) {
            throw new RuntimeException('AuthKeyFactory: bad resPQ');
        }
        $serverNonce = $resPqObj['server_nonce'];
        [$p, $q] = PqFactorizer::factorize($resPqObj['pq']);

        // --- select the bundled public key whose fingerprint the server listed
        $pem = null;
        $fingerprint = 0;
        foreach (static::bundledPublicKeys() as $candidate) {
            $own = self::fingerprintBytesOf($candidate);
            $own[0] = chr(ord($own[0]) & 0x7f);
            foreach ($resPqObj['server_public_key_fingerprints'] as $serverFingerprint) {
                $wire = pack('P', (int)$serverFingerprint);
                $wire[0] = chr(ord($wire[0]) & 0x7f);
                if ($wire === $own) {
                    $pem = $candidate;
                    $fingerprint = (int)$serverFingerprint;
                    break 2;
                }
            }
        }
        if ($pem === null) {
            throw new RuntimeException('AuthKeyFactory: server fingerprints do not include a bundled public key');
        }

        // --- RSA-PAD encryption of p_q_inner_data_dc (https://core.telegram.org/mtproto/protocols#rsa-pad)
        $inner = TLEncoder::encodeObject('p_q_inner_data_dc', [
            'pq' => $resPqObj['pq'], 'p' => $p, 'q' => $q,
            'nonce' => $nonce, 'server_nonce' => $serverNonce, 'new_nonce' => $newNonce,
            'dc' => $dcId,
        ]);
        $encryptedInner = self::rsaPadEncrypt($pem, $inner);

        // --- Step 2: req_DH_params
        $reqDh = TLEncoder::encodeObject('req_DH_params', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce,
            'p' => $p, 'q' => $q,
            'public_key_fingerprint' => $fingerprint,
            'encrypted_data' => $encryptedInner,
        ]);
        $serverDh = $conn->request($reqDh);
        $offset = 0;
        $serverDhObj = TLDecoder::decodeObject($serverDh, $offset);
        if ($serverDhObj['_'] !== 'server_DH_params_ok') {
            throw new RuntimeException('AuthKeyFactory: DH params rejected (' . $serverDhObj['_'] . ')');
        }
        self::assertNonceEcho($serverDhObj, $nonce, $serverNonce, 'server_DH_params_ok');

        // tmp_aes_key := SHA1(new_nonce + server_nonce) + substr(SHA1(server_nonce + new_nonce), 0, 12)
        // tmp_aes_iv  := substr(SHA1(server_nonce + new_nonce), 12, 8) + SHA1(new_nonce + new_nonce)
        //               + substr(new_nonce, 0, 4)
        $aesKey = self::tmpAesKey($newNonce, $serverNonce);
        $aesIv = self::tmpAesIv($newNonce, $serverNonce);

        $plainDh = AesIge::decrypt($serverDhObj['encrypted_answer'], $aesKey, $aesIv);
        $innerDhObj = self::decodeHashPrefixed($plainDh);
        if ($innerDhObj['_'] !== 'server_DH_inner_data') {
            throw new RuntimeException('AuthKeyFactory: bad server_DH_inner_data');
        }
        self::assertNonceEcho($innerDhObj, $nonce, $serverNonce, 'server_DH_inner_data');
        self::assertKnownDhParams((int)$innerDhObj['g'], (string)$innerDhObj['dh_prime']);

        // --- Step 3: set_client_DH_params
        $g = new BigInteger((string)$innerDhObj['g']);
        $dhPrime = new BigInteger(bin2hex($innerDhObj['dh_prime']), 16);
        $gA = new BigInteger(bin2hex($innerDhObj['g_a']), 16);
        $one = new BigInteger(1);
        if ($gA->compare($one) <= 0 || $gA->compare($dhPrime->subtract($one)) >= 0) {
            throw new RuntimeException('AuthKeyFactory: g_a out of range');
        }
        $b = new BigInteger(random_bytes(256), 256);
        $gB = $g->modPow($b, $dhPrime);

        $clientData = TLEncoder::encodeObject('client_DH_inner_data', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce,
            'retry_id' => 0,
            'g_b' => self::bigToBytes($gB),
        ]);
        $clientInner = sha1($clientData, true) . $clientData;
        $padLen = (16 - (strlen($clientInner) % 16)) % 16;
        $clientInner .= $padLen > 0 ? random_bytes($padLen) : '';
        $clientEncrypted = AesIge::encrypt($clientInner, $aesKey, $aesIv);

        $setDh = TLEncoder::encodeObject('set_client_DH_params', [
            'nonce' => $nonce, 'server_nonce' => $serverNonce, 'encrypted_data' => $clientEncrypted,
        ]);
        $authRes = $conn->request($setDh);
        $offset = 0;
        $authObj = TLDecoder::decodeObject($authRes, $offset);
        if ($authObj['_'] !== 'dh_gen_ok') {
            throw new RuntimeException('AuthKeyFactory: DH generation failed (' . $authObj['_'] . ')');
        }
        self::assertNonceEcho($authObj, $nonce, $serverNonce, 'dh_gen_ok');

        $authKey = self::bigToBytes($gA->modPow($b, $dhPrime));
        $authKey = str_pad($authKey, 256, "\x00", STR_PAD_LEFT);

        if (!hash_equals(self::newNonceHash1($newNonce, $authKey), (string)$authObj['new_nonce_hash1'])) {
            throw new RuntimeException('AuthKeyFactory: new_nonce_hash1 mismatch');
        }

        return new SessionData(
            dcId: $dcId,
            authKey: $authKey,
            serverTimeDelta: (int)$innerDhObj['server_time'] - time()
        );
    }

    /**
     * tmp_aes_key := SHA1(new_nonce + server_nonce) || substr(SHA1(server_nonce + new_nonce), 0, 12)
     * (32 bytes; verified against the official sample handshake transcript).
     */
    public static function tmpAesKey(string $newNonce, string $serverNonce): string
    {
        return sha1($newNonce . $serverNonce, true) . substr(sha1($serverNonce . $newNonce, true), 0, 12);
    }

    /**
     * tmp_aes_iv := substr(SHA1(server_nonce + new_nonce), 12, 8) || SHA1(new_nonce + new_nonce)
     *              || substr(new_nonce, 0, 4)  (32 bytes; transcript-verified).
     */
    public static function tmpAesIv(string $newNonce, string $serverNonce): string
    {
        $sha1SnNn = sha1($serverNonce . $newNonce, true);
        return substr($sha1SnNn, 12, 8) . sha1($newNonce . $newNonce, true) . substr($newNonce, 0, 4);
    }

    /**
     * Decodes a SHA1-prefixed payload: SHA1(data) + data + 0-15 padding bytes.
     * Throws when the SHA1 prefix does not cover the decoded object.
     *
     * @return array<string, mixed> the decoded object
     */
    public static function decodeHashPrefixed(string $buffer): array
    {
        if (strlen($buffer) < 20) {
            throw new RuntimeException('AuthKeyFactory: hash-prefixed buffer too short');
        }
        $offset = 20;
        $object = TLDecoder::decodeObject($buffer, $offset);
        if (!hash_equals(substr($buffer, 0, 20), sha1(substr($buffer, 20, $offset - 20), true))) {
            throw new RuntimeException('AuthKeyFactory: SHA1 prefix mismatch');
        }

        return $object;
    }

    /**
     * RSA-PAD encryption for the DH handshake inner data
     * (https://core.telegram.org/mtproto/protocols#rsa-pad):
     *
     *   data_with_padding  = inner_data + random bytes, exactly 192 bytes
     *   data_pad_reversed  = strrev(data_with_padding)
     *   data_with_hash     = data_pad_reversed + SHA256(temp_key + data_with_padding)
     *   aes_encrypted      = AES-IGE(data_with_hash, temp_key, zero IV)
     *   payload            = (temp_key ^ SHA256(aes_encrypted)) + aes_encrypted   // 256 bytes
     *   ciphertext         = payload^e mod n   (raw RSA, retried while payload >= n)
     */
    public static function rsaPadEncrypt(string $pem, string $innerData): string
    {
        if (strlen($innerData) > 144) {
            throw new RuntimeException('AuthKeyFactory: p_q_inner_data exceeds 144 bytes');
        }

        $components = \phpseclib3\Crypt\RSA\Formats\Keys\PKCS1::load(self::pkcs1DerOf($pem));
        $n = $components['modulus'];
        $e = $components['publicExponent'];

        $dataWithPadding = $innerData . random_bytes(192 - strlen($innerData));
        $reversed = strrev($dataWithPadding);

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $tempKey = random_bytes(32);
            $dataWithHash = $reversed . hash('sha256', $tempKey . $dataWithPadding, true);
            $aesEncrypted = AesIge::encrypt($dataWithHash, $tempKey, str_repeat("\x00", 32));
            $payload = ($tempKey ^ hash('sha256', $aesEncrypted, true)) . $aesEncrypted;

            $m = new BigInteger($payload, 256);
            if ($m->compare($n) < 0) {
                return str_pad($m->powMod($e, $n)->toBytes(), 256, "\x00", STR_PAD_LEFT);
            }
        }

        throw new RuntimeException('AuthKeyFactory: RSA-PAD failed to generate payload < n within 16 attempts');
    }

    /**
     * Normalizes any supported public key input (PKCS#1 PEM, SPKI PEM, DER)
     * to the PKCS#1 RSAPublicKey DER via phpseclib — no byte scraping.
     * phpseclib loads the FIRST key when several PEM blocks are bundled.
     */
    protected static function pkcs1DerOf(string $pem): string
    {
        $key = PublicKeyLoader::load($pem);
        $pkcs1Pem = $key->toString('PKCS1');
        $body = '';
        foreach (explode("\n", $pkcs1Pem) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-----')) {
                continue;
            }
            $body .= $line;
        }
        return (string) base64_decode($body, true);
    }

    /** @return list<string> PEM blocks bundled in the resource file */
    protected static function bundledPublicKeys(): array
    {
        $contents = file_get_contents(self::RESOURCE_PATH);
        if ($contents === false) {
            throw new RuntimeException('AuthKeyFactory: bundled public key resource missing');
        }
        $blocks = [];
        $current = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($current === []) {
                if (str_starts_with($line, '-----BEGIN')) {
                    $current[] = $line;
                }
                continue;
            }
            $current[] = $line;
            if (str_starts_with($line, '-----END')) {
                $blocks[] = implode("\n", $current);
                $current = [];
            }
        }
        if ($blocks === []) {
            throw new RuntimeException('AuthKeyFactory: no public keys found in resource file');
        }
        return $blocks;
    }

    protected static function bigToBytes(BigInteger $n): string
    {
        $hex = $n->toHex();
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }
}
