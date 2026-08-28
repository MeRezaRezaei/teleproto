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
     * server_salt := substr(new_nonce, 0, 8) XOR substr(server_nonce, 0, 8)
     */
    public static function serverSalt(string $newNonce, string $serverNonce): string
    {
        return substr($newNonce, 0, 8) ^ substr($serverNonce, 0, 8);
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
        foreach (self::bundledPublicKeys() as $candidate) {
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

        $authKey = self::bigToBytes($gA->modPow($b, $dhPrime));
        $authKey = str_pad($authKey, 256, "\x00", STR_PAD_LEFT);

        if (!hash_equals(self::newNonceHash1($newNonce, $authKey), $authObj['new_nonce_hash1'])) {
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
     * Extracts the PKCS#1 RSAPublicKey DER of the first RSA public key PEM
     * block in $pem; non-RSA/SPKI inputs are normalized through phpseclib.
     */
    protected static function pkcs1DerOf(string $pem): string
    {
        if (preg_match('/-----BEGIN RSA PUBLIC KEY-----(.*?)-----END RSA PUBLIC KEY-----/s', $pem, $m)) {
            $der = base64_decode((string)preg_replace('/\s+/', '', $m[1]), true);
            if ($der === false) {
                throw new RuntimeException('AuthKeyFactory: invalid base64 in RSA public key PEM');
            }
            return $der;
        }
        $pkcs1 = PublicKeyLoader::load($pem)->toString('PKCS1');
        if (!preg_match('/-----BEGIN RSA PUBLIC KEY-----(.*?)-----END RSA PUBLIC KEY-----/s', $pkcs1, $m)) {
            throw new RuntimeException('AuthKeyFactory: unexpected RSA key format');
        }
        $der = base64_decode((string)preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false) {
            throw new RuntimeException('AuthKeyFactory: invalid base64 in RSA public key PEM');
        }
        return $der;
    }

    /** @return list<string> PEM blocks bundled in the resource file */
    protected static function bundledPublicKeys(): array
    {
        $contents = file_get_contents(self::RESOURCE_PATH);
        if ($contents === false) {
            throw new RuntimeException('AuthKeyFactory: bundled public key resource missing');
        }
        preg_match_all('/-----BEGIN RSA PUBLIC KEY-----.*?-----END RSA PUBLIC KEY-----/s', $contents, $m);
        if ($m[0] === []) {
            throw new RuntimeException('AuthKeyFactory: no public keys found in resource file');
        }
        return $m[0];
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
