<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto;

use InvalidArgumentException;

/**
 * Stateless Session Data Transfer Object.
 * Holds cryptographic keys and connection context for a single Telegram MTProto session.
 */
class SessionData
{
    public function __construct(
        public int $dcId,
        public string $authKey,
        public int $serverTimeDelta = 0,
        public int $seqNo = 0,
        public ?int $userId = null
    ) {}

    /**
     * Export session into a safe, portable base64 string for database/Redis storage.
     * Format: "dcId:base64(authKey):userId:serverTimeDelta"
     */
    public function exportString(): string
    {
        $payload = implode(':', [
            $this->dcId,
            base64_encode($this->authKey),
            $this->userId ?? 0,
            $this->serverTimeDelta,
        ]);

        return base64_encode($payload);
    }

    /**
     * Reconstruct SessionData from an exported session string.
     */
    public static function importString(string $sessionString): self
    {
        $decoded = base64_decode($sessionString, true);
        if ($decoded === false) {
            throw new InvalidArgumentException("Invalid base64 session string.");
        }

        $parts = explode(':', $decoded);
        if (count($parts) < 4) {
            throw new InvalidArgumentException("Malformed session string format.");
        }

        return new self(
            dcId: (int)$parts[0],
            authKey: base64_decode($parts[1]),
            serverTimeDelta: (int)$parts[3],
            userId: (int)$parts[2] !== 0 ? (int)$parts[2] : null
        );
    }

    public function toArray(): array
    {
        return [
            'dc_id' => $this->dcId,
            'auth_key' => base64_encode($this->authKey),
            'server_time_delta' => $this->serverTimeDelta,
            'seq_no' => $this->seqNo,
            'user_id' => $this->userId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            dcId: (int)($data['dc_id'] ?? 2),
            authKey: isset($data['auth_key']) ? base64_decode($data['auth_key']) : '',
            serverTimeDelta: (int)($data['server_time_delta'] ?? 0),
            seqNo: (int)($data['seq_no'] ?? 0),
            userId: isset($data['user_id']) ? (int)$data['user_id'] : null
        );
    }
}
