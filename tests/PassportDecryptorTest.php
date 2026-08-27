<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\Teleproto\Passport\PassportDecryptor;

class PassportDecryptorTest extends TestCase
{
    public function testPassportDecryptionAndVerification(): void
    {
        // 1. Generate an RSA Key Pair for the Bot
        $rsaKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($rsaKey, $privateKeyPem);
        $publicKeyDetails = openssl_pkey_get_details($rsaKey);
        $publicKeyPem = $publicKeyDetails['key'];

        // 2. Prepare Sample Passport Data (Identity info)
        $passportData = [
            'personal_details' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'birth_date' => '1990-01-01',
                'country_code' => 'US',
            ]
        ];
        $plainJson = json_encode($passportData);
        $hash = hash('sha256', $plainJson, true);

        // 3. Simulate Telegram encrypting the secret with Bot's Public Key
        $secret = random_bytes(32);
        openssl_public_encrypt($secret, $encryptedSecret, $publicKeyPem, OPENSSL_PKCS1_OAEP_PADDING);

        // 4. Derive AES key and IV
        $secretHash = hash('sha512', $secret . $hash, true);
        $aesKey = substr($secretHash, 0, 32);
        $aesIv = substr($secretHash, 32, 16);

        // 5. Encrypt data with AES-256-CBC
        $encryptedData = openssl_encrypt($plainJson, 'aes-256-cbc', $aesKey, OPENSSL_RAW_DATA, $aesIv);

        // 6. Test PassportDecryptor
        $decrypted = PassportDecryptor::decryptCredentials(
            encryptedData: base64_encode($encryptedData),
            encryptedSecret: base64_encode($encryptedSecret),
            privateKeyPem: $privateKeyPem,
            hash: base64_encode($hash)
        );

        $this->assertEquals('John', $decrypted['personal_details']['first_name']);
        $this->assertEquals('Doe', $decrypted['personal_details']['last_name']);
        $this->assertEquals('US', $decrypted['personal_details']['country_code']);
    }
}
