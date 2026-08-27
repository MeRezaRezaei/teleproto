<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Telegram Mini App / Web App cryptographic HMAC-SHA256 signatures.
 * Adheres strictly to Telegram Bot API WebApp authentication guidelines.
 */
class VerifyMiniAppInitData
{
    /**
     * Handle an incoming request from Telegram Mini App.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->header('X-Telegram-Init-Data') ?? $request->input('initData');
        if (!$initData || !is_string($initData)) {
            return response()->json(['error' => 'Missing Telegram initData'], 401);
        }

        $botToken = config('teleproto.default_bot_token') ?? env('TELEGRAM_BOT_TOKEN');
        if (!$botToken) {
            return response()->json(['error' => 'Bot token not configured on server'], 500);
        }

        $validatedUser = $this->validateInitData($initData, $botToken);
        if (!$validatedUser) {
            return response()->json(['error' => 'Invalid Telegram HMAC signature'], 403);
        }

        $request->attributes->set('telegram_user', $validatedUser);

        return $next($request);
    }

    /**
     * Cryptographically validates Telegram Mini App initData without key mangling.
     */
    public function validateInitData(string $initData, string $botToken): ?array
    {
        $pairs = explode('&', $initData);
        $params = [];
        $hash = null;

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';

            if ($key === 'hash') {
                $hash = $value;
            } else {
                $params[$key] = $value;
            }
        }

        if ($hash === null || empty($params)) {
            return null;
        }

        // Sort parameters alphabetically
        ksort($params);

        $dataCheckArr = [];
        foreach ($params as $k => $v) {
            $dataCheckArr[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        // secret_key = HMAC_SHA256("WebAppData", bot_token)
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, $hash)) {
            return null;
        }

        if (isset($params['user']) && is_string($params['user'])) {
            return json_decode($params['user'], true) ?: null;
        }

        return $params;
    }
}
