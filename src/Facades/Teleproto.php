<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Facades;

use Illuminate\Support\Facades\Facade;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\BotClient;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;
use MeRezaRezaei\Teleproto\Services\UserAccountScope;

/**
 * Main Teleproto Facade for Laravel.
 *
 * @method static UserAccountScope user(?int $accountId = null, string|SessionData|null $session = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null) Connect as a User MTProto account.
 * @method static UserAccountScope fromSession(string $sessionString, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null) Initialize a User MTProto account directly from an exported base64 session string.
 * @method static UserAccountScope forAccount(?int $accountId = null, string|SessionData|null $session = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null) Alias for user().
 * @method static BotClient bot(?string $botToken = null, ?array $proxyConfig = null) Connect as a Telegram Bot (via Bot API or MTProto).
 *
 * @see \MeRezaRezaei\Teleproto\Services\TeleprotoClient
 * @see \MeRezaRezaei\Teleproto\Services\UserAccountScope
 * @see \MeRezaRezaei\Teleproto\Services\BotClient
 * @see \MeRezaRezaei\Teleproto\Types\InputPeer
 * @see \MeRezaRezaei\Teleproto\Types\InlineKeyboard
 * @see \MeRezaRezaei\Teleproto\Types\InputMedia
 * @see \MeRezaRezaei\Teleproto\Entities\EntityParser
 * @see \MeRezaRezaei\Teleproto\Media\StorageMedia
 * @see \MeRezaRezaei\Teleproto\Passport\PassportDecryptor
 */
class Teleproto extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TeleprotoClient::class;
    }
}
