<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MeRezaRezaei\Teleproto\Events\TelegramUpdateReceived;

/**
 * Standard, low-overhead Telegram Webhook intake controller.
 * Validates secret tokens, dispatches TelegramUpdateReceived events, and responds with HTTP 200.
 */
class TelegramWebhookController
{
    /**
     * Handle the incoming Telegram Webhook update.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $configuredSecret = function_exists('config')
            ? (config('teleproto.webhook_secret') ?? config('teleproto.secret_token'))
            : getenv('TELEGRAM_WEBHOOK_SECRET');

        if (!empty($configuredSecret)) {
            $receivedHeader = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if (!$receivedHeader || !hash_equals((string)$configuredSecret, (string)$receivedHeader)) {
                return new JsonResponse(['error' => 'Invalid secret token'], 403);
            }
        }

        $payload = $request->json()->all();

        if (!empty($payload) && isset($payload['update_id'])) {
            $botToken = function_exists('config') ? config('teleproto.bot_token') : null;
            TelegramUpdateReceived::dispatch($payload, $botToken);
        }

        return new JsonResponse(['ok' => true]);
    }
}
