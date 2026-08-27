<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Facades;

use Illuminate\Support\Facades\Facade;
use MeRezaRezaei\Teleproto\Services\BotClient;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;
use MeRezaRezaei\Teleproto\Services\UserAccountScope;

/**
 * @method static UserAccountScope user(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static UserAccountScope forAccount(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static BotClient bot(?string $botToken = null, ?array $proxyConfig = null)
 *
 * @see \MeRezaRezaei\Teleproto\Services\TeleprotoClient
 */
class Telegram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeleprotoClient::class;
    }
}
