#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Comprehensive End-to-End Verification Suite for Teleproto.
 * Verifies live User MTProto calls, Bot API calls, and official exception guidance.
 *
 * Usage: ./bin/teleproto test-e2e
 */

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\Exceptions\Rpc\PhoneNumberException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\RpcErrorException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\Services\TeleprotoClient;

$env = file_exists(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$session = $env['TELEGRAM_USER_SESSION'] ?? getenv('TELEGRAM_USER_SESSION');
$apiId = (int)($env['TELEGRAM_API_ID'] ?? getenv('TG_API_ID') ?: 0);
$apiHash = (string)($env['TELEGRAM_API_HASH'] ?? getenv('TG_API_HASH') ?: '');
$botToken = $env['TELEGRAM_BOT_TOKEN'] ?? getenv('TELEGRAM_BOT_TOKEN');

echo "=======================================================\n";
echo " Teleproto Low-Level End-to-End Live Verification\n";
echo "=======================================================\n\n";

$passed = 0;
$total = 0;

$runner = function (string $title, callable $test) use (&$passed, &$total): void {
    $total++;
    echo "[TEST {$total}] {$title} ... ";
    try {
        $result = $test();
        echo "\033[32mPASSED\033[0m\n";
        if (is_string($result) && $result !== '') {
            echo "  └─ \033[36m{$result}\033[0m\n";
        }
        $passed++;
    } catch (Throwable $e) {
        echo "\033[31mFAILED\033[0m\n";
        echo "  └─ \033[31mError: " . $e->getMessage() . "\033[0m\n";
    }
};

// --------------------------------------------------------------------
// 1. User MTProto Live Connection & Profile
// --------------------------------------------------------------------
$runner('User MTProto: Connect to DC with saved session', function () use ($session, $apiId, $apiHash) {
    if (!$session) {
        throw new RuntimeException('TELEGRAM_USER_SESSION missing in .env');
    }
    $client = new TeleprotoClient($apiId, $apiHash);
    $user = $client->fromSession($session);
    $user->mtproto->live();

    $nearest = $user->call('help.getNearestDc');
    if (!isset($nearest['this_dc'])) {
        throw new RuntimeException('getNearestDc failed');
    }
    return "Connected to DC " . $nearest['this_dc'] . " (Country: " . $nearest['country'] . ")";
});

$runner('User MTProto: Fetch self user profile (users.getUsers)', function () use ($session, $apiId, $apiHash) {
    $client = new TeleprotoClient($apiId, $apiHash);
    $user = $client->fromSession($session);
    $user->mtproto->live();

    $res = $user->call('users.getUsers', [
        'id' => [['_' => 'inputUserSelf']]
    ]);
    $u = $res[0] ?? [];
    if (empty($u['id'])) {
        throw new RuntimeException('User profile empty');
    }
    return "User: " . ($u['first_name'] ?? '') . " " . ($u['last_name'] ?? '') . " (@" . ($u['username'] ?? '-') . ", ID: " . $u['id'] . ")";
});

// --------------------------------------------------------------------
// 2. Exception Guidance & Error Catalog Verification
// --------------------------------------------------------------------
$runner('Live Exception: PHONE_NUMBER_INVALID guidance on bad phone format', function () use ($apiId, $apiHash) {
    $client = new TeleprotoClient($apiId, $apiHash);
    $user = $client->user();
    $user->mtproto->live();

    try {
        $user->call('auth.sendCode', [
            'phone_number' => '+0000000000',
            'api_id' => $apiId,
            'api_hash' => $apiHash,
            'settings' => ['_' => 'codeSettings']
        ]);
        throw new RuntimeException('Expected PHONE_NUMBER_INVALID but call succeeded');
    } catch (PhoneNumberException $e) {
        if (!str_contains($e->getMessage(), 'PHONE_NUMBER_INVALID')) {
            throw new RuntimeException('Wrong exception message: ' . $e->getMessage());
        }
        if (!str_contains($e->getMessage(), 'during auth.sendCode')) {
            throw new RuntimeException('Missing method context in message: ' . $e->getMessage());
        }
        return "Caught typed PhoneNumberException with guidance:\n     \"" . $e->getMessage() . "\"";
    }
});

$runner('Live Exception: USER_ID_INVALID guidance on invalid inputUser', function () use ($session, $apiId, $apiHash) {
    $client = new TeleprotoClient($apiId, $apiHash);
    $user = $client->fromSession($session);
    $user->mtproto->live();

    try {
        $user->call('users.getFullUser', [
            'id' => ['_' => 'inputUser', 'user_id' => 999999999999, 'access_hash' => 0]
        ]);
        throw new RuntimeException('Expected USER_ID_INVALID but call succeeded');
    } catch (RpcErrorException $e) {
        if (!str_contains($e->rpcErrorMessage, 'USER_ID_INVALID') && !str_contains($e->getMessage(), 'USER_ID_INVALID')) {
            throw new RuntimeException('Wrong error code: ' . $e->getMessage());
        }
        return "Caught RpcErrorException with doc guidance:\n     \"" . $e->getMessage() . "\"";
    }
});

// --------------------------------------------------------------------
// 3. Bot Client (HTTP Bot API & MTProto Bot Scope)
// --------------------------------------------------------------------
if ($botToken) {
    $runner('Bot Client: HTTP Bot API getMe', function () use ($botToken) {
        $bot = new \MeRezaRezaei\Teleproto\Services\BotClient($botToken);
        $me = $bot->getMe();
        if (empty($me['ok'])) {
            throw new RuntimeException('getMe failed');
        }
        return "Bot: @" . ($me['result']['username'] ?? '-') . " (ID: " . ($me['result']['id'] ?? '') . ")";
    });

    $runner('Bot Client: Native MTProto binary bot login (auth.importBotAuthorization)', function () use ($botToken, $apiId, $apiHash) {
        $client = new TeleprotoClient($apiId, $apiHash, $botToken);
        $bot = $client->botMtproto();
        $res = $bot->login();
        $u = $res['user'] ?? [];
        return "MTProto Bot: @" . ($u['username'] ?? '-') . " (ID: " . ($u['id'] ?? '') . ")";
    });
} else {
    $runner('Bot Client: Invalid Token HTTP Error Guidance (Simulated)', function () {
        $bot = new \MeRezaRezaei\Teleproto\Services\BotClient('123456:INVALID_TOKEN');
        try {
            $bot->getMe();
            throw new RuntimeException('Expected 401 error but call succeeded');
        } catch (TelegramException $e) {
            return "Caught expected HTTP Bot API Exception (Code " . $e->getCode() . "): " . $e->getMessage();
        }
    });
}

// --------------------------------------------------------------------
// 4. Summary
// --------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Test Results: {$passed}/{$total} Passed\n";
echo "=======================================================\n";

exit($passed === $total ? 0 : 1);
