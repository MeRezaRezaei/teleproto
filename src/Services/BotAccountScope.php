<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\MTProto\Client as MTProtoClient;
use MeRezaRezaei\Teleproto\MTProto\SessionData;

/**
 * High-performance Bot Scope operating natively over Telegram MTProto 2.0 binary protocol.
 * Eliminates HTTP webhook/polling overhead with persistent TCP socket connections and binary TL encoding.
 *
 * @see \MeRezaRezaei\Teleproto\Services\UserAccountScope
 * @see \MeRezaRezaei\Teleproto\Types\InputPeer
 * @see \MeRezaRezaei\Teleproto\Types\InputMedia
 */
class BotAccountScope extends UserAccountScope
{
    public function __construct(
        MTProtoClient $mtproto,
        SessionData $session,
        public string $botToken
    ) {
        parent::__construct($mtproto, $session);
    }

    /**
     * Authenticates the bot on Telegram MTProto servers using `auth.importBotAuthorization`.
     *
     * @param int $flags
     * @return array<string, mixed> auth.Authorization
     */
    public function login(int $flags = 0): array
    {
        return $this->call('auth.importBotAuthorization', [
            'flags' => $flags,
            'api_id' => $this->mtproto->apiId,
            'api_hash' => $this->mtproto->apiHash,
            'bot_auth_token' => $this->botToken,
        ]);
    }
}
