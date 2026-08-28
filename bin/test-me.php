#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Test your saved session from .env — performs a live getFullUser call.
 * Usage: ./bin/teleproto me
 */

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\Facades\TP;

$env = file_exists(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$session = $env['TELEGRAM_USER_SESSION'] ?? getenv('TELEGRAM_USER_SESSION');
$apiId = (int)($env['TELEGRAM_API_ID'] ?? getenv('TG_API_ID') ?: 0);
$apiHash = (string)($env['TELEGRAM_API_HASH'] ?? getenv('TG_API_HASH') ?: '');

if (!$session) {
    fwrite(STDERR, "TELEGRAM_USER_SESSION not found in .env.\nRun `./bin/teleproto login` first.\n");
    exit(1);
}

try {
    echo "Connecting to Telegram DC 4 with saved session...\n";
    $client = new \MeRezaRezaei\Teleproto\Services\TeleprotoClient($apiId, $apiHash);
    $user = $client->fromSession($session);
    $user->mtproto->live();

    $nearest = $user->call('help.getNearestDc');
    echo "Live connection to Telegram verified! Nearest DC info:\n";
    print_r($nearest);

    $users = $user->call('users.getUsers', [
        'id' => [['_' => 'inputUserSelf']]
    ]);
    echo "\nYour Telegram User Profile (Live via native MTProto Layer 227):\n";
    print_r($users);
} catch (Throwable $e) {
    fwrite(STDERR, "Session test failed: " . $e->getMessage() . "\n");
    exit(1);
}
