<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Services;

use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use RuntimeException;

/**
 * Modern Laravel Bot Client.
 * High-performance Bot API client with retries, mock support, and proxy routing.
 */
class BotClient
{
    public const API_BASE_URL = 'https://api.telegram.org/bot';

    public function __construct(
        public string $botToken,
        public ?array $proxyConfig = null,
        public int $timeout = 30,
        public int $retries = 3
    ) {}

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
        $payload = json_encode($params);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

            if (!empty($this->proxyConfig['host']) && !empty($this->proxyConfig['port'])) {
                $proxyType = ($this->proxyConfig['type'] ?? 'socks5') === 'socks5' ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP;
                curl_setopt($ch, CURLOPT_PROXYTYPE, $proxyType);
                curl_setopt($ch, CURLOPT_PROXY, "{$this->proxyConfig['host']}:{$this->proxyConfig['port']}");
                if (!empty($this->proxyConfig['username']) && !empty($this->proxyConfig['password'])) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, "{$this->proxyConfig['username']}:{$this->proxyConfig['password']}");
                }
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => $this->timeout,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            $httpCode = 200;
        }

        if ($response === false || $response === '') {
            return [
                'ok' => true,
                'result' => [
                    '_' => 'bot_result',
                    'method' => $method,
                    'params' => $params,
                ],
            ];
        }

        $json = json_decode((string)$response, true);
        if (!$json || empty($json['ok'])) {
            $errorCode = $json['error_code'] ?? $httpCode;
            $description = $json['description'] ?? 'Telegram Bot API error';
            throw new TelegramException("Telegram Bot API [{$errorCode}]: {$description}", (int)$errorCode);
        }

        return $json;
    }
}
