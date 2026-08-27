<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto\Transport;

use RuntimeException;

/**
 * Standard PHP Stream Socket Transport for Telegram MTProto.
 * Connects directly or tunnels through SOCKS5 / HTTP proxies using standard stream transports.
 */
class StreamSocket
{
    /**
     * Connects to a Telegram DC socket directly or via SOCKS5/HTTP proxy.
     *
     * @param string $host Target Telegram DC IP
     * @param int $port Target Telegram DC Port (default 443)
     * @param array{type?: string, host?: string, port?: int, username?: string, password?: string}|null $proxy
     * @param float $timeout Timeout in seconds
     * @return resource Writable stream socket
     * @todo proxy tunneling is not implemented yet; connection is direct
     */
    public static function createConnection(
        string $host,
        int $port = 443,
        ?array $proxy = null,
        float $timeout = 10.0
    ) {
        $options = [
            'socket' => [
                'tcp_nodelay' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];

        $context = stream_context_create($options);
        $remoteSocket = "tcp://{$host}:{$port}";

        $socket = @stream_socket_client(
            $remoteSocket,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new RuntimeException("Failed to connect to Telegram DC {$remoteSocket}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int)$timeout);

        return $socket;
    }

    public static function write($socket, string $bytes): void
    {
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('StreamSocket: write failed at offset ' . $written);
            }
            $written += $n;
        }
    }

    public static function read($socket, int $length): string
    {
        $chunk = @fread($socket, $length);
        if ($chunk === false || $chunk === '') {
            throw new \RuntimeException('StreamSocket: EOF while reading');
        }
        return $chunk;
    }

    public static function readExact($socket, int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $buffer .= self::read($socket, $length - strlen($buffer));
        }
        return $buffer;
    }
}
