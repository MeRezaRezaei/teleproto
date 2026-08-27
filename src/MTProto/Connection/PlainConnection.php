<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Connection;

use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;

/**
 * Unencrypted framed connection used only for the auth-key handshake.
 */
class PlainConnection
{
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

    public function request(string $payload): string
    {
        FrameCodec::sendMessage($this->socket, $payload);
        return FrameCodec::receiveMessage($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
