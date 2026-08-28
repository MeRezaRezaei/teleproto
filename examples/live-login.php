#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Interactive live MTProto login for Teleproto — powered by Laravel Prompts.
 *
 * Usage (from the repository root, after `composer install`):
 *   php examples/live-login.php        (or: ./bin/teleproto login)
 *
 * Flows: Bot token, User phone + code (+2FA SRP), User QR deep link.
 * Saves the exported session string to .env on success.
 */

require __DIR__ . '/../vendor/autoload.php';

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

use MeRezaRezaei\Teleproto\Exceptions\DcMigrationException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\AuthKeyException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\FloodWaitException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PasswordHashInvalidException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\PhoneCodeException;
use MeRezaRezaei\Teleproto\Exceptions\Rpc\SessionPasswordNeededException;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\Services\TeleprotoAuthService;

/** @param array<string, mixed> $authorization */
function celebrate(array $authorization, string $sessionString, string $label): void
{
    $user = $authorization['user'] ?? [];
    info(sprintf(
        '✅ %s logged in: %s %s (@%s, id %s)',
        $label,
        (string) ($user['first_name'] ?? ''),
        (string) ($user['last_name'] ?? ''),
        (string) ($user['username'] ?? '-'),
        (string) ($user['id'] ?? '?')
    ));
    $envKey = 'TELEGRAM_' . strtoupper(str_contains($label, 'Bot') ? 'BOT' : 'USER') . '_SESSION';
    echo "\nExported session string:\n{$sessionString}\n\n";
    if (confirm("Save session to .env as {$envKey}?", true)) {
        \MeRezaRezaei\Teleproto\Support\EnvFile::upsert(__DIR__ . '/../.env', $envKey, $sessionString);
        info("Saved to .env as {$envKey}.");
    }
}

function whoami(\MeRezaRezaei\Teleproto\Services\UserAccountScope $scope): array
{
    try {
        $res = $scope->call('users.getUsers', ['id' => [['_' => 'inputUserSelf']]]);
        return (array) ($res[0] ?? []);
    } catch (\Throwable) {
        return [];
    }
}

try {
    $envVars = \MeRezaRezaei\Teleproto\Support\EnvFile::read(__DIR__ . '/../.env');
} catch (Throwable $e) {
    warning('.env could not be parsed — continuing with empty defaults: ' . $e->getMessage());
    $envVars = [];
}
try {
    $apiId = (int) text(
        'Telegram API ID',
        placeholder: 'from https://my.telegram.org',
        default: (string) ((int) ($envVars['TELEGRAM_API_ID'] ?? 0) ?: ''),
        validate: fn (string $v) => ctype_digit($v) && (int) $v > 0 ? null : 'API ID must be a positive integer.'
    );
    $apiHash = text(
        'Telegram API Hash',
        placeholder: 'from https://my.telegram.org',
        default: (string) ($envVars['TELEGRAM_API_HASH'] ?? ''),
        validate: fn (string $v) => strlen($v) >= 30 ? null : 'API Hash looks too short.'
    );
} catch (\Laravel\Prompts\Exceptions\NonInteractiveValidationException $e) {
    // Non-interactive runs cannot retry a failed validation; render it styled
    // instead of letting Prompts escalate to an uncaught fatal (stack trace).
    error('VALIDATION FAILED — ' . $e->getMessage());
    exit(1);
}

$auth = new TeleprotoAuthService();

$choice = select(
    'Login method',
    [
        'bot' => '🤖 Bot (token from @BotFather)',
        'phone' => '📱 User (phone + verification code)',
        'qr' => '📷 User (QR deep link)',
    ],
    default: 'phone'
);

try {
    switch ($choice) {
        case 'bot':
            $token = text('Bot token', placeholder: '123456:ABC-DEF...', required: true);
            $res = $auth->loginBot($token, $apiId, $apiHash);
            celebrate(['user' => []], $res['session']->exportString(), 'Bot (MTProto)');
            break;

        case 'phone':
            $phone = text(
                'Phone number (international)',
                placeholder: '+989123456789',
                validate: fn (string $v) => preg_match('/^\+\d{8,15}$/', $v) ? null : 'Use full international format, e.g. +989123456789.'
            );
            $dcId = 2;
            $user = null;
            $code = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $code = $auth->sendPhoneCode($phone, $apiId, $apiHash, $dcId);
                    $user = $code['user'];
                    break;
                } catch (TelegramException $e) {
                    if (preg_match('/PHONE_MIGRATE_(\d+)/', $e->getMessage(), $m)) {
                        $dcId = (int) $m[1];
                        info("→ account lives on DC {$dcId}, reconnecting...");
                        continue;
                    }
                    throw $e;
                }
            }
            if ($user === null) {
                throw new RuntimeException('could not request login code');
            }

            $signIn = null;
            while (true) {
                $verify = text('Login code (Telegram app / SMS)', required: true);
                try {
                    $signIn = $auth->signInWithCode($user, $phone, $code['phone_code_hash'], $verify);
                    break;
                } catch (PhoneCodeException $e) {
                    if (str_contains($e->rpcErrorMessage, 'PHONE_CODE_INVALID')) {
                        warning('Code was invalid — check the digits and try again.');
                        continue;
                    }
                    throw $e;
                } catch (SessionPasswordNeededException) {
                    warning('2FA cloud password required.');
                    for ($try = 0; $try < 3; $try++) {
                        $secret = password('2FA password');
                        try {
                            $signIn = $auth->check2faPassword($user, $secret);
                            break 2;
                        } catch (PasswordHashInvalidException) {
                            if ($try < 2) {
                                warning('Wrong password, try again.');
                                continue;
                            }
                            throw new PasswordHashInvalidException();
                        }
                    }
                }
            }

            $me = whoami($user);
            celebrate($signIn !== null ? $signIn : ['user' => $me], $user->session->exportString(), 'User (phone)');
            break;

        case 'qr':
            $qr = $auth->exportQrLoginToken($apiId, $apiHash);
            $user = $qr['user'];
            echo \MeRezaRezaei\Teleproto\Support\TerminalQr::renderOrUrl($qr['url']);
            try {
                $authorization = $auth->pollQrLoginToken($user, $apiId, $apiHash, function (string $url): void {
                    static $last = '';
                    if ($url !== $last) {
                        $last = $url;
                        info("→ token refreshed: {$url}");
                    }
                });
            } catch (DcMigrationException $e) {
                info("→ migrating to DC {$e->dcId}");
                $authorization = $auth->importLoginTokenAt($e->dcId, (string) $e->token, $apiId, $apiHash);
            }
            celebrate($authorization, $user->session->exportString(), 'User (QR)');
            break;
    }
} catch (FloodWaitException $e) {
    error(sprintf('LOGIN BLOCKED — flood limit: retry in %d second(s).', $e->seconds));
    exit(1);
} catch (AuthKeyException $e) {
    error('LOGIN FAILED (auth key) — ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    error(sprintf('LOGIN FAILED — %s: %s', get_class($e), $e->getMessage()));
    exit(1);
}
