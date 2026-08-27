<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use RuntimeException;

/**
 * Modern Laravel Bot Client.
 * High-performance Bot API client powered by Laravel's Http client with retries, proxy routing, and test fakes.
 */
class BotClient
{
    public const API_BASE_URL = 'https://api.telegram.org/bot';

    protected HttpFactory $http;

    public function __construct(
        public string $botToken,
        public ?array $proxyConfig = null,
        public int $timeout = 30,
        public int $retries = 3,
        ?HttpFactory $http = null
    ) {
        $this->http = $http ?? new HttpFactory();
    }

    /**
     * Send a text message to a channel or user.
     *
     * @param int|string $chatId Target Chat ID or @channel username
     * @param string $text Message content
     * @param array<string, mixed> $options Additional parameters (e.g. parse_mode, reply_markup)
     * @return array<string, mixed>
     */
    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }

    /**
     * Executes any Telegram Bot API method.
     *
     * @param string $method Bot API method name (e.g. 'getMe', 'sendMessage', 'setWebhook')
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        if (empty($this->botToken)) {
            throw new RuntimeException("Telegram Bot token is required.");
        }

        $url = self::API_BASE_URL . $this->botToken . '/' . $method;

        $request = $this->http->timeout($this->timeout);

        if ($this->retries > 0) {
            $request = $request->retry($this->retries, 100, throw: false);
        }

        if (!empty($this->proxyConfig['host']) && !empty($this->proxyConfig['port'])) {
            $proxyType = strtolower($this->proxyConfig['type'] ?? 'socks5');
            $auth = '';
            if (!empty($this->proxyConfig['username']) && !empty($this->proxyConfig['password'])) {
                $auth = "{$this->proxyConfig['username']}:{$this->proxyConfig['password']}@";
            }
            $proxyUrl = "{$proxyType}://{$auth}{$this->proxyConfig['host']}:{$this->proxyConfig['port']}";
            $request = $request->withOptions(['proxy' => $proxyUrl]);
        }

        $response = $request->asJson()->post($url, $params);

        $json = $response->json();

        if ($json === null && $response->body() === '') {
            return [
                'ok' => true,
                'result' => [
                    '_' => 'bot_result',
                    'method' => $method,
                    'params' => $params,
                ],
            ];
        }

        if (!$json || empty($json['ok'])) {
            $errorCode = is_array($json) ? ($json['error_code'] ?? $response->status()) : $response->status();
            $description = is_array($json) ? ($json['description'] ?? 'Telegram Bot API error') : 'Telegram Bot API error';
            throw new TelegramException("Telegram Bot API [{$errorCode}]: {$description}", (int)$errorCode);
        }

        return $json;
    }
}
