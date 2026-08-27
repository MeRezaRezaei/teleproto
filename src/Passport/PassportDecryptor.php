<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Passport;

use RuntimeException;

/**
 * Telegram Passport Decryption Helper.
 * Decrypts end-to-end encrypted Telegram Passport identity credentials and documents.
 */
class PassportDecryptor
{
    /**
     * Decrypts Telegram Passport credentials received by a bot.
     *
     * @param string $encryptedData Base64-encoded encrypted data from Telegram
     * @param string $encryptedSecret Base64-encoded secret encrypted with bot's RSA public key
     * @param string $privateKeyPem Bot's RSA private key in PEM format
     * @param string $hash SHA-256 hash provided by Telegram for data integrity check
     * @return array<string, mixed> Decrypted JSON passport credentials
     */
    public static function decryptCredentials(
        string $encryptedData,
        string $encryptedSecret,
        string $privateKeyPem,
        string $hash
    ): array {
        $rawEncryptedData = base64_decode($encryptedData, true);
        $rawEncryptedSecret = base64_decode($encryptedSecret, true);

        if ($rawEncryptedData === false || $rawEncryptedSecret === false) {
            throw new RuntimeException("Invalid base64 passport payload.");
        }

        // 1. Decrypt the secret using the Bot's RSA Private Key (OAEP / PKCS1 padding)
        $decryptedSecret = '';
        $success = openssl_private_decrypt(
            $rawEncryptedSecret,
            $decryptedSecret,
            $privateKeyPem,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        if (!$success) {
            // Fallback to standard PKCS1 padding if OAEP fails
            $success = openssl_private_decrypt(
                $rawEncryptedSecret,
                $decryptedSecret,
                $privateKeyPem,
                OPENSSL_PKCS1_PADDING
            );
        }

        if (!$success || empty($decryptedSecret)) {
            throw new RuntimeException("Failed to decrypt Telegram Passport secret with private key.");
        }

        // 2. Derive AES Key and IV from the decrypted secret and payload hash
        // key = SHA512(secret + hash)[0..32], iv = SHA512(secret + hash)[32..48]
        $secretHash = hash('sha512', $decryptedSecret . base64_decode($hash, true), true);
        $aesKey = substr($secretHash, 0, 32);
        $aesIv = substr($secretHash, 32, 16);

        // 3. Decrypt data using AES-256-CBC
        $decryptedData = openssl_decrypt(
            $rawEncryptedData,
            'aes-256-cbc',
            $aesKey,
            OPENSSL_RAW_DATA,
            $aesIv
        );

        if ($decryptedData === false) {
            throw new RuntimeException("Failed to decrypt Passport AES payload.");
        }

        // 4. Verify data integrity against hash
        $calculatedHash = hash('sha256', $decryptedData, true);
        if (!hash_equals($calculatedHash, base64_decode($hash, true))) {
            throw new RuntimeException("Passport data integrity check failed: hash mismatch.");
        }

        return json_decode($decryptedData, true) ?: [];
    }
}
