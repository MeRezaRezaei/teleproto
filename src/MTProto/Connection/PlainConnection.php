<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\MTProto\Crypto\PacketCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use RuntimeException;

/**
 * Unencrypted framed connection used only for the auth-key handshake.
 * Every plain message is wrapped in the unencrypted MTProto envelope:
 * auth_key_id(8, zero) || message_id(8) || message_length(4, LE) || body.
 */
class PlainConnection
{
    private const MAX_MESSAGE = 2 * 1024 * 1024;

    /** @var resource */
    public $socket;

    /** @param resource $socket */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function connect(string $host, int $port = 443, float $timeout = 10.0): static
    {
        $socket = StreamSocket::createConnection($host, $port, timeout: $timeout);
        FrameCodec::writeInit($socket);
        return new static($socket);
    }

    /**
     * Builds the unencrypted-message envelope around $payload. Client message
     * ids follow the docs' plain-message convention (unixtime<<32 plus
     * sub-second counter, divisible by 4 — see PacketCodec::generateMessageId).
     */
    public static function buildPlainEnvelope(string $payload, int $msgId): string
    {
        if ($msgId <= 0) {
            throw new RuntimeException('PlainConnection: message_id must be positive');
        }
        return pack('P', 0) . pack('P', $msgId) . pack('V', strlen($payload)) . $payload;
    }

    /**
     * Validates a received unencrypted-message envelope and returns the body.
     *
     * @throws RuntimeException on non-zero auth_key_id, impossible length, or
     *         length not matching the actual frame size
     */
    public static function parsePlainEnvelope(string $frame): string
    {
        if (strlen($frame) < 20) {
            throw new RuntimeException('PlainConnection: envelope shorter than 20-byte header');
        }
        if (substr($frame, 0, 8) !== str_repeat("\x00", 8)) {
            throw new RuntimeException('PlainConnection: non-zero auth_key_id in plain response');
        }
        $msgId = unpack('P', substr($frame, 8, 8))[1];
        if ($msgId <= 0) {
            throw new RuntimeException('PlainConnection: invalid response message_id');
        }
        $length = unpack('V', substr($frame, 16, 4))[1];
        if ($length < 1 || $length > self::MAX_MESSAGE) {
            throw new RuntimeException(sprintf('PlainConnection: invalid message length %d', $length));
        }
        $body = substr($frame, 20);
        if (strlen($body) !== $length) {
            throw new RuntimeException(sprintf('PlainConnection: length %d does not match frame body %d', $length, strlen($body)));
        }
        return $body;
    }

    public function request(string $payload): string
    {
        FrameCodec::sendMessage(
            $this->socket,
            self::buildPlainEnvelope($payload, PacketCodec::generateMessageId())
        );
        return self::parsePlainEnvelope(FrameCodec::receiveMessage($this->socket));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
