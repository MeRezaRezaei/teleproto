#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Interactive live MTProto login for Teleproto — no Laravel/artisan needed.
 *
 * Usage (from the repository root, after `composer install`):
 *   TG_API_ID=1821270 TG_API_HASH=... php examples/live-login.php
 *
 * Flows: Bot token (auth.importBotAuthorization), User phone + code (+2FA
 * SRP), User QR (tg://login deep link). Prints the exported session string
 * on success — store it as TELEGRAM_BOT_SESSION / TELEGRAM_USER_SESSION.
 */

require __DIR__ . '/../vendor/autoload.php';

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\AuthKeyException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PasswordHashInvalidException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PhoneCodeException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\SessionPasswordNeededException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\Services\TeleprotoAuthService;

$apiId = (int) (getenv('TG_API_ID') ?: 0);
$apiHash = (string) (getenv('TG_API_HASH') ?: '');
if ($apiId === 0 || $apiHash === '') {
    fwrite(STDERR, "Set TG_API_ID and TG_API_HASH env vars (from https://my.telegram.org).\n");
    exit(1);
}

function ask(string $prompt): string
{
    echo $prompt;
    return trim((string) fgets(STDIN));
}

function askSecret(string $prompt): string
{
    echo $prompt;
    if (stripos(PHP_OS, 'win') === false) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if (stripos(PHP_OS, 'win') === false) {
        shell_exec('stty echo');
    }
    echo "\n";
    return $value;
}

/** @param array<string, mixed> $authorization */
function celebrate(array $authorization, string $sessionString, string $label): void
{
    $user = $authorization['user'] ?? [];
    printf(
        "\n✅ %s logged in: %s %s (@%s, id %s)\n",
        $label,
        (string)($user['first_name'] ?? ''),
        (string)($user['last_name'] ?? ''),
        (string)($user['username'] ?? '-'),
        (string)($user['id'] ?? '?')
    );
    echo "\nSession string (store encrypted; e.g. TELEGRAM_" . strtoupper(str_contains($label, 'Bot') ? 'BOT' : 'USER') . "_SESSION):\n";
    echo $sessionString . "\n\n";
}

function whoami(\MeRezaRezaei\Teleproto\Services\UserAccountScope $scope): array
{
    $res = $scope->call('users.getUsers', ['id' => [['_' => 'inputUserSelf']]]);
    return (array)($res[0] ?? []);
}

$auth = new TeleprotoAuthService();

echo "Teleproto live login — choose method:\n  1) Bot (token)\n  2) User (phone + code)\n  3) User (QR link)\n> ";
$choice = trim((string) fgets(STDIN));

try {
    switch ($choice) {
        case '1':
            $token = ask('Bot token (from @BotFather): ');
            $res = $auth->loginBot($token, $apiId, $apiHash);
            celebrate(['user' => []], $res['session']->exportString(), 'Bot (MTProto)');
            break;

        case '2':
            $phone = ask('Phone number (international, e.g. +989123456789): ');
            $dcId = 2;
            $user = null;
            // DC migration loop at the sendCode stage (PHONE_MIGRATE_X)
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $code = $auth->sendPhoneCode($phone, $apiId, $apiHash, $dcId);
                    $user = $code['user'];
                    break;
                } catch (TelegramException $e) {
                    if (preg_match('/PHONE_MIGRATE_(\d+)/', $e->getMessage(), $m)) {
                        $dcId = (int)$m[1];
                        echo "→ account lives on DC {$dcId}, reconnecting...\n";
                        continue;
                    }
                    throw $e;
                }
            }
            if ($user === null) {
                throw new RuntimeException('could not request login code');
            }

            $verify = ask('Login code (Telegram app / SMS): ');
            try {
                $signIn = $auth->signInWithCode($user, $phone, $code['phone_code_hash'], $verify);
            } catch (SessionPasswordNeededException) {
                echo "→ 2FA cloud password required\n";
                for ($try = 0; $try < 3; $try++) {
                    $password = askSecret('2FA password: ');
                    try {
                        $signIn = $auth->check2faPassword($user, $password);
                        break;
                    } catch (PasswordHashInvalidException) {
                        if ($try < 2) {
                            echo "→ wrong password, try again\n";
                            continue;
                        }
                        throw new PasswordHashInvalidException();
                    }
                }
            } catch (PhoneCodeException $e) {
                fwrite(STDERR, 'LOGIN FAILED — ' . $e->getMessage() . "\n");
                exit(1);
            }

            $me = whoami($user);
            celebrate(['user' => $me], $user->session->exportString(), 'User (phone)');
            break;

        case '3':
            $qr = $auth->exportQrLoginToken($apiId, $apiHash);
            $user = $qr['user'];
            echo "\n📱 Open this link from ANY device already signed into Telegram\n";
            echo "   (Telegram Desktop: just click it; phone: scan it as a QR if you render one):\n\n";
            echo "   " . $qr['url'] . "\n\n";
            try {
                $authorization = $auth->pollQrLoginToken($user, $apiId, $apiHash, function (string $url): void {
                    static $last = '';
                    if ($url !== $last) {
                        $last = $url;
                        echo "→ token refreshed: {$url}\n";
                    }
                });
            } catch (DcMigrationException $e) {
                echo "→ migrating to DC {$e->dcId}\n";
                $authorization = $auth->importLoginTokenAt($e->dcId, (string)$e->token, $apiId, $apiHash);
            }
            celebrate($authorization, $user->session->exportString(), 'User (QR)');
            break;

        default:
            fwrite(STDERR, "unknown choice\n");
            exit(1);
    }
} catch (FloodWaitException $e) {
    fwrite(STDERR, sprintf("LOGIN BLOCKED — flood limit: retry in %d second(s).\n", $e->seconds));
    exit(1);
} catch (AuthKeyException $e) {
    fwrite(STDERR, "LOGIN FAILED (auth key) — " . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("LOGIN FAILED — %s: %s\n", get_class($e), $e->getMessage()));
    exit(1);
}
