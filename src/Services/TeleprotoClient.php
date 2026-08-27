<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use RuntimeException;

/**
 * High-level Laravel Telegram Client Manager.
 * Supports multi-tenant runtime API credentials, user sessions, and bot accounts.
 */
class TeleprotoClient
{
    public function __construct(
        public int $defaultApiId = 0,
        public string $defaultApiHash = '',
        public ?string $defaultBotToken = null,
        public ?array $defaultProxyConfig = null
    ) {}

    /**
     * Create or bind an MTProto user account session.
     *
     * @param int|null $accountId Telegram user ID (optional if session contains it)
     * @param string|SessionData|null $session SessionData object, exported base64 string, or raw AuthKey
     * @param int $dcId Primary DC ID (default: 2)
     * @param int|null $apiId Custom runtime API ID (falls back to default if null)
     * @param string|null $apiHash Custom runtime API Hash (falls back to default if null)
     * @param array|null $proxyConfig Custom runtime proxy config (falls back to default if null)
     */
    public function user(
        ?int $accountId = null,
        string|SessionData|null $session = null,
        int $dcId = 2,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        $finalApiId = $apiId ?? $this->defaultApiId;
        $finalApiHash = $apiHash ?? $this->defaultApiHash;
        $finalProxy = $proxyConfig ?? $this->defaultProxyConfig;

        if (empty($finalApiId) || empty($finalApiHash)) {
            throw new RuntimeException("Telegram API ID and API Hash are required. Pass them to user() or configure defaults in config/teleproto.php.");
        }

        if ($session instanceof SessionData) {
            $sessionData = $session;
        } elseif (is_string($session) && str_contains(base64_decode($session, true) ?: '', ':')) {
            $sessionData = SessionData::importString($session);
        } else {
            $sessionData = new SessionData(
                dcId: $dcId,
                authKey: is_string($session) ? $session : '',
                userId: $accountId
            );
        }

        $mtproto = new MTProtoClient(
            apiId: $finalApiId,
            apiHash: $finalApiHash,
            session: $sessionData
        );

        if ($finalProxy) {
            $mtproto->setProxy($finalProxy);
        }

        return new UserAccountScope($mtproto, $sessionData);
    }

    /**
     * Backward-compatible alias for user().
     */
    public function forAccount(
        ?int $accountId = null,
        string|SessionData|null $session = null,
        int $dcId = 2,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        return $this->user($accountId, $session, $dcId, $apiId, $apiHash, $proxyConfig);
    }

    /**
     * Create client directly from an exported session string.
     */
    public function fromSession(
        string $sessionString,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        return $this->user(
            accountId: null,
            session: $sessionString,
            apiId: $apiId,
            apiHash: $apiHash,
            proxyConfig: $proxyConfig
        );
    }

    /**
     * Create or bind a Bot API client.
     *
     * @param string|null $botToken Custom runtime Bot Token (falls back to default if null)
     * @param array|null $proxyConfig Custom runtime proxy
     */
    public function bot(?string $botToken = null, ?array $proxyConfig = null): BotClient
    {
        $finalToken = $botToken ?? $this->defaultBotToken;
        if (empty($finalToken)) {
            throw new RuntimeException("Telegram Bot Token is required. Pass it to bot() or configure TELEGRAM_BOT_TOKEN in .env.");
        }

        return new BotClient($finalToken, $proxyConfig ?? $this->defaultProxyConfig);
    }
}
