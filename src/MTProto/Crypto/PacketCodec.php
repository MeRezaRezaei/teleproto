<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Crypto;

use RuntimeException;

/**
 * MTProto 2.0 Binary Packet Codec.
 * Encrypts and decrypts client-server messages adhering to MTProto 2.0 cryptographic wire specs.
 */
class PacketCodec
{
    /**
     * Computes the 64-bit AuthKey ID (lower 8 bytes of SHA1(auth_key)).
     */
    public static function computeAuthKeyId(string $authKey): string
    {
        return substr(hash('sha1', $authKey, true), 12, 8);
    }

    /**
     * Generates a unique 64-bit MTProto message ID based on current Unix timestamp.
     * message_id must be strictly increasing and roughly match server time.
     */
    public static function generateMessageId(int $serverTimeDelta = 0): int
    {
        $time = microtime(true) + $serverTimeDelta;
        $seconds = (int)$time;
        $nanoseconds = (int)(($time - $seconds) * 1e9);

        $msgId = ($seconds << 32) | ($nanoseconds & 0xFFFFFFFC);
        return $msgId;
    }

    /**
     * Encrypts an MTProto 2.0 message packet.
     *
     * @param string $payload Raw TL binary payload
     * @param string $authKey 256-byte shared authentication key
     * @param int $sessionId 64-bit random session identifier
     * @param int $serverSalt 64-bit server salt
     * @param int $seqNo Message sequence number
     * @param int $serverTimeDelta Timestamp delta in seconds
     * @param bool $toServer Whether packet is being sent to Telegram server (true) or to client (false)
     * @param int|null $messageId Optional explicit message id (encrypted client content
     *                            messages use ids ≡ 1 mod 4); null keeps the internally
     *                            generated id (≡ 0 mod 4, plain-message convention)
     * @return string Encrypted binary packet ready for TCP socket
     */
    public static function encryptPacket(
        string $payload,
        string $authKey,
        int $sessionId,
        int $serverSalt = 0,
        int $seqNo = 0,
        int $serverTimeDelta = 0,
        bool $toServer = true,
        ?int $messageId = null
    ): string {
        $authKeyId = self::computeAuthKeyId($authKey);
        $messageId = $messageId ?? self::generateMessageId($serverTimeDelta);

        // 1. Build unencrypted message inner data
        $msgData = pack('P', $serverSalt);
        $msgData .= pack('P', $sessionId);
        $msgData .= pack('P', $messageId);
        $msgData .= pack('V', $seqNo);
        $msgData .= pack('V', strlen($payload));
        $msgData .= $payload;

        // 2. Add random padding (12 to 1024 bytes so total length is multiple of 16)
        $len = strlen($msgData);
        $paddingLen = 16 - ($len % 16);
        if ($paddingLen < 12) {
            $paddingLen += 16;
        }
        $msgData .= random_bytes($paddingLen);

        // 3. Compute msg_key = SHA256(auth_key[88+x..120+x] + msgData)[0..16]
        $x = $toServer ? 0 : 8;
        $msgKey = substr(hash('sha256', substr($authKey, 88 + $x, 32) . $msgData, true), 0, 16);

        // 4. Derive AES key and IV
        [$aesKey, $aesIv] = self::deriveKeys($authKey, $msgKey, x: $x);

        // 5. Encrypt with AES-256-IGE
        $encrypted = AesIge::encrypt($msgData, $aesKey, $aesIv);

        return $authKeyId . $msgKey . $encrypted;
    }

    /**
     * Decrypts an MTProto 2.0 message packet.
     *
     * @param string $packet Binary packet from socket
     * @param string $authKey 256-byte authentication key
     * @param bool $fromServer Whether packet was received from Telegram server (true) or from client (false)
     * @return array{server_salt: int, session_id: int, message_id: int, seq_no: int, payload: string}
     */
    public static function decryptPacket(string $packet, string $authKey, bool $fromServer = true): array
    {
        if (strlen($packet) < 40) {
            throw new RuntimeException("MTProto packet too short.");
        }

        $authKeyId = substr($packet, 0, 8);
        $msgKey = substr($packet, 8, 16);
        $encrypted = substr($packet, 24);

        if (!hash_equals(self::computeAuthKeyId($authKey), $authKeyId)) {
            throw new RuntimeException("AuthKey ID mismatch in MTProto packet.");
        }

        $x = $fromServer ? 8 : 0;

        // Derive AES key and IV
        [$aesKey, $aesIv] = self::deriveKeys($authKey, $msgKey, x: $x);

        // Decrypt with AES-256-IGE
        $decrypted = AesIge::decrypt($encrypted, $aesKey, $aesIv);

        // Verify msg_key = SHA256(auth_key[88+x..120+x] + decrypted)[0..16]
        $calculatedMsgKey = substr(hash('sha256', substr($authKey, 88 + $x, 32) . $decrypted, true), 0, 16);
        if (!hash_equals($calculatedMsgKey, $msgKey)) {
            throw new RuntimeException("MTProto packet integrity check failed: msg_key mismatch.");
        }

        $serverSalt = unpack('P', substr($decrypted, 0, 8))[1];
        $sessionId = unpack('P', substr($decrypted, 8, 8))[1];
        $messageId = unpack('P', substr($decrypted, 16, 8))[1];
        $seqNo = unpack('V', substr($decrypted, 24, 4))[1];
        $dataLen = unpack('V', substr($decrypted, 28, 4))[1];

        $payload = substr($decrypted, 32, $dataLen);

        return [
            'server_salt' => $serverSalt,
            'session_id' => $sessionId,
            'message_id' => $messageId,
            'seq_no' => $seqNo,
            'payload' => $payload,
        ];
    }

    /**
     * Derives AES-256 key and IV from AuthKey and MsgKey for MTProto 2.0.
     *
     * @return array{0: string, 1: string} [aesKey, aesIv]
     */
    protected static function deriveKeys(string $authKey, string $msgKey, int $x): array
    {
        $sha256a = hash('sha256', $msgKey . substr($authKey, $x, 36), true);
        $sha256b = hash('sha256', substr($authKey, 40 + $x, 36) . $msgKey, true);

        $aesKey = substr($sha256a, 0, 8) . substr($sha256b, 8, 16) . substr($sha256a, 24, 8);
        $aesIv = substr($sha256b, 0, 8) . substr($sha256a, 8, 16) . substr($sha256b, 24, 8);

        return [$aesKey, $aesIv];
    }
}
