<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\MTProto;

use MeRezaRezaei\Teleproto\MTProto\Connection\EncryptedConnection;
use MeRezaRezaei\Teleproto\MTProto\Connection\PlainConnection;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AesIge;
use MeRezaRezaei\Teleproto\MTProto\Crypto\AuthKeyFactory;
use MeRezaRezaei\Teleproto\MTProto\Crypto\PasswordCalculator;
use MeRezaRezaei\Teleproto\MTProto\TL\TLSerializer;
use MeRezaRezaei\Teleproto\MTProto\Transport\FrameCodec;
use MeRezaRezaei\Teleproto\MTProto\Transport\StreamSocket;
use RuntimeException;

/**
 * Clean, stateless MTProto 2.0 Client.
 * Handles MTProto RPC calls, encryption, and proxy routing without local database or filesystem locks.
 */
class Client
{
    /**
     * Default Telegram production DC IP addresses.
     */
    public const DC_IPS = [
        1 => '149.154.175.53',
        2 => '149.154.167.51',
        3 => '149.154.175.100',
        4 => '149.154.167.91',
        5 => '91.108.56.130',
    ];

    public const DEFAULT_PORT = 443;

    protected ?SessionData $session = null;
    protected ?array $proxyConfig = null;
    protected bool $live = false;
    private ?EncryptedConnection $conn = null;

    public function __construct(
        public int $apiId,
        public string $apiHash,
        ?SessionData $session = null,
        bool $live = false
    ) {
        $this->session = $session;
        $this->live = $live;
    }

    public function setSession(SessionData $session): self
    {
        $this->session = $session;
        $this->close();
        return $this;
    }

    public function getSession(): ?SessionData
    {
        return $this->session;
    }

    /**
     * Configure proxy for MTProto socket connections.
     *
     * @param array{type?: string, host?: string, port?: int, username?: string, password?: string} $config
     */
    public function setProxy(array $config): self
    {
        $this->proxyConfig = $config;
        return $this;
    }

    /**
     * Connects to the active DC stream socket.
     *
     * @return resource
     */
    public function openSocket()
    {
        $dcId = $this->session->dcId ?? 2;
        $host = self::DC_IPS[$dcId] ?? self::DC_IPS[2];

        return StreamSocket::createConnection(
            host: $host,
            port: self::DEFAULT_PORT,
            proxy: $this->proxyConfig
        );
    }

    /**
     * Opt in to the live MTProto wire path (fluent; used by the doctor command).
     */
    public function live(): static
    {
        $this->live = true;
        return $this;
    }

    /**
     * Executes a raw MTProto RPC method.
     *
     * @param string $method MTProto method name (e.g. 'messages.sendMessage', 'users.getFullUser')
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        if (!$this->live) {
            if ($this->session === null || empty($this->session->authKey)) {
                throw new RuntimeException("Session AuthKey is required to make authenticated MTProto calls.");
            }

            // Mock result for unit test & execution pipeline
            return [
                '_' => 'rpc_result',
                'method' => $method,
                'params' => $params,
                'dc_id' => $this->session->dcId,
            ];
        }

        return $this->ensureConnection()->call($method, $params);
    }

    /**
     * Lazily handshakes (when the session has no 256-byte auth key) and opens
     * the encrypted connection exactly once; later live calls reuse it.
     */
    protected function ensureConnection(): EncryptedConnection
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        [$host, $port] = $this->resolveHost();
        return $this->conn = $this->connectEncrypted($this->ensureAuthKey($host, $port), $host, $port);
    }

    /**
     * Test-only seam: forces a fresh handshake (if needed) to the given host
     * and issues a single help.getNearestDc probe. Never touches cached state.
     *
     * @return array<string, mixed>
     */
    public function callToHost(string $host, int $port = 443): array
    {
        $conn = $this->connectEncrypted($this->ensureAuthKey($host, $port), $host, $port);
        try {
            return $conn->call('help.getNearestDc');
        } finally {
            $conn->close();
        }
    }

    /**
     * Runs the DH handshake via PlainConnection unless the session already
     * carries a full 256-byte auth key; returns the session to connect with
     * (and stores a freshly generated one on the client).
     */
    protected function ensureAuthKey(string $host, int $port): SessionData
    {
        if ($this->session === null) {
            throw new RuntimeException('MTProto live call: no session');
        }
        if (strlen($this->session->authKey) === 256) {
            return $this->session;
        }

        $plain = PlainConnection::connect($host, $port);
        try {
            return $this->session = AuthKeyFactory::generate($plain, $this->session->dcId);
        } finally {
            $plain->close();
        }
    }

    /**
     * Opens the encrypted connection, carrying this client's apiId into
     * initConnection (EncryptedConnection::connect() cannot take an apiId).
     */
    protected function connectEncrypted(SessionData $session, string $host, int $port): EncryptedConnection
    {
        try {
            $socket = StreamSocket::createConnection($host, $port);
            FrameCodec::writeInit($socket);
        } catch (RuntimeException $e) {
            throw new RuntimeException("MTProto connect to {$host}:{$port} failed: " . $e->getMessage(), 0, $e);
        }
        return new EncryptedConnection($session, $socket, $this->apiId);
    }

    /** @return array{0: string, 1: int} */
    protected function resolveHost(): array
    {
        $dcId = $this->session->dcId ?? 2;
        return [self::DC_IPS[$dcId] ?? self::DC_IPS[2], self::DEFAULT_PORT];
    }

    /**
     * Closes any open live connection and drops the cached handle.
     */
    public function close(): void
    {
        $this->conn?->close();
        $this->conn = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Helper to compute 2FA SRP parameters when Telegram returns SESSION_PASSWORD_NEEDED.
     *
     * @param array<string, mixed> $accountPassword
     * @param string $password
     * @return array{srp_id: int, A: string, M1: string}
     */
    public function compute2faProof(array $accountPassword, string $password): array
    {
        return PasswordCalculator::computeSrpProof($accountPassword, $password);
    }
}
