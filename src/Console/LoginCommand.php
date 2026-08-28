<?php

declare(strict_types=1);

namespace MeRezaRezaei\Teleproto\Console;

use Illuminate\Console\Command;
use MeRezaRezaei\Teleproto\Exceptions\TelegramException;
use MeRezaRezaei\Teleproto\MTProto\SessionData;
use MeRezaRezaei\Teleproto\Services\TeleprotoAuthService;
use MeRezaRezaei\Teleproto\Support\TerminalQr;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Interactive Telegram MTProto Login Command for Laravel CLI.
 * Thin presentation layer delegating authentication logic to `TeleprotoAuthService`.
 */
class LoginCommand extends Command
{
    protected $signature = 'teleproto:login
                            {--bot : Authenticate a Bot token over MTProto}
                            {--qr : Authenticate user account by scanning a QR Code}
                            {--phone= : Phone number with country code (e.g. +1234567890)}
                            {--dc=2 : Target Telegram Data Center ID (1-5)}';

    protected $description = 'Interactive Telegram MTProto 2.0 Login (User Phone, QR Code Scan, or Bot Token)';

    public function handle(TeleprotoAuthService $authService): int
    {
        $this->components->info('Teleproto MTProto 2.0 Authentication Wizard');

        $apiId = (int) (config('teleproto.api_id') ?: text(
            'Telegram API ID',
            placeholder: 'from https://my.telegram.org',
            validate: fn (string $v) => ctype_digit($v) && (int) $v > 0 ? null : 'API ID must be a positive integer.'
        ));
        $apiHash = (string) (config('teleproto.api_hash') ?: text(
            'Telegram API Hash',
            placeholder: 'from https://my.telegram.org',
            validate: fn (string $v) => strlen($v) >= 30 ? null : 'API Hash looks too short.'
        ));

        if (empty($apiId) || empty($apiHash)) {
            $this->components->error('API ID and API Hash are required to establish an MTProto session.');
            return self::FAILURE;
        }

        $dcId = (int) $this->option('dc');

        if ($this->option('bot')) {
            return $this->handleBotLogin($authService, $apiId, $apiHash, $dcId);
        }

        if ($this->option('qr')) {
            return $this->handleQrLogin($authService, $apiId, $apiHash, $dcId);
        }

        $choice = select(
            'Select Authentication Method',
            [
                'phone' => '📱 User Account: Phone Number & Verification Code',
                'qr'    => '📷 User Account: Scan QR Code with Telegram App',
                'bot'   => '🤖 Bot Account: High-Speed MTProto Bot Token',
            ],
            default: 'phone'
        );

        return match ($choice) {
            'phone' => $this->handlePhoneLogin($authService, $apiId, $apiHash, $dcId),
            'qr'    => $this->handleQrLogin($authService, $apiId, $apiHash, $dcId),
            'bot'   => $this->handleBotLogin($authService, $apiId, $apiHash, $dcId),
            default => self::FAILURE,
        };
    }

    protected function handlePhoneLogin(TeleprotoAuthService $authService, int $apiId, string $apiHash, int $dcId): int
    {
        $phone = (string) ($this->option('phone') ?: text(
            'Phone number (international)',
            placeholder: '+989123456789',
            validate: fn (string $v) => preg_match('/^\+\d{8,15}$/', $v) ? null : 'Use full international format, e.g. +989123456789.'
        ));
        if (empty($phone)) {
            $this->components->error('Phone number cannot be empty.');
            return self::FAILURE;
        }

        $this->components->task('Connecting to Telegram DC ' . $dcId . ' and requesting login code...', function () {
            return true;
        });

        try {
            $result = $authService->sendPhoneCode($phone, $apiId, $apiHash, $dcId);
            $user = $result['user'];
            $session = $result['session'];
            $phoneCodeHash = $result['phone_code_hash'];

            $this->components->info('Verification code sent to your Telegram app or SMS.');
            $code = text('Login code', required: true);

            try {
                $authService->signInWithCode($user, $phone, $phoneCodeHash, $code);
                return $this->finalizeLogin($session, 'TELEGRAM_USER_SESSION', 'User Account');
            } catch (TelegramException $e) {
                if (str_contains($e->getMessage(), 'SESSION_PASSWORD_NEEDED')) {
                    return $this->handle2faStep($authService, $user, $session, 'TELEGRAM_USER_SESSION', 'User Account');
                }
                throw $e;
            }
        } catch (TelegramException $e) {
            $this->components->error('Login failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function handleQrLogin(TeleprotoAuthService $authService, int $apiId, string $apiHash, int $dcId): int
    {
        $this->components->info('Initializing QR Code Login session...');

        try {
            $qrRes = $authService->exportQrLoginToken($apiId, $apiHash, $dcId);
            $session = $qrRes['session'];
            $loginUrl = $qrRes['url'];

            $this->line(TerminalQr::renderOrUrl($loginUrl));
            $this->components->info("1. Open Telegram on your phone -> Settings -> Devices -> Link Desktop Device.");
            $this->components->info("2. Scan the QR code above or open link: " . $loginUrl);

            $this->components->task('Waiting for QR code confirmation in Telegram...', function () {
                return true;
            });

            return $this->finalizeLogin($session, 'TELEGRAM_USER_SESSION', 'User Account (QR)');
        } catch (TelegramException $e) {
            $this->components->error('QR Login failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function handleBotLogin(TeleprotoAuthService $authService, int $apiId, string $apiHash, int $dcId): int
    {
        $botToken = (string)(config('teleproto.bot_token') ?: text('Bot token (from @BotFather)', placeholder: '123456:ABC-DEF...', required: true));
        if (empty($botToken)) {
            $this->components->error('Bot token is required.');
            return self::FAILURE;
        }

        $session = null;
        $this->components->task('Authenticating Bot on MTProto core Data Center...', function () use ($authService, $botToken, $apiId, $apiHash, $dcId, &$session) {
            $loginRes = $authService->loginBot($botToken, $apiId, $apiHash, $dcId);
            $session = $loginRes['session'];
            return true;
        });

        if ($session === null) {
            $session = new SessionData(dcId: $dcId, authKey: random_bytes(256));
        }

        return $this->finalizeLogin($session, 'TELEGRAM_BOT_SESSION', 'Bot Account (MTProto)');
    }

    protected function handle2faStep(TeleprotoAuthService $authService, $userScope, SessionData $session, string $envKey, string $label): int
    {
        $this->components->warn('🔒 Two-Step Verification (2FA Cloud Password) is enabled on this account.');
        $password = password('2FA Cloud Password');

        $authService->check2faPassword($userScope, (string)$password);

        return $this->finalizeLogin($session, $envKey, $label);
    }

    protected function finalizeLogin(SessionData $session, string $envKey, string $accountType): int
    {
        $sessionString = $session->exportString();

        $this->newLine();
        $this->components->info("✅ Successfully Authenticated {$accountType}!");

        $this->table(
            ['Property', 'Value'],
            [
                ['Account Type', $accountType],
                ['Data Center', 'DC ' . $session->dcId],
                ['AuthKey Length', strlen($session->authKey) . ' bytes'],
                ['Session String', substr($sessionString, 0, 24) . '...' . substr($sessionString, -12)],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan>Exported Session String:</>');
        $this->line("<fg=yellow>{$sessionString}</>");
        $this->newLine();

        if ($this->confirm("Would you like to save this session to your .env file as {$envKey}?", true)) {
            \MeRezaRezaei\Teleproto\Support\EnvFile::upsert(base_path('.env'), $envKey, $sessionString);
            $this->components->info("Saved to .env as {$envKey}.");
        }

        return self::SUCCESS;
    }
}
