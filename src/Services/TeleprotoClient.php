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
        public ?array $defaultProxyConfig = null,
        public ?string $defaultUserSession = null,
        public ?string $defaultBotSession = null,
        public int $defaultDcId = 2
    ) {}

    /**
     * Create or bind an MTProto user account session.
     * If no session is provided, falls back to the configured default user session from .env.
     *
     * @param int|null $accountId Telegram user ID (optional if session contains it)
     * @param string|SessionData|null $session SessionData object, exported base64 string, or raw AuthKey
     * @param int|null $dcId Primary DC ID (default: configured defaultDcId or 2)
     * @param int|null $apiId Custom runtime API ID (falls back to default if null)
     * @param string|null $apiHash Custom runtime API Hash (falls back to default if null)
     * @param array|null $proxyConfig Custom runtime proxy config (falls back to default if null)
     */
    public function user(
        ?int $accountId = null,
        string|SessionData|null $session = null,
        ?int $dcId = null,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        $finalApiId = $apiId ?? $this->defaultApiId;
        $finalApiHash = $apiHash ?? $this->defaultApiHash;
        $finalProxy = $proxyConfig ?? $this->defaultProxyConfig;
        $finalDcId = $dcId ?? $this->defaultDcId;
        $targetSession = $session ?? $this->defaultUserSession;

        if (empty($finalApiId) || empty($finalApiHash)) {
            throw new RuntimeException("Telegram API ID and API Hash are required. Pass them to user() or configure defaults in config/teleproto.php.");
        }

        if ($targetSession instanceof SessionData) {
            $sessionData = $targetSession;
        } elseif (is_string($targetSession) && str_contains(base64_decode($targetSession, true) ?: '', ':')) {
            $sessionData = SessionData::importString($targetSession);
        } else {
            $sessionData = new SessionData(
                dcId: $finalDcId,
                authKey: is_string($targetSession) ? $targetSession : '',
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
        ?int $dcId = null,
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
     * Create or bind a Bot API client (over HTTP Bot API).
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

    /**
     * Create or bind a Bot account operating directly over high-speed MTProto 2.0 (Binary TCP RPC).
     * Uses MTProto `auth.importBotAuthorization` for bot login without Bot API HTTP latency.
     *
     * @param string|null $botToken Custom runtime Bot Token
     * @param string|SessionData|null $session
     * @param int|null $dcId Primary DC ID (default: 2)
     * @param int|null $apiId
     * @param string|null $apiHash
     * @param array|null $proxyConfig
     */
    public function botMtproto(
        ?string $botToken = null,
        string|SessionData|null $session = null,
        ?int $dcId = null,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): BotAccountScope {
        $finalToken = $botToken ?? $this->defaultBotToken;
        if (empty($finalToken)) {
            throw new RuntimeException("Telegram Bot Token is required for MTProto bot authorization.");
        }

        $finalApiId = $apiId ?? $this->defaultApiId;
        $finalApiHash = $apiHash ?? $this->defaultApiHash;
        $finalProxy = $proxyConfig ?? $this->defaultProxyConfig;
        $finalDcId = $dcId ?? $this->defaultDcId;
        $targetSession = $session ?? $this->defaultBotSession;

        if (empty($finalApiId) || empty($finalApiHash)) {
            throw new RuntimeException("Telegram API ID and API Hash are required for MTProto connections.");
        }

        if ($targetSession instanceof SessionData) {
            $sessionData = $targetSession;
        } elseif (is_string($targetSession) && str_contains(base64_decode($targetSession, true) ?: '', ':')) {
            $sessionData = SessionData::importString($targetSession);
        } else {
            $sessionData = new SessionData(
                dcId: $finalDcId,
                authKey: is_string($targetSession) ? $targetSession : ''
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

        return new BotAccountScope($mtproto, $sessionData, $finalToken);
    }
}
